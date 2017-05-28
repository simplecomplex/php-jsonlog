<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

/**
 * Provide class method for reusing instance(s).
 *
 * See GetInstanceTrait for in-depth description.
 *
 * @see GetInstanceTrait
 *
 * @package SimpleComplex\JsonLog
 */
interface GetInstanceInterface
{
    /**
     * Get previously instantiated object or create new.
     *
     * @param string $name
     * @param array $constructorArgs
     *
     * @return static
     */
    public static function getInstance($name = '', $constructorArgs = []);

    /**
     * Kill class reference(s) to instance(s).
     *
     * @param string $name
     *      Unrefer instance by that name, if exists.
     * @param bool $last
     *      Kill reference to last instantiated object.
     * @return void
     */
    public static function flushInstance($name = '', $last = false);
}
