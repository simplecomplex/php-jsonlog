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
use SimpleComplex\Utils\Interfaces\SectionedMapInterface;
use SimpleComplex\Utils\SectionedMap;
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
 * @dependency-injection-container logger
 *      Suggested ID of the JsonLog instance.
 *
 * @see \SimpleComplex\JsonLog\JsonLogEvent
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLog extends AbstractLogger
{

    // Psr\Log\AbstractLogger members.

    /**
     * Logs event if level is equal to or more severe than a threshold.
     *
     * @see JsonLogEvent
     * @see JsonLogEvent::THRESHOLD_DEFAULT
     * @see \Psr\Log\LogLevel
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
    public function log($level, $message, array $context = [])/*: void*/
    {
        // Init.----------------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->config) {
            // Use enviroment variable wrapper config class if exists;
            // fall back on empty sectioned map.
            if (class_exists('\\SimpleComplex\\Config\\EnvSectionedConfig')) {
                $this->config = call_user_func('\\SimpleComplex\\Config\\EnvSectionedConfig::getInstance');
            } else {
                $this->config = new SectionedMap();
            }
        }


        // Business.------------------------------------------------------------

        // Sufficiently severe to log?
        $severity = Utils::getInstance()->logLevelToInteger($level);

        // Prime sectioned config; load the whole section into memory.
        $this->config->remember(static::CONFIG_SECTION);

        // Less is more.
        if ($severity > $this->config->get(static::CONFIG_SECTION, 'threshold', static::THRESHOLD_DEFAULT)) {
            // Relieve config memory.
            $this->config->forget(static::CONFIG_SECTION);
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
                $event->get(),
                '' . $this->config->get(static::CONFIG_SECTION, 'format', 'default')
            )
        );
        // Relieve config memory.
        $this->config->forget(static::CONFIG_SECTION);
    }

    /**
     * Composes event, disregarding severity threshold.
     *
     * @see JsonLogEvent
     * @see JsonLogEvent::THRESHOLD_DEFAULT
     * @see \Psr\Log\LogLevel
     *
     * @param mixed $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     * @param string $message
     *      Placeholder {word}s must correspond to keys in the context argument.
     * @param array $context
     *
     * @return array
     *
     * @throws \Psr\Log\InvalidArgumentException
     *      Propagated; invalid level argument.
     */
    public function compose($level, $message, array $context = []) : array
    {
        // Init.----------------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->config) {
            // Use enviroment variable wrapper config class if exists;
            // fall back on empty sectioned map.
            if (class_exists('\\SimpleComplex\\Config\\EnvSectionedConfig')) {
                $this->config = call_user_func('\\SimpleComplex\\Config\\EnvSectionedConfig::getInstance');
            } else {
                $this->config = new SectionedMap();
            }
        }
        if (!$this->unicode) {
            $this->unicode = Unicode::getInstance();
        }
        if (!$this->sanitize) {
            $this->sanitize = Sanitize::getInstance();
        }


        // Business.------------------------------------------------------------

        // Prime sectioned config; load the whole section into memory.
        $this->config->remember(static::CONFIG_SECTION);

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
        $list = $event->get();
        // Relieve config memory.
        $this->config->forget(static::CONFIG_SECTION);

        return $list;
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
     * @deprecated Use a dependency injection container instead.
     * @see \SimpleComplex\Utils\Dependency
     * @see \Slim\Container
     *
     * @param mixed ...$constructorParams
     *
     * @return JsonLog
     *      static, really, but IDE might not resolve that.
     */
    public static function getInstance(...$constructorParams)
    {
        // Unsure about null ternary ?? for class and instance vars.
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
     *  - (int) truncate:   32 (TRUNCATE_DEFAULT)
     *  - (str) siteid:     a dir name in document root, or 'unknown'
     *  - (str) type:       'webapp' (TYPE_DEFAULT)
     *  - (str) path:       default webserver log dir + /jsonlog
     *  - (str) file_time:  'Ymd'; date() pattern or empty or 'none'
     *  - (str) canonical:  empty
     *  - (str) tags:       empty; comma-separated list
     *  - (str) reverse_proxy_addresses:    empty; comma-separated list
     *  - (str) reverse_proxy_header:       HTTP_X_FORWARDED_FOR
     *  - (str) format:     default|pretty|prettier
     *
     * See also ../config-ini/json-log.ini
     *
     * @var SectionedMapInterface
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
     * @see \SimpleComplex\Utils\Interfaces\SectionedMapInterface
     * @see \SimpleComplex\Config\Interfaces\SectionedConfigInterface
     * @see \SimpleComplex\Config\EnvSectionedConfig
     *
     * @param SectionedMapInterface|object|array|null $config
     *      Non-SectionedMapInterface object|array: will be used
     *          as JsonLog specific settings.
     *      Null: instance will on demand use
     *          \SimpleComplex\Config\EnvSectionedConfig, if exists.
     */
    public function __construct($config = null)
    {
        // Dependencies.--------------------------------------------------------
        // Extending class' constructor might provide configuration
        // by other means.
        if (!$this->config && isset($config)) {
            if ($config instanceof SectionedMapInterface) {
                $this->config = $config;
            } else {
                $this->config = (new SectionedMap())->setSection(static::CONFIG_SECTION, $config);
            }
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
     * @deprecated
     *      This method will be removed; doesn't solve anything in terms
     *      of mutual dependency, and there hardly is any such issue anyway.
     *
     * @param SectionedMapInterface $config
     *
     * @return void
     */
    public function setConfig(SectionedMapInterface $config)/*: void*/
    {
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
     * # CLI
     * cd vendor/simplecomplex/json-log/src/cli
     * php cli.phpsh json-log -h
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
            // Use enviroment variable wrapper config class if exists;
            // fall back on empty sectioned map.
            if (class_exists('\\SimpleComplex\\Config\\EnvSectionedConfig')) {
                $this->config = call_user_func('\\SimpleComplex\\Config\\EnvSectionedConfig::getInstance');
            } else {
                $this->config = new SectionedMap();
            }
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
                        $event->get(),
                        '' . $this->config->get(static::CONFIG_SECTION, 'format', 'default')
                    )
                );
                if ($getResponse) {
                    $result['message'] .= "\n" . 'Committed dummy event.';
                }
            }
        }

        return $result;
    }

    /**
     * Truncate current log file.
     *
     * Only allowed in CLI mode.
     * @see CliJsonLog
     *
     * @see JsonLogEvent::truncate()
     *
     * @code
     * # CLI
     * cd vendor/simplecomplex/json-log/src/cli
     * php cli.phpsh json-log -h
     * @endcode
     *
     * @return string
     *      Non-empty: path+filename; succeeded.
     *      Empty: failed.
     */
    public function truncate(): string
    {
        // Init.----------------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->config) {
            // Use enviroment variable wrapper config class if exists;
            // fall back on empty sectioned map.
            if (class_exists('\\SimpleComplex\\Config\\EnvSectionedConfig')) {
                $this->config = call_user_func('\\SimpleComplex\\Config\\EnvSectionedConfig::getInstance');
            } else {
                $this->config = new SectionedMap();
            }
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
            'truncate'
        );

        return $event->truncate();
    }
}
