<?php

namespace Jackalope\Query\QOM;

use PHPCR\Query\QOM\LengthInterface;
use PHPCR\Query\QOM\PropertyValueInterface;

/**
 * {@inheritDoc}
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class Length implements LengthInterface
{
    /**
     * @var PropertyValueInterface
     */
    protected $propertyValue;

    /**
     * Create a new length information.
     */
    public function __construct(PropertyValueInterface $propertyValue)
    {
        $this->propertyValue = $propertyValue;
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getPropertyValue()
    {
        return $this->propertyValue;
    }
}
