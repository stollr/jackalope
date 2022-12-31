<?php

namespace Jackalope\NodeType;

use Jackalope\Transport\AddNodeOperation;
use PHPCR\ItemExistsException;
use PHPCR\Lock\LockException;
use PHPCR\NamespaceException;
use PHPCR\NodeInterface;
use PHPCR\NodeType\ConstraintViolationException;
use PHPCR\NodeType\NodeDefinitionInterface;
use PHPCR\NodeType\NodeTypeDefinitionInterface;
use PHPCR\NodeType\PropertyDefinitionInterface;
use PHPCR\PathNotFoundException;
use PHPCR\PropertyInterface;
use PHPCR\PropertyType;
use PHPCR\RepositoryException;
use PHPCR\Util\PathHelper;
use PHPCR\Util\UUIDHelper;
use PHPCR\ValueFormatException;
use PHPCR\Version\VersionException;

/**
 * This class processes according to its declared node types.
 *
 * - Validates properties
 * - Auto-generates property values
 * - Generates extra node addition operations that follow from the node definition.
 *
 * Adapted from the jackalope-doctrine-dbal implementation:
 * https://github.com/jackalope/jackalope-doctrine-dbal/blob/31cca1d1fb7fbe56423fa34478e15ce6d93313fd/src/Jackalope/Transport/DoctrineDBAL/Client.php
 */
