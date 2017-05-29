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
     * List of previously instantiated objects, by class, and eventually by name.
     *
     * @var array {
     *      @var array $parentClassName {
     *          @var ParentClass $someName
     *          @var ParentClass $otherName
     *      }
     *      @var array $childClassName {
     *          @var ChildClass $someName
     *      }
     * }
     */
    protected static $instancesByClass = [];

    /**
     * Reference to last instantiated instance, of a class.
     *
     * That is: if that instance was instantiated via getInstance(),
     * or if constructor passes it's $this to this var.
     *
     * Whether constructor sets/updates this var is optional.
     * Referring an instance - that may never be used again - may well be
     * unnecessary overhead.
     * On the other hand: if the class/instance is used as a singleton, and the
     * current dependency injection pattern doesn't support calling getInstance(),
     * then constructor _should_ set/update this var.
     *
     * @var array {
     *      @var Class $className
     * }
     */
    protected static $lastInstanceByClass = [];

    /**
     * Get previously instantiated object or create new.
     *
     * @code
     * // Get/create specific instance.
     * $instance = Class::getInstance('myInstance', [
     *   $someLogger,
     * ]);
     * // Get specific instance, expecting it was created earlier (say:bootstrap).
     * $instance = Class::getInstance('myInstance');
     * // Get/create any instance, supplying constructor args.
     * $instance = Class::getInstance('', [
     *   $someLogger,
     * ]);
     * // Get/create any instance, expecting constructor arg defaults to work.
     * $instance = Class::getInstance();
     * @endcode
     *
     * @param string $name
     * @param array $constructorArgs
     *
     * @return static
     */
    public static function getInstance($name = '', $constructorArgs = [])
    {
        $class = get_called_class();
        if ($name) {
            if (isset(static::$instancesByClass[$class][$name])) {
                return static::$instancesByClass[$class][$name];
            }
        } elseif (isset(static::$lastInstanceByClass[$class])) {
            return static::$lastInstanceByClass[$class];
        }

        static::$lastInstanceByClass[$class] = $nstnc = new static(...$constructorArgs);

        if ($name) {
            static::$instancesByClass[$class][$name] = $nstnc;
        }
        return $nstnc;
    }

    /**
     * Kill class reference(s) to instance(s).
     *
     * @param string $name
     *      Unrefer instance by that name, if exists.
     * @param bool $last
     *      Kill reference to last instantiated object.
     *
     * @return void
     */
    public static function flushInstance($name = '', $last = false)
    {
        $class = get_called_class();
        if ($name) {
            unset(static::$instancesByClass[$class][$name]);
        }
        if ($last) {
            static::$lastInstanceByClass[$class] = null;
        }
    }
}
