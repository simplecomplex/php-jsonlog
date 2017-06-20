<?php
/**
 * SimpleComplex PHP JsonLog
 * @link      https://github.com/simplecomplex/php-jsonlog
 * @copyright Copyright (c) 2014-2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-jsonlog/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\JsonLog;

use Psr\Log\AbstractLogger;
use SimpleComplex\Config\SectionedConfigInterface;
use SimpleComplex\Config\EnvSectionedConfig;
use SimpleComplex\Utils\Utils;
use SimpleComplex\Utils\Unicode;
use SimpleComplex\Utils\Sanitize;

/**
 * PSR-3 logger which files events as JSON.
 *
 * Proxy class for actual logger instances of JsonLogEvent.
 *
 * Intended as singleton - ::getInstance() - but constructor not protected.
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
     * @param mixed $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     * @param string $message
     *      Placeholder {word}s must correspond to keys in the context argument.
     * @param array $context
     *
     * @return void
     *
     * @throws \Psr\Log\InvalidArgumentException
     *      Propagated; invalid level argument.
     */
    public function log($level, $message, array $context = []) /*: void*/
    {
        // Init.----------------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->config) {
            $this->setConfig(EnvSectionedConfig::getInstance());
        }


        // Business.------------------------------------------------------------

        // Sufficiently severe to log?
        $severity = Utils::getInstance()->logLevelToInteger($level);

        if ($this->threshold == -1) {
            $this->threshold = (int) $this->config->get(static::CONFIG_SECTION, 'threshold', static::THRESHOLD_DEFAULT);
        }
        // Less is more.
        if ($severity > $this->threshold) {
            return;
        }


        // More init.-----------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->unicode) {
            $this->unicode = Unicode::getInstance();
        }
        if (!$this->sanitize) {
            $this->sanitize = Sanitize::getInstance();
        }


        // Business.------------------------------------------------------------

        $event_class = static::CLASS_JSON_LOG_EVENT;
        /**
         * @var JsonLogEvent $event
         */
        $event = new $event_class(
            $this,
            // LogLevel word.
            Utils::getInstance()->logLevelToString($level),
            $message,
            $context
        );

        // Append to log file.
        $event->commit(
            // To JSON.
            $event->format(
                // Compose.
                $event->get()
            )
        );
    }


    // Custom members.

    /**
     * Reference to first object instantiated via the getInstance() method,
     * no matter which parent/child class the method was/is called on.
     *
     * @var JsonLog
     */
    protected static $instance;

    /**
     * First object instantiated via this method, disregarding class called on.
     *
     * @param mixed ...$constructorParams
     *
     * @return JsonLog
     *      static, really, but IDE might not resolve that.
     */
    public static function getInstance(...$constructorParams)
    {
        if (!static::$instance) {
            static::$instance = new static(...$constructorParams);
        }
        return static::$instance;
    }

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
     * Config vars, and their effective defaults:
     *  - (int) threshold:  warning (THRESHOLD_DEFAULT)
     *  - (int) truncate:   64 (TRUNCATE_DEFAULT)
     *  - (str) siteid:     a dir name in document root, or 'unknown'
     *  - (str) type:       'webapp' (TYPE_DEFAULT)
     *  - (str) path:       default webserver log dir + /jsonlog
     *  - (str) file_time:  'Ymd'; date() pattern
     *  - (str) canonical:  empty
     *  - (str) tags:       empty; comma-separated list
     *  - (str) reverse_proxy_addresses:    empty; comma-separated list
     *  - (str) reverse_proxy_header:       HTTP_X_FORWARDED_FOR
     *  - (bool|int) keep_enclosing_tag @todo: remove(?)
     *
     * @var SectionedConfigInterface
     */
    public $config;

    /**
     * @var Unicode
     */
    public $unicode;

    /**
     * @var Sanitize
     */
    public $sanitize;

    /**
     * Lightweight instantiation - dependencies are secured on demand,
     * not by constructor.
     *
     * Logging methods - and committable() - will use
     * SimpleComplex\Config\EnvSectionedConfig as fallback, if no config object
     * passed to constructor and no subsequent call to setConfig().
     *
     * @see JsonLog::setConfig()
     * @see \SimpleComplex\Config\EnvSectionedConfig
     *
     * @param SectionedConfigInterface|null $config
     *      Uses/instantiates SimpleComplex\Config\EnvSectionedConfig
     *      _on demand_, as fallback.
     */
    public function __construct(/*?SectionedConfigInterface*/ $config = null)
    {
        // Dependencies.--------------------------------------------------------
        // Extending class' constructor might provide instances by other means.
        if (!$this->config && isset($config)) {
            $this->setConfig($config);
        }

        // Business.------------------------------------------------------------
        // None.
    }

    /**
     * Config var default section.
     *
     * @var string
     */
    const CONFIG_SECTION = 'lib_simplecomplex_jsonlog';

    /**
     * Less severe (higher valued) events will not be logged.
     *
     * Overridable by 'threshold' conf var.
     *
     * @var int
     */
    const THRESHOLD_DEFAULT = LOG_WARNING;

    /**
     * Record configured severity threshold across events of a request.
     *
     * Set at every call to setConfig().
     *
     * @var integer
     */
    protected $threshold = -1;

    /**
     * Overcome mutual dependency, provide a config object after instantiation.
     *
     * This class does not need a config object, if configuration is based on
     * environment vars, or if defaults are adequate for current system.
     *
     * @param SectionedConfigInterface $config
     *
     * @return void
     */
    public function setConfig(SectionedConfigInterface $config) /*: void*/
    {
        // Reset cross event vars, if shifting to a new configuration.
        if ($this->config) {
            $this->threshold = -1;
        }

        $this->config = $config;
    }

    /**
     * Check/enable JsonLog to write logs.
     *
     * Also available as CLI command.
     * @see CliJsonLog
     *
     * @see JsonLogEvent::committable()
     *
     * @code
     * # In CLI mode: Is JsonLog ready?
     * cd vendor/simplecomplex/json-log/src/cli
     * # Execute 'committable' command.
     * php JsonLogCli.phpsh committable --enable --commit --verbose
     * @endcode
     *
     * @param bool $enable
     * @param bool $commitOnSuccess
     * @param bool $getResponse
     *
     * @return boolean|array
     */
    public function committable($enable = false, $commitOnSuccess = false, $getResponse = false)
    {
        // Init.----------------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->config) {
            $this->setConfig(EnvSectionedConfig::getInstance());
        }
        if (!$this->unicode) {
            $this->unicode = Unicode::getInstance();
        }
        if (!$this->sanitize) {
            $this->sanitize = Sanitize::getInstance();
        }

        // Business.------------------------------------------------------------

        $event_class = static::CLASS_JSON_LOG_EVENT;
        /**
         * @var JsonLogEvent $event
         */
        $event = new $event_class(
            $this,
            Utils::getInstance()->logLevelToString(LOG_DEBUG),
            'Committable? Is this {cake} bakeable?',
            [
                'cake' => 'apple pie',
                'subType' => get_class($this),
                'code' => 7913,
            ]
        );

        $result = $event->committable($enable, $getResponse);
        if ($commitOnSuccess) {
            if (
                (!$getResponse && $result)
                || ($getResponse && $result['success'])
            ) {
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

        return $result;
    }
}
