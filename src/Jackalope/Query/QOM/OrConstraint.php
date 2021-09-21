<?php

namespace Jackalope\Query\QOM;

use PHPCR\Query\QOM\ConstraintInterface;
use PHPCR\Query\QOM\OrInterface;

/**
 * {@inheritDoc}
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class OrConstraint implements OrInterface
{
    /**
     * @var ConstraintInterface
     */
    protected $constraint1;

    /**
     * @var ConstraintInterface
     */
    protected $constraint2;

    /**
     * Constructor.
     */
    public function __construct(ConstraintInterface $constraint1, ConstraintInterface $constraint2)
    {
        $this->constraint1 = $constraint1;
        $this->constraint2 = $constraint2;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getConstraint1()
    {
        return $this->constraint1;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getConstraint2()
    {
        return $this->constraint2;
    }

    /**
     * Gets all constraints including itself.
     *
     * @return array the constraints
     *
     * @api
     */
    public function getConstraints()
    {
        $constraints = array_merge($this->getConstraint1()->getConstraints(), $this->getConstraint2()->getConstraints());
        $constraints[] = $this;

        return $constraints;
    }
}
