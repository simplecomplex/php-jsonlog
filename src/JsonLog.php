<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

use Psr\Log\AbstractLogger;


/**
 * PSR-3 logger which files events as JSON.
 *
 * @see \SimpleComplex\JsonLog\JsonLogEvent
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLog extends AbstractLogger
{

    // Psr\Log\AbstractLogger members.

    /**
     * Logs if level is equal to or more severe than a threshold.
     *
     * @see JsonLogEvent
     * @see JsonLogEvent::THRESHOLD_DEFAULT
     *
     * @throws \Psr\Log\InvalidArgumentException
     *   Propagated; invalid level argument.
     *
     * @param mixed $level
     *   String (word): value as defined by Psr\Log\LogLevel class constants.
     *   Integer|stringed integer: between zero and seven; RFC 5424.
     * @param string $message
     *   Placeholder {word}s must correspond to keys in the context argument.
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = array()) : void
    {
        $event_class = static::CLASS_JSON_LOG_EVENT;
        /**
         * @var JsonLogEvent $event
         */
        $event = new $event_class($level, $message, $context);

        if ($event->severe()) {
            $event->commit(
                $event->get()
            );
        }
    }


    // Custom members.

    /**
     * Class name of \SimpleComplex\JsonLog\JsonLogEvent or extending class.
     *
     * @code
     * // Overriding class must use fully qualified (namespaced) class name.
     * const CLASS_JSON_LOG_EVENT = \Package\Library\CustomJsonLogEvent::class;
     * @endcode
     *
     * @see \SimpleComplex\JsonLog\JsonLogEvent
     *
     * @var string
     */
    const CLASS_JSON_LOG_EVENT = JsonLogEvent::class;

    /**
     * @see \SimpleComplex\JsonLog\JsonLogEvent::committable()
     *
     * @param bool $enable
     * @param bool $getResponse
     *
     * @return boolean|array
     */
    public function committable($enable = false, $getResponse = false)
    {
        $event_class = static::CLASS_JSON_LOG_EVENT;
        /**
         * @var JsonLogEvent $event
         */
        $event = new $event_class(LOG_DEBUG, 'Committable?');

        return $event->committable($enable, $getResponse);
    }
}
