<?php

namespace Zxing\Common;

use \Zxing\NotFoundException;
use ReflectionClass;

/**
 * A general enum implementation until we got SplEnum.
 */
final class AbstractEnum
{
    /**
     * Default value.
     */
    const __default = null;
    /**
     * Current value.
     *
     * @var mixed
     */
    protected $value;
    /**
     * Cache of constants.
     *
     * @var array
     */
    protected $constants;
    /**
     * Whether to handle values strict or not.
     *
     * @var boolean
     */
    protected $strict;

    /**
     * Creates a new enum.
     *
     * @param mixed   $initialValue
     * @param boolean $strict
     */
    public function __construct($initialValue = null, $strict = false)
    {
        $this->strict = $strict;
        $this->change($initialValue);
    }

    /**
     * Changes the value of the enum.
     *
     * @param  mixed $value
     *
     * @return void
     */
    public function change($value)
    {
        if (!in_array($value, $this->getConstList(), $this->strict)) {
            throw new \UnexpectedValueException('Value not a const in enum ' . get_class($this));
        }
        $this->value = $value;
    }

    /**
     * Gets all constants (possible values) as an array.
     *
     * @param  boolean $includeDefault
     *
     * @return array
     */
    public function getConstList($includeDefault = true)
    {
        if ($this->constants === null) {
            $reflection      = new ReflectionClass($this);
            $this->constants = $reflection->getConstants();
        }
        if ($includeDefault) {
            return $this->constants;
        }
        $constants = $this->constants;
        unset($constants['__default']);

        return $constants;
    }

    /**
     * Gets current value.
     *
     * @return mixed
     */
    public function get()
    {
        return $this->value;
    }

    /**
     * Gets the name of the enum.
     *
     * @return string
     */
    public function __toString()
    {
        return (string)array_search($this->value, $this->getConstList());
    }
}
