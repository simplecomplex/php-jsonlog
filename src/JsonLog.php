<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

use Psr\Log\AbstractLogger;
use Psr\SimpleCache\CacheInterface;
use SimpleComplex\Filter\Unicode;
use SimpleComplex\Filter\Sanitize;


// @todo: move Unicode, Sanitize, Cli and GetInstanceInterface+GetInstanceTrait to SimpleComplex\Utils package.
// @todo: take the GetInstanceTrait declared in JsonLog (has best documentation).

// @todo: Unicode and Sanitize shan't be passed about as (overridable) dependencies via constructor args
// @todo: - use getInstance() in libs using these classes.
// @todo: And then use setLogger() in bootstrapper if you want to provide a logger to these (Unicode and Sanitize) instances
// @todo: ...after instantiation of the logger.

// @todo: Config should have the same - primary - priority as the logger, and then use config->setLogger().
// @todo: priority: 1 logger, 2 config.

// @todo: Ask operations which PHP version they intend to support 7.0 or 7.1.



/**
 * PSR-3 logger which files events as JSON.
 *
 * Proxy class for actual logger instances of JsonLogEvent.
 *
 * @see \SimpleComplex\JsonLog\JsonLogEvent
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLog extends AbstractLogger implements GetInstanceInterface
{

    // Psr\Log\AbstractLogger members.

    /**
     * Logs if level is equal to or more severe than a threshold.
     *
     * @see JsonLogEvent
     * @see JsonLogEvent::THRESHOLD_DEFAULT
     *
     * @throws \Psr\Log\InvalidArgumentException
     *      Propagated; invalid level argument.
     *
     * @param mixed $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     * @param string $message
     *      Placeholder {word}s must correspond to keys in the context argument.
     * @param array $context
     *
     * @return void
     */
    public function log($level, $message, array $context = [])
    {
        $event_class = static::CLASS_JSON_LOG_EVENT;
        /**
         * @var JsonLogEvent $event
         */
        $event = new $event_class(
            [
                'config' => $this->config,
                'unicode' => $this->unicode,
                'sanitize' => $this->sanitize,
            ],
            $level,
            $message,
            $context
        );

        if ($event->severe()) {
            // Append to log file.
            $event->commit(
                // To JSON.
                $event->format(
                    // Compose.
                    $event->get()
                )
            );
        }
    }


    // GetInstanceInterface implementation.

    /**
     * @see GetInstanceTrait
     *
     * List of previously instantiated objects, by name.
     * @protected
     * @static
     * @var array $instances
     *
     * Reference to last instantiated instance.
     * @protected
     * @static
     * @var static $lastInstance
     *
     * Get previously instantiated object or create new.
     * @public
     * @static
     * @see GetInstanceTrait::getInstance()
     *
     * Kill class reference(s) to instance(s).
     * @public
     * @static
     * @see GetInstanceTrait::flushInstance()
     */
    use GetInstanceTrait;
    //use \SimpleComplex\Filter\GetInstanceTrait;


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
     * Class name of \SimpleComplex\Filter\Unicode or extending class.
     *
     * @var string
     */
    const CLASS_UNICODE = Unicode::class;

    /**
     * Class name of \SimpleComplex\Filter\Sanitize or extending class.
     *
     * @var string
     */
    const CLASS_SANITIZE = Sanitize::class;

    /**
     * @var CacheInterface|null
     */
    protected $config;

    /**
     * @var Unicode
     */
    protected $unicode;

    /**
     * @var Sanitize
     */
    protected $sanitize;

    /**
     * Checks and resolves all dependencies, whereas JsonLogEvent use them unchecked.
     *
     * @see JsonLog::setConfig()
     *
     * @param CacheInterface|null $config
     *      PSR-16 based configuration instance, if any.
     */
    public function __construct($config = null)
    {
        $this->config = $config;

        $this->unicode = static::CLASS_UNICODE == Unicode::class ? Unicode::getInstance() :
            forward_static_call(static::CLASS_UNICODE . '::getInstance');
        $this->sanitize = static::CLASS_SANITIZE == Sanitize::class ? Sanitize::getInstance() :
            forward_static_call(static::CLASS_SANITIZE . '::getInstance');
    }

    /**
     * Overcome mutual dependency, provide a config object after instantiation.
     *
     * This class does not need a config object, if configuration is based on
     * environment vars, or if defaults are adequate for current system.
     *
     * @param CacheInterface $config
     *
     * @return void
     */
    public function setConfig(CacheInterface $config)
    {
        $this->config = $config;
    }

    /**
     * Check/enable JsonLogEvent to write logs.
     *
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
        $event = new $event_class(
            [
                'config' => $this->config,
                'unicode' => $this->unicode,
                'sanitize' => $this->sanitize,
            ],
            LOG_DEBUG,
            'Committable?'
        );

        return $event->committable($enable, $getResponse);
    }

    /**
     * Access JsonLogEvent's configuration.
     *
     * Mainly for test/debug purposes, not efficient performance-wise.
     *
     * @param string $action
     *      Values: set|get.
     * @param string $name
     * @param mixed $value
     *      Ignored if action is get.
     *
     * @return bool|mixed
     */
    public function config($action, $name, $value = null) {
        $event_class = static::CLASS_JSON_LOG_EVENT;
        /**
         * @var JsonLogEvent $event
         */
        $event = new $event_class(
            [
                'config' => $this->config,
                'unicode' => $this->unicode,
                'sanitize' => $this->sanitize,
            ],
            LOG_DEBUG,
            ''
        );
        if ($action == 'set') {
            return $event->configSet('', $name, $value);
        }
        return $event->configGet('', $name);
    }
}
