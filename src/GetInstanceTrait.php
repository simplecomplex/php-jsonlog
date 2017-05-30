<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

/**
 * Provides static getInstance() method for reusing instance(s).
 *
 * @package SimpleComplex\JsonLog
 */
trait GetInstanceTrait
{
    /**
     * Reference to last instantiated instance, of a class.
     *
     * Keeping the instance(s) in list by class name secures that parent/child
     * class' getInstance() returns instance of the class getInstance() is
     * called on (not just any last instance).
     *
     * @var array {
     *      @var Class $className
     * }
     */
    protected static $instanceByClass = [];

    /**
     * Get previously instantiated object or create new.
     *
     * @param mixed ...$constructorParams
     *
     * @return static
     */
    public static function getInstance(...$constructorParams)
    {
        $class = get_called_class();
        if (isset(static::$instanceByClass[$class])) {
            return static::$instanceByClass[$class];
        }

        static::$instanceByClass[$class] = $nstnc = new static(...$constructorParams);

        return $nstnc;
    }
}
