<?php

namespace Jackalope\Query\QOM;

use Jackalope\FactoryInterface;
use Jackalope\ObjectManager;
use PHPCR\Query\QOM\AndInterface;
use PHPCR\Query\QOM\BindVariableValueInterface;
use PHPCR\Query\QOM\ChildNodeInterface;
use PHPCR\Query\QOM\ChildNodeJoinConditionInterface;
use PHPCR\Query\QOM\ColumnInterface;
use PHPCR\Query\QOM\ComparisonInterface;
use PHPCR\Query\QOM\ConstraintInterface;
use PHPCR\Query\QOM\DescendantNodeInterface;
use PHPCR\Query\QOM\DescendantNodeJoinConditionInterface;
use PHPCR\Query\QOM\DynamicOperandInterface;
use PHPCR\Query\QOM\EquiJoinConditionInterface;
use PHPCR\Query\QOM\FullTextSearchInterface;
use PHPCR\Query\QOM\FullTextSearchScoreInterface;
use PHPCR\Query\QOM\JoinConditionInterface;
use PHPCR\Query\QOM\JoinInterface;
use PHPCR\Query\QOM\LengthInterface;
use PHPCR\Query\QOM\LiteralInterface;
use PHPCR\Query\QOM\LowerCaseInterface;
use PHPCR\Query\QOM\NodeLocalNameInterface;
use PHPCR\Query\QOM\NodeNameInterface;
use PHPCR\Query\QOM\NotInterface;
use PHPCR\Query\QOM\OrderingInterface;
use PHPCR\Query\QOM\OrInterface;
use PHPCR\Query\QOM\PropertyExistenceInterface;
use PHPCR\Query\QOM\PropertyValueInterface;
use PHPCR\Query\QOM\QueryObjectModelConstantsInterface;
use PHPCR\Query\QOM\QueryObjectModelFactoryInterface;
use PHPCR\Query\QOM\QueryObjectModelInterface;
use PHPCR\Query\QOM\SameNodeInterface;
use PHPCR\Query\QOM\SameNodeJoinConditionInterface;
use PHPCR\Query\QOM\SelectorInterface;
use PHPCR\Query\QOM\SourceInterface;
use PHPCR\Query\QOM\StaticOperandInterface;
use PHPCR\Query\QOM\UpperCaseInterface;

/**
 * {@inheritDoc}
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class QueryObjectModelFactory implements QueryObjectModelFactoryInterface
{
    protected FactoryInterface $factory;
    protected ?ObjectManager $objectManager;

    public function __construct(FactoryInterface $factory, ?ObjectManager $objectManager = null)
    {
        $this->factory = $factory;
        $this->objectManager = $objectManager;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function createQuery(
        SourceInterface $source,
        ConstraintInterface $constraint = null,
        array $orderings = [],
        array $columns = []
    ): QueryObjectModelInterface {
        return $this->factory->get(
            QueryObjectModel::class,
            [$this->objectManager, $source, $constraint, $orderings, $columns]
        );
    }

    // TODO: should we use the factory ->get here? but this would mean all of them need to expect the factory as first parameter
    // or refactor the factory to make the first param optional.

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function selector($selectorName, $nodeTypeName): SelectorInterface
    {
        return new Selector($selectorName, $nodeTypeName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function join(SourceInterface $left, SourceInterface $right, $joinType, JoinConditionInterface $joinCondition): JoinInterface
    {
        return new Join($left, $right, $joinType, $joinCondition);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function equiJoinCondition($selector1Name, $property1Name, $selector2Name, $property2Name): EquiJoinConditionInterface
    {
        return new EquiJoinCondition($selector1Name, $property1Name, $selector2Name, $property2Name);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function sameNodeJoinCondition($selector1Name, $selector2Name, $selector2Path = null): SameNodeJoinConditionInterface
    {
        return new SameNodeJoinCondition($selector1Name, $selector2Name, $selector2Path);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function childNodeJoinCondition($childSelectorName, $parentSelectorName): ChildNodeJoinConditionInterface
    {
        return new ChildNodeJoinCondition($childSelectorName, $parentSelectorName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function descendantNodeJoinCondition($descendantSelectorName, $ancestorSelectorName): DescendantNodeJoinConditionInterface
    {
        return new DescendantNodeJoinCondition($descendantSelectorName, $ancestorSelectorName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function andConstraint(ConstraintInterface $constraint1, ConstraintInterface $constraint2): AndInterface
    {
        return new AndConstraint($constraint1, $constraint2);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function orConstraint(ConstraintInterface $constraint1, ConstraintInterface $constraint2): OrInterface
    {
        return new OrConstraint($constraint1, $constraint2);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function notConstraint(ConstraintInterface $constraint): NotInterface
    {
        return new NotConstraint($constraint);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function comparison(DynamicOperandInterface $operand1, $operator, StaticOperandInterface $operand2): ComparisonInterface
    {
        return new ComparisonConstraint($operand1, $operator, $operand2);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function propertyExistence($selectorName, $propertyName): PropertyExistenceInterface
    {
        return new PropertyExistence($selectorName, $propertyName);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     *
     * @api
     */
    public function fullTextSearch($selectorName, $propertyName, $fullTextSearchExpression): FullTextSearchInterface
    {
        return new FullTextSearchConstraint($selectorName, $propertyName, $fullTextSearchExpression);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function sameNode($selectorName, $path): SameNodeInterface
    {
        return new SameNode($selectorName, $path);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function childNode($selectorName, $path): ChildNodeInterface
    {
        return new ChildNodeConstraint($selectorName, $path);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function descendantNode($selectorName, $path): DescendantNodeInterface
    {
        return new DescendantNodeConstraint($selectorName, $path);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     *
     * @api
     */
    public function propertyValue($selectorName, $propertyName): PropertyValueInterface
    {
        return new PropertyValue($selectorName, $propertyName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function length(PropertyValueInterface $propertyValue): LengthInterface
    {
        return new Length($propertyValue);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     *
     * @api
     */
    public function nodeName($selectorName): NodeNameInterface
    {
        return new NodeName($selectorName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function nodeLocalName($selectorName): NodeLocalNameInterface
    {
        return new NodeLocalName($selectorName);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     *
     * @api
     */
    public function fullTextSearchScore($selectorName): FullTextSearchScoreInterface
    {
        return new FullTextSearchScore($selectorName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function lowerCase(DynamicOperandInterface $operand): LowerCaseInterface
    {
        return new LowerCase($operand);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function upperCase(DynamicOperandInterface $operand): UpperCaseInterface
    {
        return new UpperCase($operand);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function bindVariable($bindVariableName): BindVariableValueInterface
    {
        return new BindVariableValue($bindVariableName);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function literal($literalValue): LiteralInterface
    {
        return new Literal($literalValue);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function ascending(DynamicOperandInterface $operand): OrderingInterface
    {
        return new Ordering($operand, QueryObjectModelConstantsInterface::JCR_ORDER_ASCENDING);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function descending(DynamicOperandInterface $operand): OrderingInterface
    {
        return new Ordering($operand, QueryObjectModelConstantsInterface::JCR_ORDER_DESCENDING);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     *
     * @api
     */
    public function column($selectorName, $propertyName = null, $columnName = null): ColumnInterface
    {
        return new Column($selectorName, $propertyName, $columnName);
    }
}
