<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

/**
 * Provides static class vars and methods for reusing instance(s).
 *
 * Beware of child classes and same-named/unnammed instances
 * ---------------------------------------------------------
 * Instances of parent and child classes are inevitably kept in the same class
 * vars. So the first call to getInstance() will determine what's returned on
 * all later calls (using same name argument) - no matter what class used on the
 * later calls.
 * And including (using) this trait in child class is no fix; fatal error when
 * calling getInstance() on child class before calling it on parent class.
 * Using self in getInstance() couldn't change this behaviour either.
 * Workaround: do use named - that is, differently named - instances.
 *
 * @see GetInstanceInterface
 *
 * @package SimpleComplex\JsonLog
 */
trait GetInstanceTrait
{
    /**
     * List of previously instantiated objects, by name.
     *
     * @var array
     */
    protected static $instances = array();

    /**
     * Reference to last instantiated instance.
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
     * @var static
     */
    protected static $lastInstance;

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
        if ($name) {
            if (isset(static::$instances[$name])) {
                return static::$instances[$name];
            }
        } elseif (static::$lastInstance) {
            return static::$lastInstance;
        }

        static::$lastInstance = $nstnc = new static(...$constructorArgs);

        if ($name) {
            static::$instances[$name] = $nstnc;
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
        if ($name) {
            unset(static::$instances[$name]);
        }
        if ($last) {
            static::$lastInstance = null;
        }
    }
}
