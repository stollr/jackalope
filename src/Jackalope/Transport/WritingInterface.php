<?php

namespace Jackalope\Transport;

use Jackalope\Node;
use Jackalope\Workspace;
use PHPCR\PathNotFoundException;
use PHPCR\RepositoryException;
use PHPCR\WorkspaceInterface;

/**
 * Defines the methods needed for Writing support.
 *
 * Notes:
 *
 * Registering and removing namespaces is also part of this chapter.
 *
 * The announced IDENTIFIER_STABILITY must be guaranteed by the transport.
 * The interface does not differ though.
 *
 * @see <a href="http://www.day.com/specs/jcr/2.0/10_Writing.html">JCR 2.0, chapter 10</a>
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
interface WritingInterface extends TransportInterface
{
    /**
     * Whether this node name conforms to the specification.
     *
     * Note: There is a minimal implementation in BaseTransport
     *
     * @param string $name The name to check
     *
     * @return bool always true, if the name is not valid a RepositoryException is thrown
     *
     * @throws RepositoryException if the name contains invalid characters
     *
     * @see http://www.day.com/specs/jcr/2.0/3_Repository_Model.html#3.2.2%20Local%20Names
     */
    public function assertValidName($name): bool;

    /**
     * Copies a Node from src (potentially from another workspace) to dst in
     * the current workspace.
     *
     * This method does not need to load the node but can execute the copy
     * directly in the storage.
     *
     * If there already is a node at $destAbsPath, the transport may merge
     * nodes as described in the WorkspaceInterface::copy documentation.
     *
     * @param string $srcAbsPath   Absolute source path to the node
     * @param string $destAbsPath  Absolute destination path including the new
     *                             node name
     * @param string $srcWorkspace The workspace where the source node can be
     *                             found or null for current workspace
     *
     * @see http://www.ietf.org/rfc/rfc2518.txt
     * @see WorkspaceInterface::copy
     */
    public function copyNode(string $srcAbsPath, string $destAbsPath, string $srcWorkspace = null): void;

    /**
     * Clones the subgraph at the node srcAbsPath in srcWorkspace to the new
     * location at destAbsPath in this workspace.
     *
     * There may be no node at dstAbsPath
     * This method does not need to load the node but can execute the clone
     * directly in the storage.
     *
     * @param string $srcWorkspace   The workspace where the source node can be found
     * @param string $srcAbsPath     Absolute source path to the node
     * @param string $destAbsPath    Absolute destination path including the new
     *                               node name
     * @param bool   $removeExisting whether to remove a node with the same identifier
     *                               if there exists one
     *
     * @see http://www.ietf.org/rfc/rfc2518.txt
     * @see WorkspaceInterface::cloneFrom
     */
    public function cloneFrom(string $srcWorkspace, string $srcAbsPath, string $destAbsPath, bool $removeExisting): void;

    /**
     * Update a node and its children to match its corresponding node in the specified workspace.
     *
     * @param Node   $node         the node to update
     * @param string $srcWorkspace The workspace where the corresponding source node can be found
     */
    public function updateNode(Node $node, string $srcWorkspace): void;

    /**
     * Perform a batch of move operations in the order of the passed array.
     *
     * @param MoveNodeOperation[] $operations
     */
    public function moveNodes(array $operations): void;

    /**
     * Moves a node from src to dst outside of a transaction.
     *
     * @param string $srcAbsPath Absolute source path to the node
     * @param string $dstAbsPath Absolute destination path (must NOT include
     *                           the new node name)
     *
     * @see http://www.ietf.org/rfc/rfc2518.txt
     * @see Workspace::moveNode
     */
    public function moveNodeImmediately(string $srcAbsPath, string $dstAbsPath): void;

    /**
     * Reorder the children of $node as the node said it needs them reordered.
     *
     * You can either get the reordering list with getOrderCommands or use
     * getNodeNames to get the absolute order.
     *
     * @param Node $node the node to reorder its children
     */
    public function reorderChildren(Node $node): void;

    /**
     * Perform a batch remove operation.
     *
     * Take care that cyclic REFERENCE properties of to be deleted nodes do not
     * lead to errors.
     *
     * @param RemoveNodeOperation[] $operations
     */
    public function deleteNodes(array $operations): void;

    /**
     * Perform a batch remove operation.
     *
     * @param RemovePropertyOperation[] $operations
     */
    public function deleteProperties(array $operations): void;

    /**
     * Deletes a node and the whole subtree under it outside of a transaction.
     *
     * @param string $path Absolute path to the node
     *
     * @see Workspace::removeItem
     *
     * @throws PathNotFoundException if the item is already deleted on
     *                               the server. This should not happen if ObjectManager is correctly
     *                               checking.
     * @throws RepositoryException   if error occurs
     */
    public function deleteNodeImmediately(string $path): void;

    /**
     * Deletes a property outside of a transaction.
     *
     * @param string $path Absolute path to the property
     *
     * @see Workspace::removeItem
     *
     * @throws PathNotFoundException if the item is already deleted on
     *                               the server. This should not happen if ObjectManager is correctly
     *                               checking.
     * @throws RepositoryException   if error occurs
     */
    public function deletePropertyImmediately(string $path): void;

    /**
     * Store all nodes in the AddNodeOperations.
     *
     * Transport stores the node at its path, with all properties (but do not
     * store children).
     *
     * The transport is responsible to ensure that the node is valid and
     * has to generate autocreated properties.
     *
     * Note: Nodes in the log may be deleted if they are deleted. The delete
     * request will be passed later, according to the log. You should still
     * create it here as it might be used temporarily in move operations or
     * such. Use Node::getPropertiesForStoreDeletedNode in that case to avoid
     * a status check of the deleted node.
     *
     * @see BaseTransport::validateNode
     *
     * @param AddNodeOperation[] $operations the operations containing the nodes to store
     *
     * @throws RepositoryException if error occurs
     */
    public function storeNodes(array $operations): void;

    /**
     * Update the properties of a node.
     *
     * @param Node $node the node to update
     */
    public function updateProperties(Node $node): void;

    /**
     * Register a new namespace.
     *
     * Validation based on what was returned from getNamespaces has already
     * happened in the NamespaceRegistry.
     *
     * The transport is however responsible of removing an existing prefix for
     * that uri, if one exists. As well as removing the current uri mapped to
     * this prefix if this prefix is already existing.
     *
     * @param string $prefix the prefix to be mapped
     * @param string $uri    the URI to be mapped
     */
    public function registerNamespace(string $prefix, string $uri): void;

    /**
     * Unregister an existing namespace.
     *
     * Validation based on what was returned from getNamespaces has already
     * happened in the NamespaceRegistry.
     *
     * @param string $prefix the prefix to unregister
     */
    public function unregisterNamespace(string $prefix): void;

    /**
     * Called before any data is written.
     */
    public function prepareSave(): void;

    /**
     * Called after everything internally is done in the save() method
     * so the transport has a chance to do final stuff (or commit everything
     * at once).
     */
    public function finishSave(): void;

    /**
     * Called if a save operation caused an exception.
     */
    public function rollbackSave(): void;
}
