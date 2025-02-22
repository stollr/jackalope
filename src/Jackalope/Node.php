<?php

namespace Jackalope;

use Jackalope\NodeType\NodeType;
use PHPCR\AccessDeniedException;
use PHPCR\InvalidItemStateException;
use PHPCR\ItemExistsException;
use PHPCR\ItemInterface;
use PHPCR\ItemNotFoundException;
use PHPCR\Lock\LockException;
use PHPCR\NamespaceException;
use PHPCR\NodeInterface;
use PHPCR\NodeType\ConstraintViolationException;
use PHPCR\NodeType\NodeDefinitionInterface;
use PHPCR\NodeType\NodeTypeInterface;
use PHPCR\NodeType\NoSuchNodeTypeException;
use PHPCR\PathNotFoundException;
use PHPCR\PropertyInterface;
use PHPCR\PropertyType;
use PHPCR\RepositoryException;
use PHPCR\UnsupportedRepositoryOperationException;
use PHPCR\Util\NodeHelper;
use PHPCR\Util\PathHelper;
use PHPCR\Util\UUIDHelper;
use PHPCR\ValueFormatException;
use PHPCR\Version\VersionException;

/**
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class Node extends Item implements \IteratorAggregate, NodeInterface
{
    /**
     * The index if this is a same-name sibling.
     *
     * TODO: fully implement same-name siblings
     */
    private int $index = 1;

    /**
     * The primary type name of this node.
     */
    private ?string $primaryType = null;

    /**
     * mapping of property name to PropertyInterface objects.
     *
     * all properties are instantiated in the constructor
     *
     * OPTIMIZE: lazy instantiate property objects, just have local array of values
     *
     * @var Property[]
     */
    private array $properties = [];

    /**
     * keep track of properties to be deleted until the save operation was successful.
     *
     * this is needed in order to track deletions in case of refresh
     *
     * keys are the property names, values the properties (in state deleted)
     */
    private array $deletedProperties = [];

    /**
     * ordered list of the child node names.
     */
    private array $nodes = [];

    /**
     * Ordered list of the child node names as known to be at the backend.
     *
     * Used to calculate reordering operations if orderBefore() was used
     *
     * @var array<string[]>|null
     */
    private ?array $originalNodesOrder = null;

    /**
     * Cached instance of the node definition that defines this node.
     *
     * @see Node::getDefinition()
     */
    private NodeDefinitionInterface $definition;

    /**
     * Create a new node instance with data from the storage layer.
     *
     * This is only to be called by the Factory::get() method even inside the
     * Jackalope implementation to allow for custom implementations of Nodes.
     *
     * @param FactoryInterface $factory the object factory
     * @param array|\stdClass  $rawData in the format as returned from TransportInterface::getNode
     * @param bool             $new     set to true if this is a new node being created.
     *                                  Defaults to false which means the node is loaded from storage.
     *
     * @see TransportInterface::getNode()
     *
     * @throws RepositoryException
     *
     * @private
     */
    public function __construct(FactoryInterface $factory, $rawData, string $path, Session $session, ObjectManager $objectManager, bool $new = false)
    {
        parent::__construct($factory, $path, $session, $objectManager, $new);
        $this->isNode = true;

        $this->parseData($rawData, false);
    }

    /**
     * Initialize or update this object with raw data from backend.
     *
     * @param array|\stdClass $rawData     in the format as returned from Jackalope\Transport\TransportInterface
     * @param bool            $update      whether to initialize this object or update
     * @param bool            $keepChanges only used if $update is true, same as $keepChanges in refresh()
     *
     * @throws \InvalidArgumentException
     * @throws LockException
     * @throws ConstraintViolationException
     * @throws RepositoryException
     * @throws ValueFormatException
     * @throws VersionException
     *
     *@see Node::__construct()
     * @see Node::refresh()
     */
    private function parseData($rawData, bool $update, bool $keepChanges = false): void
    {
        // TODO: refactor to use hash array instead of stdClass struct

        $oldNodes = [];
        $oldProperties = [];
        if ($update) {
            // keep backup of old state so we can remove what needs to be removed
            $oldNodes = array_flip(array_values($this->nodes));
            $oldProperties = $this->properties;
        }
        /*
         * we collect all nodes coming from the backend. if we update with
         * $keepChanges, we use this to update the node list rather than losing
         * reorders
         *
         * properties are easy as they are not ordered.
         */
        $nodesInBackend = [];

        foreach ($rawData as $key => $value) {
            $node = false; // reset to avoid trouble
            if (is_object($value)) {
                // this is a node. add it if
                if (!$update // init new node
                    || !$keepChanges // want to discard changes
                    || isset($oldNodes[$key]) // it was already existing before reloading
                    || !($node = $this->objectManager->getCachedNode($this->path.'/'.$key)) // we know nothing about it
                ) {
                    // for all those cases, if the node was moved away or is deleted in current session, we do not add it
                    if (!$this->objectManager->isNodeMoved($this->path.'/'.$key)
                        && !$this->objectManager->isNodeDeleted($this->path.'/'.$key)
                    ) {
                        // otherwise we (re)load a node from backend but a child has been moved away already
                        $nodesInBackend[] = $key;
                    }
                }
                if ($update) {
                    unset($oldNodes[$key]);
                }
            } else {
                // property or meta information

                /* Property type declarations start with :, the value then is
                 * the type string from the NodeType constants. We skip that and
                 * look at the type when we encounter the value of the property.
                 *
                 * If its a binary data, we only get the type declaration and
                 * no data. Then the $value of the type declaration is not the
                 * type string for binary, but the number of bytes of the
                 * property - resp. array of number of bytes.
                 *
                 * The magic property ::NodeIteratorSize tells this node has no
                 * children. Ignore that info for now. We might optimize with
                 * this info once we do prefetch nodes.
                 */
                if (0 === strpos($key, ':')) {
                    if ((is_int($value) || is_array($value))
                         && '::NodeIteratorSize' !== $key
                    ) {
                        // This is a binary property and we just got its length with no data
                        $key = substr($key, 1);
                        if (!isset($rawData->$key)) {
                            $binaries[$key] = $value;
                            if ($update) {
                                unset($oldProperties[$key]);
                            }
                            if (isset($this->properties[$key])) {
                                // refresh existing binary, this will only happen in update
                                // only update length
                                if (!($keepChanges && $this->properties[$key]->isModified())) {
                                    $this->properties[$key]->_setLength($value);
                                    if ($this->properties[$key]->isDirty()) {
                                        $this->properties[$key]->setClean();
                                    }
                                }
                            } else {
                                // this will always fall into the creation mode
                                $this->_setProperty($key, $value, PropertyType::BINARY, true);
                            }
                        }
                    } // else this is a type declaration

                    // skip this entry (if its binary, its already processed
                    continue;
                }

                if ($update && array_key_exists($key, $this->properties)) {
                    unset($oldProperties[$key]);
                    $prop = $this->properties[$key];
                    if ($keepChanges && $prop->isModified()) {
                        continue;
                    }
                } elseif ($update && array_key_exists($key, $this->deletedProperties)) {
                    if ($keepChanges) {
                        // keep the delete
                        continue;
                    }
                    // restore the property
                    $this->properties[$key] = $this->deletedProperties[$key];
                    $this->properties[$key]->setClean();
                    // now let the loop update the value. no need to talk to ObjectManager as it
                    // does not store property deletions
                }

                switch ($key) {
                    case 'jcr:index':
                        $this->index = $value;
                        break;
                    case 'jcr:primaryType':
                        $this->primaryType = $value;
                        // type information is exposed as property too,
                        // although there exist more specific methods
                        $this->_setProperty('jcr:primaryType', $value, PropertyType::NAME, true);
                        break;
                    case 'jcr:mixinTypes':
                        // type information is exposed as property too,
                        // although there exist more specific methods
                        $this->_setProperty($key, $value, PropertyType::NAME, true);
                        break;

                        // OPTIMIZE: do not instantiate properties until needed
                    default:
                        if (isset($rawData->{':'.$key})) {
                            /*
                             * this is an inconsistency between jackrabbit and
                             * dbal transport: jackrabbit has type name, dbal
                             * delivers numeric type.
                             * we should eventually fix the format returned by
                             * transport and either have jackrabbit transport
                             * do the conversion or let dbal store a string
                             * value instead of numerical.
                             */
                            $type = is_numeric($rawData->{':'.$key})
                                    ? $rawData->{':'.$key}
                                    : PropertyType::valueFromName($rawData->{':'.$key});
                        } else {
                            $type = $this->valueConverter->determineType($value);
                        }
                        $this->_setProperty($key, $value, $type, true);
                        break;
                }
            }
        }

        if ($update) {
            if ($keepChanges) {
                // we keep changes. merge new nodes to the right place
                $previous = null;
                $newFromBackend = array_diff($nodesInBackend, array_intersect($this->nodes, $nodesInBackend));

                foreach ($newFromBackend as $name) {
                    $pos = array_search($name, $nodesInBackend, true);
                    if (is_array($this->originalNodesOrder)) {
                        // update original order to send the correct reorderings
                        array_splice($this->originalNodesOrder, $pos, 0, $name);
                    }
                    if (0 === $pos) {
                        array_unshift($this->nodes, $name);
                    } else {
                        // do we find the predecessor of the new node in the list?
                        $insert = array_search($nodesInBackend[$pos - 1], $this->nodes);
                        if (false !== $insert) {
                            array_splice($this->nodes, $insert + 1, 0, $name);
                        } else {
                            // failed to find predecessor, add to the end
                            $this->nodes[] = $name;
                        }
                    }
                }
            } else {
                // discard changes, just overwrite node list
                $this->nodes = $nodesInBackend;
                $this->originalNodesOrder = null;
            }
            foreach ($oldProperties as $name => $property) {
                if (!($keepChanges && $property->isNew())) {
                    // may not call remove(), we don't want another delete with
                    // the backend to be attempted
                    $this->properties[$name]->setDeleted();
                    unset($this->properties[$name]);
                }
            }

            // notify nodes that where not received again that they disappeared
            foreach ($oldNodes as $name => $index) {
                if ($this->objectManager->purgeDisappearedNode($this->path.'/'.$name, $keepChanges)) {
                    // drop, it was not a new child
                    if ($keepChanges) { // otherwise we overwrote $this->nodes with the backend
                        $id = array_search($name, $this->nodes, true);
                        if (false !== $id) {
                            unset($this->nodes[$id]);
                        }
                    }
                }
            }
        } else {
            // new node loaded from backend
            $this->nodes = $nodesInBackend;
        }
    }

    /**
     * Creates a new node at the specified $relPath.
     *
     * {@inheritDoc}
     *
     * In Jackalope, the child node type definition is immediately applied if no
     * primaryNodeTypeName is specified.
     *
     * The PathNotFoundException and ConstraintViolationException are thrown
     * immediately.
     * Version and Lock related exceptions are delayed until save.
     *
     * @api
     */
    public function addNode($relPath, $primaryNodeTypeName = null): NodeInterface
    {
        $relPath = (string) $relPath;

        $this->checkState();

        $ntm = $this->session->getWorkspace()->getNodeTypeManager();

        // are we not the immediate parent?
        if (false !== strpos($relPath, '/')) {
            // forward to real parent

            $relPath = PathHelper::absolutizePath($relPath, $this->getPath(), true);
            $parentPath = PathHelper::getParentPath($relPath);
            $newName = PathHelper::getNodeName($relPath);

            try {
                $parentNode = $this->objectManager->getNodeByPath($parentPath);
            } catch (ItemNotFoundException $e) {
                // we have to throw a different exception if there is a property
                // with that name than if there is nothing at the path at all.
                // lets see if the property exists
                if ($this->session->propertyExists($parentPath)) {
                    throw new ConstraintViolationException("Node '{$this->path}': Not allowed to add a node below property at $parentPath");
                }

                throw new PathNotFoundException($e->getMessage(), $e->getCode(), $e);
            }

            return $parentNode->addNode($newName, $primaryNodeTypeName);
        }

        if (null === $primaryNodeTypeName) {
            if ('rep:root' === $this->primaryType) {
                $primaryNodeTypeName = 'nt:unstructured';
            } else {
                $type = $ntm->getNodeType($this->primaryType);
                $nodeDefinitions = $type->getChildNodeDefinitions();
                foreach ($nodeDefinitions as $def) {
                    if (!is_null($def->getDefaultPrimaryType())) {
                        $primaryNodeTypeName = $def->getDefaultPrimaryTypeName();
                        break;
                    }
                }
            }

            if (is_null($primaryNodeTypeName)) {
                throw new ConstraintViolationException("No matching child node definition found for `$relPath' in type `{$this->primaryType}' for node '{$this->path}'. Please specify the type explicitly.");
            }
        }

        // create child node
        // sanity check: no index allowed. TODO: we should verify this is a valid node name
        if (false !== strpos($relPath, ']')) {
            throw new RepositoryException("The node '{$this->path}' does not allow an index in name of newly created node: $relPath");
        }
        if (in_array($relPath, $this->nodes, true)) {
            throw new ItemExistsException("The node '{$this->path}' already has a child named '$relPath''."); // TODO: same-name siblings if nodetype allows for them
        }

        $data = ['jcr:primaryType' => $primaryNodeTypeName];
        $path = $this->getChildPath($relPath);
        $node = $this->factory->get(Node::class, [$data, $path, $this->session, $this->objectManager, true]);

        $this->addChildNode($node, false); // no need to check the state, we just checked when entering this method
        $this->objectManager->addNode($path, $node);

        if (is_array($this->originalNodesOrder)) {
            // new nodes are added at the end
            $this->originalNodesOrder[] = $relPath;
        }
        // by definition, adding a node sets the parent to modified
        $this->setModified();

        return $node;
    }

    /**
     * @api
     *
     * @throws \InvalidArgumentException
     * @throws ItemExistsException
     * @throws PathNotFoundException
     * @throws RepositoryException
     */
    public function addNodeAutoNamed($nameHint = null, $primaryNodeTypeName = null): NodeInterface
    {
        $name = NodeHelper::generateAutoNodeName(
            $this->nodes,
            $this->session->getWorkspace()->getNamespaceRegistry()->getNamespaces(),
            'jcr',
            $nameHint
        );

        return $this->addNode($name, $primaryNodeTypeName);
    }

    /**
     * Jackalope implements this feature and updates the position of the
     * existing child at srcChildRelPath to be in the list immediately before
     * destChildRelPath.
     *
     * {@inheritDoc}
     *
     * Jackalope has no implementation-specific ordering restriction so no
     * \PHPCR\ConstraintViolationException is expected. VersionException and
     * LockException are not tested immediately but thrown on save.
     *
     * @api
     */
    public function orderBefore($srcChildRelPath, $destChildRelPath): void
    {
        if ($srcChildRelPath === $destChildRelPath) {
            // nothing to move
            return;
        }

        if (null === $this->originalNodesOrder) {
            $this->originalNodesOrder = $this->nodes;
        }

        $this->nodes = NodeHelper::orderBeforeArray($srcChildRelPath, $destChildRelPath, $this->nodes);
        $this->setModified();
    }

    /**
     * @throws PathNotFoundException
     *
     * @api
     *
     * @throws AccessDeniedException
     * @throws ItemNotFoundException
     * @throws \InvalidArgumentException
     */
    public function rename($newName): void
    {
        $names = (array) $this->getParent()->getNodeNames();
        $pos = array_search($this->name, $names, true);
        $next = $names[$pos + 1] ?? null;

        $newPath = $this->parentPath.'/'.$newName;

        if (0 === strpos($newPath, '//')) {
            $newPath = substr($newPath, 1);
        }

        $this->session->move($this->path, $newPath);
        if ($next) {
            $this->getParent()->orderBefore($newName, $next);
        }
    }

    /**
     * Determine whether the children of this node need to be reordered.
     *
     * @private
     */
    public function needsChildReordering(): bool
    {
        return (bool) $this->originalNodesOrder;
    }

    /**
     * Returns the orderBefore commands to be applied to the childnodes
     * to get from the original order to the new one.
     *
     * @return array<string[]> of arrays with 2 fields: name of node to order before second name
     *
     * @throws AccessDeniedException
     * @throws ItemNotFoundException
     *
     * @private
     */
    public function getOrderCommands(): array
    {
        if (!$this->originalNodesOrder) {
            return [];
        }

        $reorders = NodeHelper::calculateOrderBefore($this->originalNodesOrder, $this->nodes);
        $this->originalNodesOrder = null;

        return $reorders;
    }

    /**
     * @param bool $validate When false, node types are not asked to validate
     *                       whether operation is allowed
     *
     * @throws InvalidItemStateException
     * @throws NamespaceException
     * @throws \InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ItemNotFoundException
     *
     * @api
     */
    public function setProperty($name, $value, $type = PropertyType::UNDEFINED, $validate = true): ?PropertyInterface
    {
        $this->checkState();

        // abort early when the node value is not changed
        // for multivalue, === is only true when array keys and values match. this is exactly what we need.
        try {
            if (array_key_exists($name, $this->properties) && $this->properties[$name]->getValue() === $value) {
                return $this->properties[$name];
            }
        } catch (RepositoryException $e) {
            // if anything goes wrong trying to get the property value, move on and don't return early
        }

        if ($validate && 'jcr:uuid' === $name && !$this->isNodeType('mix:referenceable')) {
            throw new ConstraintViolationException('You can only change the uuid of newly created nodes that have "referenceable" mixin.');
        }

        if ($validate) {
            if (is_array($value)) {
                foreach ($value as $key => $v) {
                    if (null === $v) {
                        unset($value[$key]);
                    }
                }
            }
            $types = $this->getMixinNodeTypes();
            $types[] = $this->getPrimaryNodeType();
            if (null !== $value) {
                $exception = null;
                foreach ($types as $nt) {
                    /* @var $nt NodeType */
                    try {
                        $nt->canSetProperty($name, $value, true);
                        $exception = null;
                        break; // exit loop, we found a valid definition
                    } catch (RepositoryException $e) {
                        if (null === $exception) {
                            $exception = $e;
                        }
                    }
                }
                if (null !== $exception) {
                    $types = 'Primary type '.$this->primaryType;
                    if (isset($this->properties['jcr:mixinTypes'])) {
                        $types .= ', mixins '.implode(',', $this->getPropertyValue('jcr:mixinTypes', PropertyType::STRING));
                    }
                    $msg = sprintf('Can not set property %s on node %s. Node types do not allow for this: %s', $name, $this->path, $types);

                    throw new ConstraintViolationException($msg, 0, $exception);
                }
            } else {
                // $value is null for property removal
                // if any type forbids, throw exception
                foreach ($types as $nt) {
                    /* @var $nt \Jackalope\NodeType\NodeType */
                    $nt->canRemoveProperty($name, true);
                }
            }
        }

        // try to get a namespace for the set property
        if (false !== strpos($name, ':')) {
            [$prefix] = explode(':', $name);
            // Check if the namespace exists. If not, throw an NamespaceException
            $this->session->getNamespaceURI($prefix);
        }

        if (is_null($value)) {
            if (isset($this->properties[$name])) {
                $this->properties[$name]->remove();
            }

            return null;
        }

        // if the property is the UUID, then register the UUID against the path
        // of this node.
        if ('jcr:uuid' === $name) {
            $this->objectManager->registerUuid($value, $this->getPath());
        }

        return $this->_setProperty($name, $value, $type, false);
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function getNode($relPath): NodeInterface
    {
        $this->checkState();

        $relPath = (string) $relPath;

        if ('' === $relPath || '/' === $relPath[0]) {
            throw new PathNotFoundException("$relPath is not a relative path");
        }

        try {
            $node = $this->objectManager->getNodeByPath(PathHelper::absolutizePath($relPath, $this->path));
        } catch (ItemNotFoundException $e) {
            throw new PathNotFoundException($e->getMessage(), $e->getCode(), $e);
        }

        return $node;
    }

    /**
     * @api
     */
    public function getNodes($nameFilter = null, $typeFilter = null)
    {
        $this->checkState();

        $names = self::filterNames($nameFilter, $this->nodes);
        $result = [];

        if (count($names)) {
            $paths = [];
            foreach ($names as $name) {
                $paths[] = PathHelper::absolutizePath($name, $this->path);
            }
            $nodes = $this->objectManager->getNodesByPath($paths, Node::class, $typeFilter);

            // OPTIMIZE if we lazy-load in ObjectManager we should not do this loop
            foreach ($nodes as $node) {
                $result[$node->getName()] = $node;
            }
        }

        return new \ArrayIterator($result);
    }

    /**
     * @api
     */
    public function getNodeNames($nameFilter = null, $typeFilter = null)
    {
        $this->checkState();

        if (null !== $typeFilter) {
            return $this->objectManager->filterChildNodeNamesByType($this, $nameFilter, $typeFilter);
        }

        $names = self::filterNames($nameFilter, $this->nodes);

        return new \ArrayIterator($names);
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function getProperty($relPath)
    {
        $this->checkState();

        if (false === strpos($relPath, '/')) {
            if (!isset($this->properties[$relPath])) {
                throw new PathNotFoundException("Property $relPath in ".$this->path);
            }
            if ($this->properties[$relPath]->isDeleted()) {
                throw new PathNotFoundException("Property '$relPath' of ".$this->path.' is deleted');
            }

            return $this->properties[$relPath];
        }

        return $this->session->getProperty($this->getChildPath($relPath));
    }

    /**
     * This method is only meant for the transport to be able to still build a
     * store request for afterwards deleted nodes to support the operationslog.
     *
     * @return Property[] with just the jcr:primaryType property in it
     *
     * @see \Jackalope\Transport\WritingInterface::storeNodes
     *
     * @throws InvalidItemStateException
     * @throws RepositoryException
     *
     * @private
     */
    public function getPropertiesForStoreDeletedNode()
    {
        if (!$this->isDeleted()) {
            throw new InvalidItemStateException('You are not supposed to call this on a not deleted node');
        }
        $myProperty = $this->properties['jcr:primaryType'];
        $myProperty->setClean();
        $path = $this->getChildPath('jcr:primaryType');
        $property = $this->factory->get(
            'Property',
            [['type' => $myProperty->getType(), 'value' => $myProperty->getValue()],
                $path,
                $this->session,
                $this->objectManager,
            ]
        );
        $myProperty->setDeleted();

        return ['jcr:primaryType' => $property];
    }

    /**
     * @throws InvalidItemStateException
     * @throws \InvalidArgumentException
     *
     * @api
     */
    public function getPropertyValue($name, $type = null)
    {
        $this->checkState();

        $val = $this->getProperty($name)->getValue();
        if (null !== $type) {
            $val = $this->valueConverter->convertType($val, $type);
        }

        return $val;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws InvalidItemStateException
     * @throws PathNotFoundException
     * @throws ValueFormatException
     *
     * @api
     */
    public function getPropertyValueWithDefault($relPath, $defaultValue)
    {
        if ($this->hasProperty($relPath)) {
            return $this->getPropertyValue($relPath);
        }

        return $defaultValue;
    }

    /**
     * @api
     */
    public function getProperties($nameFilter = null)
    {
        $this->checkState();

        // OPTIMIZE: lazy iterator?
        $names = self::filterNames($nameFilter, array_keys($this->properties));
        $result = [];

        foreach ($names as $name) {
            // we know for sure the properties exist, as they come from the
            // array keys of the array we are accessing
            $result[$name] = $this->properties[$name];
        }

        return new \ArrayIterator($result);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws InvalidItemStateException
     * @throws ValueFormatException
     * @throws ItemNotFoundException
     *
     * @api
     */
    public function getPropertiesValues($nameFilter = null, $dereference = true): array
    {
        $this->checkState();

        // OPTIMIZE: do not create properties in constructor, go over array here
        $names = self::filterNames($nameFilter, array_keys($this->properties));
        $result = [];

        foreach ($names as $name) {
            // we know for sure the properties exist, as they come from the
            // array keys of the array we are accessing
            $type = $this->properties[$name]->getType();
            if (!$dereference
                    && (PropertyType::REFERENCE === $type
                    || PropertyType::WEAKREFERENCE === $type
                    || PropertyType::PATH === $type)
            ) {
                $result[$name] = $this->properties[$name]->getString();
            } else {
                // OPTIMIZE: collect the paths and call objectmanager->getNodesByPath once
                $result[$name] = $this->properties[$name]->getValue();
            }
        }

        return $result;
    }

    /**
     * @api
     */
    public function getPrimaryItem(): ?ItemInterface
    {
        try {
            $primary_item = null;
            $item_name = $this->getPrimaryNodeType()->getPrimaryItemName();

            if (null !== $item_name) {
                $primary_item = $this->session->getItem($this->path.'/'.$item_name);
            }
        } catch (\Exception $ex) {
            throw new RepositoryException("An error occured while reading the primary item of the node '{$this->path}': ".$ex->getMessage());
        }

        if (null === $primary_item) {
            throw new ItemNotFoundException("No primary item found for node '{$this->path}'");
        }

        return $primary_item;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws AccessDeniedException
     * @throws InvalidItemStateException
     * @throws ItemNotFoundException
     * @throws LockException
     * @throws NamespaceException
     * @throws ConstraintViolationException
     * @throws ValueFormatException
     * @throws VersionException
     * @throws PathNotFoundException
     *
     * @api
     */
    public function getIdentifier()
    {
        $this->checkState();

        if ($this->isNodeType('mix:referenceable')) {
            if (empty($this->properties['jcr:uuid'])) {
                $this->setProperty('jcr:uuid', UUIDHelper::generateUUID());
            }

            return $this->getPropertyValue('jcr:uuid');
        }

        return $this->getPath();
    }

    /**
     * @api
     */
    public function getIndex(): int
    {
        $this->checkState();

        return $this->index;
    }

    /**
     * @api
     */
    public function getReferences($name = null)
    {
        $this->checkState();

        return $this->objectManager->getReferences($this->path, $name);
    }

    /**
     * @api
     */
    public function getWeakReferences($name = null)
    {
        $this->checkState();

        return $this->objectManager->getWeakReferences($this->path, $name);
    }

    /**
     * @api
     */
    public function hasNode($relPath): bool
    {
        $this->checkState();

        if (false === strpos($relPath, '/')) {
            return in_array($relPath, $this->nodes, true);
        }
        if ('' === $relPath || '/' === $relPath[0]) {
            throw new \InvalidArgumentException("'$relPath' is not a relative path");
        }

        return $this->session->nodeExists($this->getChildPath($relPath));
    }

    /**
     * @api
     */
    public function hasProperty($relPath): bool
    {
        $this->checkState();

        if (false === strpos($relPath, '/')) {
            return isset($this->properties[$relPath]);
        }
        if ('' === $relPath || '/' === $relPath[0]) {
            throw new \InvalidArgumentException("'$relPath' is not a relative path");
        }

        return $this->session->propertyExists($this->getChildPath($relPath));
    }

    /**
     * @api
     */
    public function hasNodes(): bool
    {
        $this->checkState();

        return 0 !== count($this->nodes);
    }

    /**
     * @api
     */
    public function hasProperties(): bool
    {
        $this->checkState();

        return 0 !== count($this->properties);
    }

    /**
     * @api
     */
    public function getPrimaryNodeType(): NodeTypeInterface
    {
        $this->checkState();

        $ntm = $this->session->getWorkspace()->getNodeTypeManager();

        return $ntm->getNodeType($this->primaryType);
    }

    /**
     * @api
     */
    public function getMixinNodeTypes(): array
    {
        $this->checkState();

        if (!isset($this->properties['jcr:mixinTypes'])) {
            return [];
        }

        $res = [];
        $ntm = $this->session->getWorkspace()->getNodeTypeManager();

        foreach ($this->properties['jcr:mixinTypes']->getValue() as $type) {
            $res[] = $ntm->getNodeType($type);
        }

        return $res;
    }

    /**
     * @api
     */
    public function isNodeType($nodeTypeName): bool
    {
        $this->checkState();

        // is it the primary type?
        if ($this->primaryType === $nodeTypeName) {
            return true;
        }
        // is it one of the mixin types?
        if (isset($this->properties['jcr:mixinTypes'])) {
            if (in_array($nodeTypeName, $this->properties['jcr:mixinTypes']->getValue())) {
                return true;
            }
        }
        $ntm = $this->session->getWorkspace()->getNodeTypeManager();
        // is the primary type a subtype of the type?
        if ($ntm->getNodeType($this->primaryType)->isNodeType($nodeTypeName)) {
            return true;
        }
        // if there are no mixin types, then we now know this node is not of that type
        if (!isset($this->properties['jcr:mixinTypes'])) {
            return false;
        }
        // is it an ancestor of any of the mixin types?
        foreach ($this->properties['jcr:mixinTypes'] as $mixin) {
            if ($ntm->getNodeType($mixin)->isNodeType($nodeTypeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Changes the primary node type of this node to nodeTypeName.
     *
     * {@inheritDoc}
     *
     * Jackalope only validates type conflicts on save.
     *
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function setPrimaryType($nodeTypeName): void
    {
        $this->checkState();

        throw new NotImplementedException('Write');
    }

    /**
     * {@inheritDoc}
     *
     * Jackalope validates type conflicts only on save, not immediately.
     * It is possible to add mixin types after the first save.
     *
     * @api
     */
    public function addMixin($mixinName): void
    {
        // Check if mixinName exists as a mixin type
        $typemgr = $this->session->getWorkspace()->getNodeTypeManager();
        $nodeType = $typemgr->getNodeType($mixinName);
        if (!$nodeType->isMixin()) {
            throw new ConstraintViolationException("Trying to add a mixin '$mixinName' that is a primary type");
        }

        $this->checkState();

        // TODO handle LockException & VersionException cases
        if ($this->hasProperty('jcr:mixinTypes')) {
            if (!in_array($mixinName, $this->properties['jcr:mixinTypes']->getValue(), true)) {
                $this->properties['jcr:mixinTypes']->addValue($mixinName);
                $this->setModified();
            }
        } else {
            $this->setProperty('jcr:mixinTypes', [$mixinName], PropertyType::NAME);
            $this->setModified();
        }
    }

    /**
     * @throws InvalidItemStateException
     * @throws \InvalidArgumentException
     * @throws AccessDeniedException
     * @throws ItemNotFoundException
     * @throws PathNotFoundException
     * @throws NamespaceException
     * @throws ValueFormatException
     *
     * @api
     */
    public function removeMixin($mixinName): void
    {
        $this->checkState();

        // check if node type is assigned
        if (!$this->hasProperty('jcr:mixinTypes')) {
            throw new NoSuchNodeTypeException("Node does not have type $mixinName");
        }

        $mixins = $this->getPropertyValue('jcr:mixinTypes');
        $key = array_search($mixinName, $mixins, true);
        if (false === $key) {
            throw new NoSuchNodeTypeException("Node does not have type $mixinName");
        }

        unset($mixins[$key]);
        $this->setProperty('jcr:mixinTypes', $mixins); // might be empty array which is fine
    }

    /**
     * @throws \InvalidArgumentException
     * @throws AccessDeniedException
     * @throws InvalidItemStateException
     * @throws ItemNotFoundException
     * @throws NamespaceException
     * @throws PathNotFoundException
     * @throws ValueFormatException
     *
     * @api
     */
    public function setMixins(array $mixinNames): void
    {
        $toRemove = [];
        if ($this->hasProperty('jcr:mixinTypes')) {
            foreach ($this->getPropertyValue('jcr:mixinTypes') as $mixin) {
                if (false !== $key = array_search($mixin, $mixinNames, true)) {
                    unset($mixinNames[$key]);
                } else {
                    $toRemove[] = $mixin;
                }
            }
        }
        if (!(count($toRemove) || count($mixinNames))) {
            return; // nothing to do
        }

        // make sure the new types actually exist before we add anything
        $ntm = $this->session->getWorkspace()->getNodeTypeManager();
        foreach ($mixinNames as $mixinName) {
            $nodeType = $ntm->getNodeType($mixinName);
            if (!$nodeType->isMixin()) {
                throw new ConstraintViolationException("Trying to add a mixin '$mixinName' that is a primary type");
            }
        }

        foreach ($mixinNames as $type) {
            $this->addMixin($type);
        }

        foreach ($toRemove as $type) {
            $this->removeMixin($type);
        }
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function canAddMixin($mixinName)
    {
        $this->checkState();

        throw new NotImplementedException('Write');
    }

    /**
     * @api
     */
    public function getDefinition()
    {
        $this->checkState();

        if ('rep:root' === $this->primaryType) {
            throw new NotImplementedException('what is the definition of the root node?');
        }

        if (!isset($this->definition)) {
            $this->definition = $this->findItemDefinition(function (NodeTypeInterface $nt) {
                return $nt->getChildNodeDefinitions();
            });
        }

        return $this->definition;
    }

    /**
     * @api
     */
    public function update($srcWorkspace): void
    {
        $this->checkState();

        if ($this->isNew()) {
            // no node in workspace
            return;
        }

        $this->getSession()->getTransport()->updateNode($this, $srcWorkspace);
        $this->setDirty();
        $this->setChildrenDirty();
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function getCorrespondingNodePath($workspaceName): string
    {
        $this->checkState();

        return $this->getSession()
            ->getTransport()
            ->getNodePathForIdentifier($this->getIdentifier(), $workspaceName);
    }

    /**
     * @api
     */
    public function getSharedSet(): void
    {
        $this->checkState();

        throw new NotImplementedException();
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function removeSharedSet(): void
    {
        $this->checkState();
        $this->setModified();

        throw new NotImplementedException('Write');
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function removeShare(): void
    {
        $this->checkState();
        $this->setModified();

        throw new NotImplementedException('Write');
    }

    /**
     * @api
     */
    public function isCheckedOut(): bool
    {
        $this->checkState();

        $workspace = $this->session->getWorkspace();
        $versionManager = $workspace->getVersionManager();

        return $versionManager->isCheckedOut($this->getPath());
    }

    /**
     * @api
     */
    public function isLocked(): bool
    {
        $this->checkState();

        throw new NotImplementedException();
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function followLifecycleTransition($transition): void
    {
        $this->checkState();
        $this->setModified();

        throw new NotImplementedException('Write');
    }

    /**
     * @throws InvalidItemStateException
     *
     * @api
     */
    public function getAllowedLifecycleTransitions()
    {
        $this->checkState();

        throw new NotImplementedException('Write');
    }

    /**
     * Refresh this node.
     *
     * {@inheritDoc}
     *
     * This is also called internally to refresh when the node is accessed in
     * state DIRTY.
     *
     * @see Item::checkState
     */
    protected function refresh(bool $keepChanges, bool $internal = false): void
    {
        if (!$internal && $this->isDeleted()) {
            throw new InvalidItemStateException('This item has been removed and can not be refreshed');
        }
        $deleted = false;

        // Get properties and children from backend
        try {
            $json = $this->objectManager->getTransport()->getNode(
                is_null($this->oldPath)
                    ? $this->path
                    : $this->oldPath
            );
        } catch (ItemNotFoundException $ex) {
            // The node was deleted in another session
            if (!$this->objectManager->purgeDisappearedNode($this->path, $keepChanges)) {
                throw new \LogicException($this->path.' should be purged and not kept');
            }
            $keepChanges = false; // delete never keeps changes
            if (!$internal) {
                // this is not an internal update
                $deleted = true;
            }

            // continue with empty data, parseData will notify all cached
            // children and all properties that we are removed
            $json = [];
        }

        $this->parseData($json, true, $keepChanges);

        if ($deleted) {
            $this->setDeleted();
        }
    }

    /**
     * Remove this node.
     *
     * {@inheritDoc}
     *
     * A jackalope node needs to notify the parent node about this if it is
     * cached, in addition to \PHPCR\ItemInterface::remove()
     *
     * @uses Node::unsetChildNode()
     *
     * @api
     */
    public function remove(): void
    {
        $this->checkState();
        $parent = $this->getParent();

        $parentNodeType = $parent->getPrimaryNodeType();
        // will throw a ConstraintViolationException if this node can't be removed
        $parentNodeType->canRemoveNode($this->getName(), true);

        $parent->unsetChildNode($this->name, true);

        // once we removed ourselves, $this->getParent() won't work anymore. do this last
        parent::remove();
    }

    /**
     * Removes the reference in the internal node storage.
     *
     * @param string $name  the name of the child node to unset
     * @param bool   $check whether a state check should be done - set to false
     *                      during internal update operations
     *
     * @throws ItemNotFoundException     If there is no child with $name
     * @throws InvalidItemStateException
     *
     * @private
     */
    public function unsetChildNode(string $name, bool $check): void
    {
        if ($check) {
            $this->checkState();
        }

        $key = array_search($name, $this->nodes, true);
        if (false === $key) {
            if (!$check) {
                // inside a refresh operation
                return;
            }
            throw new ItemNotFoundException("Could not remove child node because it's already gone");
        }

        unset($this->nodes[$key]);

        if (null !== $this->originalNodesOrder) {
            $this->originalNodesOrder = array_flip($this->originalNodesOrder);
            unset($this->originalNodesOrder[$name]);
            $this->originalNodesOrder = array_flip($this->originalNodesOrder);
        }
    }

    /**
     * Adds child node to this node for internal reference.
     *
     * @param NodeInterface $node  The name of the child node
     * @param bool          $check whether to check state
     * @param string|null   $name  is used in cases where $node->getName would not return the correct name (during move operation)
     *
     * @throws InvalidItemStateException
     * @throws RepositoryException
     *
     * @private
     */
    public function addChildNode(NodeInterface $node, bool $check, string $name = null): void
    {
        if ($check) {
            $this->checkState();
        }

        if (is_null($name)) {
            $name = $node->getName();
        }

        $nt = $this->getPrimaryNodeType();
        // will throw a ConstraintViolationException if this node can't be added
        $nt->canAddChildNode($name, $node->getPrimaryNodeType()->getName(), true);

        // TODO: same name siblings

        $this->nodes[] = $name;

        if (null !== $this->originalNodesOrder) {
            $this->originalNodesOrder[] = $name;
        }
    }

    /**
     * Removes the reference in the internal node storage.
     *
     * @throws ItemNotFoundException     If this node has no property with name $name
     * @throws InvalidItemStateException
     * @throws RepositoryException
     *
     * @private
     */
    public function unsetProperty(string $name): void
    {
        $this->checkState();
        $this->setModified();

        if (!array_key_exists($name, $this->properties)) {
            throw new ItemNotFoundException('Implementation Error: Could not remove property from node because it is already gone');
        }
        $this->deletedProperties[$name] = $this->properties[$name];
        unset($this->properties[$name]);
    }

    /**
     * In addition to calling parent method, tell all properties and clean deletedProperties.
     */
    public function confirmSaved(): void
    {
        foreach ($this->properties as $property) {
            if ($property->isModified() || $property->isNew()) {
                $property->confirmSaved();
            }
        }
        $this->deletedProperties = [];
        parent::confirmSaved();
    }

    /**
     * In addition to calling parent method, tell all properties.
     */
    public function setPath(string $path, bool $move = false): void
    {
        parent::setPath($path, $move);
        foreach ($this->properties as $property) {
            $property->setPath($path.'/'.$property->getName(), $move);
        }
    }

    /**
     * Make sure $p is an absolute path.
     *
     * If its a relative path, prepend the path to this node, otherwise return as is
     *
     * @param string $p the relative or absolute property or node path
     *
     * @return string the absolute path to this item, with relative paths resolved against the current node
     */
    private function getChildPath(string $p): string
    {
        if ('' === $p) {
            throw new \InvalidArgumentException('Name can not be empty');
        }
        if ('/' === $p[0]) {
            return $p;
        }
        // relative path, combine with base path for this node
        $path = '/' === $this->path ? '/' : $this->path.'/';

        return $path.$p;
    }

    /**
     * Filter the list of names according to the filter expression / array.
     *
     * @param string|string[] $filter according to getNodes|getProperties
     * @param string[]        $names  list of names to filter
     *
     * @return string[] the names in $names that match the filter
     */
    private static function filterNames($filter, array $names): array
    {
        if (null !== $filter) {
            $filtered = [];
            $filter = (array) $filter;
            foreach ($filter as $k => $f) {
                $f = trim($f);
                $filter[$k] = strtr($f, [
                    '*' => '.*', // wildcard
                    '.' => '\\.', // escape regexp
                    '\\' => '\\\\',
                    '{' => '\\{',
                    '}' => '\\}',
                    '(' => '\\(',
                    ')' => '\\)',
                    '+' => '\\+',
                    '^' => '\\^',
                    '$' => '\\$',
                ]);
            }
            foreach ($names as $name) {
                foreach ($filter as $f) {
                    if (preg_match('/^'.$f.'$/', $name)) {
                        $filtered[] = $name;
                    }
                }
            }
        } else {
            $filtered = $names;
        }

        return $filtered;
    }

    /**
     * Provide Traversable interface: redirect to getNodes with no filter.
     *
     * @throws RepositoryException
     */
    public function getIterator(): \Iterator
    {
        $this->checkState();

        return $this->getNodes();
    }

    /**
     * Implement really setting the property without any notification.
     *
     * Implement the setProperty, but also used from constructor or in refresh,
     * when the backend has a new property that is not yet loaded in memory.
     *
     * @param string|int $type
     * @param bool       $internal whether we are setting this node through api or internally
     *
     * @throws \InvalidArgumentException
     * @throws LockException
     * @throws ConstraintViolationException
     * @throws RepositoryException
     * @throws UnsupportedRepositoryOperationException
     * @throws ValueFormatException
     * @throws VersionException
     *
     * @see Node::setProperty
     * @see Node::refresh
     * @see Node::__construct
     */
    private function _setProperty(string $name, $value, $type, bool $internal): Property
    {
        if ('' === $name || false !== strpos($name, '/')) {
            throw new \InvalidArgumentException("The name '$name' is no valid property name");
        }

        if (!isset($this->properties[$name])) {
            $path = $this->getChildPath($name);
            $property = $this->factory->get(
                Property::class,
                [
                    ['type' => $type, 'value' => $value],
                    $path,
                    $this->session,
                    $this->objectManager,
                    !$internal,
                ]
            );
            $this->properties[$name] = $property;
            if (!$internal) {
                $this->setModified();
            }
        } else {
            if ($internal) {
                $this->properties[$name]->_setValue($value, $type);
                if ($this->properties[$name]->isDirty()) {
                    $this->properties[$name]->setClean();
                }
            } else {
                $this->properties[$name]->setValue($value, $type);
            }
        }

        return $this->properties[$name];
    }

    /**
     * Overwrite to set the properties dirty as well.
     *
     * @private
     */
    public function setDirty(bool $keepChanges = false, $targetState = false): void
    {
        parent::setDirty($keepChanges, $targetState);

        foreach ($this->properties as $property) {
            if ($keepChanges && self::STATE_NEW !== $property->getState()) {
                // if we want to keep changes, we do not want to set new properties dirty.
                $property->setDirty($keepChanges, $targetState);
            }
        }
    }

    /**
     * Mark all cached children as dirty.
     *
     * @private
     */
    public function setChildrenDirty(): void
    {
        foreach ($this->objectManager->getCachedDescendants($this->getPath()) as $childNode) {
            $childNode->setDirty();
        }
    }

    /**
     * In addition to set this item deleted, set all properties to deleted.
     *
     * They will be automatically deleted by the backend, but the user might
     * still have a reference to one of the property objects.
     *
     * @private
     */
    public function setDeleted(): void
    {
        parent::setDeleted();
        foreach ($this->properties as $property) {
            $property->setDeleted(); // not all properties are tracked in objectmanager
        }
    }

    /**
     * {@inheritDoc}
     *
     * Additionally, notifies all properties of this node. Child nodes are not
     * notified, it is the job of the ObjectManager to know which nodes are
     * cached and notify them.
     */
    public function beginTransaction(): void
    {
        parent::beginTransaction();

        // Notify the children properties
        foreach ($this->properties as $prop) {
            $prop->beginTransaction();
        }
    }

    /**
     * {@inheritDoc}
     *
     * Additionally, notifies all properties of this node. Child nodes are not
     * notified, it is the job of the ObjectManager to know which nodes are
     * cached and notify them.
     */
    public function commitTransaction(): void
    {
        parent::commitTransaction();

        foreach ($this->properties as $prop) {
            $prop->commitTransaction();
        }
    }

    /**
     * {@inheritDoc}
     *
     * Additionally, notifies all properties of this node. Child nodes are not
     * notified, it is the job of the ObjectManager to know which nodes are
     * cached and notify them.
     */
    public function rollbackTransaction(): void
    {
        parent::rollbackTransaction();

        foreach ($this->properties as $prop) {
            $prop->rollbackTransaction();
        }
    }
}
