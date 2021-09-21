<?php

namespace Jackalope\Query\QOM;

use InvalidArgumentException;
use Jackalope\FactoryInterface;
use Jackalope\NotImplementedException;
use Jackalope\ObjectManager;
use Jackalope\Query\Sql1Query;
use PHPCR\Query\QOM\ColumnInterface;
use PHPCR\Query\QOM\ConstraintInterface;
use PHPCR\Query\QOM\OrderingInterface;
use PHPCR\Query\QOM\QueryObjectModelInterface;
use PHPCR\Query\QOM\SourceInterface;
use PHPCR\Util\QOM\QomToSql1QueryConverter;
use PHPCR\Util\QOM\Sql1Generator;
use PHPCR\Util\ValueConverter;

/**
 * {@inheritDoc}
 *
 * We extend SqlQuery to have features like limit and offset.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class QueryObjectModelSql1 extends Sql1Query implements QueryObjectModelInterface
{
    /**
     * @var SourceInterface
     */
    protected $source;

    /**
     * @var ConstraintInterface
     */
    protected $constraint;

    /**
     * @var array
     */
    protected $orderings;

    /**
     * @var array
     */
    protected $columns;

    /**
     * Constructor.
     *
     * @param FactoryInterface    $factory       the object factory
     * @param ObjectManager       $objectManager (can be omitted if you do not want
     *                                           to execute the query but just use it with a parser)
     * @param ConstraintInterface $constraint
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        FactoryInterface $factory,
        ObjectManager $objectManager = null,
        SourceInterface $source,
        ConstraintInterface $constraint = null,
        array $orderings,
        array $columns
    ) {
        foreach ($orderings as $o) {
            if (!$o instanceof OrderingInterface) {
                throw new InvalidArgumentException('Not a valid ordering: '.$o);
            }
        }
        foreach ($columns as $c) {
            if (!$c instanceof ColumnInterface) {
                throw new InvalidArgumentException('Not a valid column: '.$c);
            }
        }
        parent::__construct($factory, '', $objectManager);
        $this->source = $source;
        $this->constraint = $constraint;
        $this->orderings = $orderings;
        $this->columns = $columns;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getConstraint()
    {
        return $this->constraint;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getOrderings()
    {
        return $this->orderings;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getBindVariableNames()
    {
        // TODO: can we inherit from SqlQuery?
        throw new NotImplementedException();
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getStatement()
    {
        $valueConverter = $this->factory->get(ValueConverter::class);
        $converter = new QomToSql1QueryConverter(new Sql1Generator($valueConverter));

        return $converter->convert($this);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getLanguage()
    {
        return self::SQL;
    }
}
