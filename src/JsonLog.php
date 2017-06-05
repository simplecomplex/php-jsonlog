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
        // Init.
        $threshold = static::$threshold;
        if ($threshold == -1) {
            static::$threshold = $threshold = $this->configGet(
                '', 'threshold', static::THRESHOLD_DEFAULT
            );
        }

        // Sufficiently severe to log?
        $severity = $this->levelToInteger($level);
        // Less is more.
        if ($severity > $threshold) {
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
     * @var CacheInterface|null
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
     * Checks and resolves all dependencies, whereas JsonLogEvent use them unchecked.
     *
     * @see JsonLog::setConfig()
     *
     * @param CacheInterface|null $config
     *      PSR-16 based configuration instance, if any.
     */
    public function __construct(/*?CacheInterface*/ $config = null)
    {
        // Dependencies.--------------------------------------------------------
        // Config is not required.
        $this->config = $config;

        // Extending class' constructor might provide instance by other means.
        if (!$this->unicode) {
            $this->unicode = Unicode::getInstance();
        }
        if (!$this->sanitize) {
            $this->sanitize = Sanitize::getInstance();
        }

        // Business.------------------------------------------------------------
        // None.
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
    public function setConfig(CacheInterface $config) /*: void*/
    {
        $this->config = $config;
    }

    /**
     * Conf var default namespace.
     *
     * @var string
     */
    const CONFIG_DOMAIN = 'lib_simplecomplex_jsonlog';

    /**
     * Delimiter between config domain and config var name, when not using
     * environment vars.
     *
     * @var string
     */
    const CONFIG_DELIMITER = ':';

    /**
     * Provided no config (service) object this implementation uses
     * environment variables.
     *
     * @var string
     */
    const CONFIG_DEFAULT_PROVISION = 'environment variables';

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
     * @var integer
     */
    protected static $threshold = -1;

    /**
     * Get config var.
     *
     * If JsonLog was provided with a config object, that will be used.
     * Otherwise this implementation uses environment vars.
     *
     *  Vars, and their effective defaults:
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
     * Config object var names will be prefixed by
     * CONFIG_DOMAIN . CONFIG_DELIMITER
     * Environment var names will be prefixed by CONFIG_DOMAIN; example
     * lib_simplecomplex_jsonlog_siteid.
     * Beware that environment variables are always strings.
     *
     * @param string $domain
     *      Default: static::CONFIG_DOMAIN.
     * @param string $name
     * @param mixed $default
     *      Default: null.
     *
     * @return mixed|null
     */
    public function configGet($domain, $name, $default = null) /*: ?mixed*/
    {
        if ($this->config) {
            return $this->config->get(
                ($domain ? $domain : static::CONFIG_DOMAIN) . static::CONFIG_DELIMITER . $name,
                $default
            );
        }
        return ($val = getenv(($domain ? $domain : static::CONFIG_DOMAIN) . '_' . $name)) !== false ? $val : $default;
    }

    /**
     * Unless JsonLog was provided with a config object, this implementation
     * does nothing, since you can't save an environment var.
     *
     * @param string $domain
     * @param string $name
     * @param mixed $value
     *
     * @return bool
     */
    public function configSet($domain, $name, $value) : bool
    {
        if ($this->config) {
            return $this->config->set(
                ($domain ? $domain : static::CONFIG_DOMAIN) . static::CONFIG_DELIMITER . $name,
                $value
            );
        }
        return putenv(($domain ? $domain : static::CONFIG_DOMAIN) . '_' . $name . '=' . $value);
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