class NodeProcessor
{
    /**
     * Regex pattern to validate a URI according
     * to RFC3986:.
     *
     * http://tools.ietf.org/html/rfc3986
     */
    public const VALIDATE_URI_RFC3986 = "
/^
([a-z][a-z0-9\\*\\-\\.]*):\\/\\/
(?:
  (?:(?:[\\w\\.\\-\\+!$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+:)*
  (?:[\\w\\.\\-\\+%!$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+@
)?
(?:
  (?:[a-z0-9\\-\\.]|%[0-9a-f]{2})+
  |(?:\\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\\])
)
(?::[0-9]+)?
(?:[\\/|\\?]
  (?:[\\w#!:\\.\\?\\+=&@!$'~*,;\\/\\(\\)\\[\\]\\-]|%[0-9a-f]{2})
*)?
$/xi";

    /**
     * Regex to assert that a string does not contain any illegal characters.
     */
    public const VALIDATE_STRING = '/[^\x{9}\x{a}\x{d}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u';

    /**
     * @var string ID of the connected user
     */
    private $userId;

    /**
     * @var bool Whether the last modified property should be updated automatically
     */
    private $autoLastModified;

    /**
     * @var \ArrayObject List of namespaces known to the current session. Keys are prefix, values are URI.
     */
    private $namespaces;

    /**
     * @param string       $userId           ID of the connected user
     * @param \ArrayObject $namespaces       List of namespaces in the current session. Keys are prefix, values are URI.
     * @param bool         $autoLastModified Whether the last modified property should be updated automatically
     */
    public function __construct($userId, \ArrayObject $namespaces, $autoLastModified = true)
    {
        $this->userId = (string) $userId;
        $this->autoLastModified = $autoLastModified;
        $this->namespaces = $namespaces;
    }

    /**
     * Process the given node and return eventual extra operations determined from the node.
     *
     * @return AddNodeOperation[] additional operations that the client must execute for autocreated nodes
     */
    public function process(NodeInterface $node)
    {
        $this->validateNamespace($node->getName());

        $nodeDef = $node->getPrimaryNodeType();
        $nodeTypes = $node->getMixinNodeTypes();
        array_unshift($nodeTypes, $nodeDef);

        $additionalOperations = [];
        foreach ($nodeTypes as $nodeType) {
            /* @var $nodeType NodeTypeDefinitionInterface */
            $additionalOperations = array_merge(
                $additionalOperations,
                $this->processNodeWithType($node, $nodeType)
            );
        }

        return $additionalOperations;
    }

    /**
     * Validate this node with the nodetype and generate not yet existing
     * autogenerated properties as necessary.
     *
     * @return AddNodeOperation[] additional operations to handle autocreated nodes
     *
     * @throws \InvalidArgumentException
     * @throws RepositoryException
     * @throws ItemExistsException
     * @throws LockException
     * @throws ConstraintViolationException
     * @throws PathNotFoundException
     * @throws VersionException
     * @throws ValueFormatException
     */
    private function processNodeWithType(NodeInterface $node, NodeType $nodeTypeDefinition)
    {
        $additionalOperations = [];

        foreach ($nodeTypeDefinition->getDeclaredChildNodeDefinitions() as $childDef) {
            /* @var $childDef NodeDefinitionInterface */
            if (!$node->hasNode($childDef->getName())) {
                if ('*' === $childDef->getName()) {
                    continue;
                }

                if ($childDef->isMandatory() && !$childDef->isAutoCreated()) {
                    throw new RepositoryException(sprintf(
                        'Child "%s" is mandatory, but is not present while saving "%s" at path "%s"',
                        $childDef->getName(),
                        $nodeTypeDefinition->getName(),
                        $node->getPath()
                    ));
                }

                if ($childDef->isAutoCreated()) {
                    $requiredPrimaryTypeNames = $childDef->getRequiredPrimaryTypeNames();
                    $primaryType = count($requiredPrimaryTypeNames) ? current($requiredPrimaryTypeNames) : null;
                    $newNode = $node->addNode($childDef->getName(), $primaryType);
                    $absPath = $node->getPath().'/'.$childDef->getName();
                    $operation = new AddNodeOperation($absPath, $newNode);
                    $additionalOperations[] = $operation;
                }
            }
        }

        foreach ($nodeTypeDefinition->getDeclaredPropertyDefinitions() as $propertyDef) {
            /* @var $propertyDef PropertyDefinitionInterface */
            if ('*' === $propertyDef->getName()) {
                continue;
            }

            if (!$node->hasProperty($propertyDef->getName())) {
                if ($propertyDef->isMandatory() && !$propertyDef->isAutoCreated()) {
                    throw new RepositoryException(sprintf(
                        'Property "%s" is mandatory, but is not present while saving "%s" at "%s"',
                        $propertyDef->getName(),
                        $nodeTypeDefinition->getName(),
                        $node->getPath()
                    ));
                }

                if ($propertyDef->isAutoCreated()) {
                    switch ($propertyDef->getName()) {
                        case 'jcr:uuid':
                            $value = UUIDHelper::generateUUID();
                            break;
                        case 'jcr:createdBy':
                        case 'jcr:lastModifiedBy':
                            $value = $this->userId;
                            break;
                        case 'jcr:created':
                        case 'jcr:lastModified':
                            $value = new \DateTime();
                            break;
                        case 'jcr:etag':
                            // TODO: http://www.day.com/specs/jcr/2.0/3_Repository_Model.html#3.7.12.1%20mix:etag
                            $value = 'TODO: generate from binary properties of this node';
                            break;
                        default:
                            $defaultValues = $propertyDef->getDefaultValues();
                            if ($propertyDef->isMultiple()) {
                                $value = $defaultValues;
                            } elseif (isset($defaultValues[0])) {
                                $value = $defaultValues[0];
                            } else {
                                // When implementing versionable or activity, we need to handle more properties explicitly
                                throw new RepositoryException(sprintf(
                                    'No default value for autocreated property "%s" at "%s"',
                                    $propertyDef->getName(),
                                    $node->getPath()
                                ));
                            }
                    }

                    $node->setProperty(
                        $propertyDef->getName(),
                        $value,
                        $propertyDef->getRequiredType()
                    );
                }
            } elseif ($propertyDef->isAutoCreated()) {
                $prop = $node->getProperty($propertyDef->getName());
                if (!$prop->isModified() && !$prop->isNew()) {
                    switch ($propertyDef->getName()) {
                        case 'jcr:lastModified':
                            if ($this->autoLastModified) {
                                $prop->setValue(new \DateTime());
                            }
                            break;
                        case 'jcr:lastModifiedBy':
                            if ($this->autoLastModified) {
                                $prop->setValue($this->userId);
                            }
                            break;
                        case 'jcr:etag':
                            // TODO: update etag if needed
                            break;
                    }
                }
            }
        }

        foreach ($nodeTypeDefinition->getDeclaredSupertypes() as $superType) {
            $this->processNodeWithType($node, $superType);
        }

        foreach ($node->getProperties() as $property) {
            $this->assertValidProperty($property);
        }

        return $additionalOperations;
    }

    /**
     * Validation if all the data is correct before writing it into the database.
     *
     * @throws RepositoryException
     * @throws ValueFormatException
     */
    private function assertValidProperty(PropertyInterface $property)
    {
        $type = $property->getType();
        switch ($type) {
            case PropertyType::NAME:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = [$values];
                }
                foreach ($values as $value) {
                    $pos = strpos($value, ':');
                    if (false !== $pos) {
                        $prefix = substr($value, 0, $pos);

                        if (!isset($this->namespaces[$prefix])) {
                            throw new ValueFormatException(sprintf(
                                'Invalid value for NAME property type at "%s", the namespace prefix "%s" does not exist.");',
                                $property->getPath(),
                                $prefix
                            ));
                        }
                    }
                }
                break;
            case PropertyType::PATH:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = [$values];
                }
                foreach ($values as $value) {
                    try {
                        // making the path absolute also validates the result to be a valid path
                        PathHelper::absolutizePath($value, $property->getPath());
                    } catch (RepositoryException $e) {
                        throw new ValueFormatException(sprintf('Value "%s" for PATH property at "%s" is invalid', $value, $property->getPath()), 0, $e);
                    }
                }
                break;
            case PropertyType::URI:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = [$values];
                }
                foreach ($values as $value) {
                    if (!preg_match(self::VALIDATE_URI_RFC3986, $value)) {
                        throw new ValueFormatException(sprintf(
                            'Invalid value "%s" for URI property at "%s". Value has to comply with RFC 3986.',
                            $value,
                            $property->getPath()
                        ));
                    }
                }
                break;
            case PropertyType::DECIMAL:
            case PropertyType::STRING:
                $values = (array) $property->getValue();
                foreach ($values as $value) {
                    if (0 !== preg_match(self::VALIDATE_STRING, $value)) {
                        throw new ValueFormatException(sprintf(
                            'Invalid character detected in value %s for STRING property at "%s".',
                            json_encode($value),
                            $property->getPath()
                        ));
                    }
                }
                break;
        }
    }

    /**
     * Ensure that, if a namespace with an alias is passed,
     * that the alias is registered.
     *
     * @param string $name
     *
     * @throws NamespaceException
     */
    private function validateNamespace($name)
    {
        $aliasLength = strpos($name, ':');

        if (false === $aliasLength) {
            return;
        }

        $alias = substr($name, 0, $aliasLength);

        if (!isset($this->namespaces[$alias])) {
            $aliases = implode("', '", array_keys($this->namespaces->getArrayCopy()));

            throw new NamespaceException(
                "Namespace alias '$alias' is not known for name '$name', known namespace aliases are: '$aliases'"
            );
        }
    }
}
