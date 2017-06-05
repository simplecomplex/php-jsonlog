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
use Psr\Log\LogLevel;
use Psr\Log\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use SimpleComplex\Utils\Traits\GetInstanceOfFamilyTrait;
use SimpleComplex\Utils\EnvVarConfig;
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
    public function log($level, $message, array $context = []) /*: void*/
    {
        // Init.----------------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->config) {
            $this->setConfig(EnvVarConfig::getInstance());
        }
        if (!$this->unicode) {
            $this->unicode = Unicode::getInstance();
        }
        if (!$this->sanitize) {
            $this->sanitize = Sanitize::getInstance();
        }


        // Business.------------------------------------------------------------

        // Sufficiently severe to log?
        $severity = $this->levelToInteger($level);

        if ($this->threshold == -1) {
            $this->threshold = (int) $this->config->get($this->configDomain . 'threshold', static::THRESHOLD_DEFAULT);
        }
        // Less is more.
        if ($severity > $this->threshold) {
            return;
        }

        $event_class = static::CLASS_JSON_LOG_EVENT;
        /**
         * @var JsonLogEvent $event
         */
        $event = new $event_class(
            $this,
            // LogLevel word.
            static::levelToString($level),
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
     * @see \SimpleComplex\Utils\Traits\GetInstanceOfFamilyTrait
     *
     * First object instantiated via this method, disregarding class called on.
     * @public
     * @static
     * @see \SimpleComplex\Utils\Traits\GetInstanceOfFamilyTrait::getInstance()
     */
    use GetInstanceOfFamilyTrait;

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
     * @var CacheInterface
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
     * @var string
     */
    public $configDomain;

    /**
     * Lightweight instantiation - dependencies are secured on demand,
     * not by constructor.
     *
     * Logging methods - and committable() - will use
     * SimpleComplex\Utils\EnvVarConfig as fallback, if no config object
     * passed to constructor and no subsequent call to setConfig().
     *
     * @see JsonLog::setConfig()
     * @see \SimpleComplex\Utils\EnvVarConfig
     *
     * @param CacheInterface|null $config
     *      PSR-16 based configuration instance.
     *      Uses/instantiates SimpleComplex\Utils\EnvVarConfig _on demand_,
     *      as fallback.
     */
    public function __construct(/*?CacheInterface*/ $config = null)
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
     * Conf var default namespace.
     *
     * @var string
     */
    const CONFIG_DOMAIN = 'lib_simplecomplex_jsonlog';

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
     * @param CacheInterface $config
     *
     * @return void
     */
    public function setConfig(CacheInterface $config) /*: void*/
    {
        // Reset cross event vars, if shifting from a previous configuration.
        if ($this->config) {
            $this->threshold = -1;
        }

        $this->config = $config;
        if (method_exists($config, 'keyDomainDelimiter')) {
            $this->configDomain = static::CONFIG_DOMAIN . $config->keyDomainDelimiter();
        } else {
            $this->configDomain = static::CONFIG_DOMAIN . '__';
        }
    }

    /**
     * Check/enable JsonLog to write logs.
     *
     * Also available as CLI command.
     * @see \SimpleComplex\JsonLog\Cli\JsonLogCli
     *
     * @see \SimpleComplex\JsonLog\JsonLogEvent::committable()
     *
     * @code
     * // In CLI mode: Is JsonLog ready?
     * require 'vendor/autoload.php';
     * use \SimpleComplex\JsonLog\JsonLog;
     * $logger = JsonLog::getInstance();
     * // Get info, without attempt to enable.
     * var_dump($logger->committable(false, true));
     * // ...well, try enabling then.
     * var_dump($logger->committable(true, true));
     * @endcode
     *
     * @param bool $enable
     * @param bool $getResponse
     *
     * @return boolean|array
     */
    public function committable($enable = false, $getResponse = false)
    {
        // Init.----------------------------------------------------------------
        // Load dependencies on demand.
        if (!$this->config) {
            $this->setConfig(EnvVarConfig::getInstance());
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
            LOG_DEBUG,
            'Committable?'
        );

        return $event->committable($enable, $getResponse);
    }

    /**
     * PSR LogLevel doesn't define numeric values of levels,
     * but RFC 5424 'emergency' is 0 and 'debug' is 7.
     *
     * @see \Psr\Log\LogLevel
     *
     * @var array
     */
    const LEVEL_BY_SEVERITY = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * LogLevel word.
     *
     * @throws \Psr\Log\InvalidArgumentException
     *      Invalid level argument; as proscribed by PSR-3.
     *
     * @param mixed $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     *
     * @return string
     *      Equivalent to a Psr\Log\LogLevel class constant.
     */
    public function levelToString($level) : string
    {
        // Support RFC 5424 integer as well as words defined by PSR-3.
        $lvl = '' . $level;

        // RFC 5424 integer.
        if (ctype_digit($lvl)) {
            if ($lvl >= 0 && $lvl < count(static::LEVEL_BY_SEVERITY)) {
                return static::LEVEL_BY_SEVERITY[$lvl];
            }
        }
        // Word defined by PSR-3.
        elseif (in_array($lvl, static::LEVEL_BY_SEVERITY)) {
            return $lvl;
        }

        throw new InvalidArgumentException('Invalid log level argument [' . $level . '].');
    }

    /**
     * RFC 5424 integer.
     *
     * @throws \Psr\Log\InvalidArgumentException
     *      Invalid level argument; as proscribed by PSR-3.
     *
     * @param mixed $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     *
     * @return int
     */
    public function levelToInteger($level) : int
    {
        // Support RFC 5424 integer as well as words defined by PSR-3.
        $lvl = '' . $level;

        if (ctype_digit($lvl)) {
            if ($lvl >= 0 && $lvl < count(static::LEVEL_BY_SEVERITY)) {
                return (int) $lvl;
            }
        } else {
            $index = array_search($lvl, static::LEVEL_BY_SEVERITY);
            if ($index !== false) {
                return $index;
            }
        }

        throw new InvalidArgumentException('Invalid log level argument [' . $level . '].');
    }
}
