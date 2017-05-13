<?php

namespace SimpleComplex\JsonLog;

use Psr\Log\AbstractLogger;


/**
 * PSR-3 logger which files events as JSON.
 *
 * Preferably do extend JsonLogEvent, not JsonLog itself.
 *
 * @see \SimpleComplex\JsonLog\JsonLogEvent
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLog extends AbstractLogger {

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
  public function log($level, $message, array $context = array()) {
    /**
     * @var JsonLogEvent $event
     */
    $event = new $this->eventClass($level, $message, $context);

    echo constant($this->eventClass . '::THRESHOLD_DEFAULT') . "\n";


    if ($event->severe()) {
      $event->submit(
        $event->get()
      );
    }
  }


  // Custom members.

  /**
   * Class name of \SimpleComplex\JsonLog\JsonLogEvent or extending class.
   *
   * @var string
   */
  protected $eventClass = '';

  /**
   * Dependency injection by class instead of instance, because it doesn't
   * make sense to instantiate a (single) log event prior to logging anything
   * at all.
   *
   * @param string $eventClass
   *   Class name of \SimpleComplex\JsonLog\JsonLogEvent or extending class.
   */
  public function __construct($eventClass = JsonLogEvent::class) {
    $this->eventClass = $eventClass;
  }
}
