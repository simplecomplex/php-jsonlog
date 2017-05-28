<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

use Psr\Log\LogLevel;
use Psr\Log\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use SimpleComplex\Filter\Unicode;
use SimpleComplex\Filter\Sanitize;

/**
 * JsonLog event.
 *
 * @internal
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLogEvent
{


    // @todo: introduce new 'session' column, which can do the same as Inspect used to do: session-id:request-no (no page-load-no)

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
     * Less severe (higher valued) events will not be logged.
     *
     * Overridable by 'threshold' conf var.
     *
     * @var int
     */
    const THRESHOLD_DEFAULT = LOG_WARNING;

    /**
     * Default max. byte length of the 'message' column, in kilobytes.
     *
     * @var int
     */
    const TRUNCATE_DEFAULT = 64;

    /**
     * Overridable by 'type' conf var.
     *
     * Using 'php' is probably a bad idea, unless the event to be logged is an
     * actual PHP error.
     *
     * @var string
     */
    const TYPE_DEFAULT = 'webapp';

    /**
     * Overridable by 'subtype' conf var.
     *
     * @var string
     */
    const SUB_TYPE_DEFAULT = 'component';

    /**
     * Context placeholder prefix in message.
     *
     * @var string
     */
    const PLACEHOLDER_PREFIX = '{';

    /**
     * Context placeholder suffix in message.
     *
     * @var string
     */
    const PLACEHOLDER_SUFFIX = '}';

    /**
     * List of site (system) columns.
     *
     * Key is the property's get method; value is the property's name when logged.
     * Beware: Fatal error if a method doesn't exist.
     *
     * All column methods must return string or number.
     *
     *  Overriding class may:
     *  - list less columns
     *  - define different values; log column names
     *  - list more columns; do make sure to declare a get method for each key
     *
     *  Non-obvious columns:
     *  - type: could be the name of a PHP framework
     *  - canonical: canonical site identifier across site instances
     *  - tags: comma-separed list of tags set site-wide, by 'tags' conf var
     *
     * @var string[]
     */
    const COLUMNS_SITE = [
        'type' => 'type',
        'host' => 'host',
        'siteId' => 'site_id',
        'canonical' => 'canonical',
        'tags' => 'tags',
    ];

    /**
     * List of request (process) columns.
     *
     * @var string[]
     */
    const COLUMNS_REQUEST = [
        'method' => 'method',
        'requestUri' => 'request_uri',
        'referer' => 'referer',
        'clientIp' => 'client_ip',
        'userAgent' => 'useragent',
    ];

    /**
     * List of event-specific columns.
     *
     *  Non-obvious columns:
     *  - timestamp: iso 8601 datetime with milliseconds. UTC Z; not timezone
     *  - canonical: canonical site identifier across site instances
     *  - subType: should be the name of a component, module or equivalent
     *  - (int) code: could be an error code
     *  - truncation: 'orig. length/final length' if message truncated
     *
     * Required column sequences:
     * - message before truncation
     *
     * @var string[]
     */
    const COLUMNS_EVENT = [
        'message' => 'message',
        'timestamp' => '@timestamp',
        'eventId' => 'message_id',
        'subType' => 'subtype',
        'level' => 'level',
        'code' => 'code',
        'truncation' => 'trunc',
        'user' => 'user',
    ];

    /**
     * Lists the sequence of column groups (site, request, event).
     *
     * @var string[]
     */
    const COLUMN_SEQUENCE = [
        'event',
        'request',
        'site',
    ];

    /**
     * Skip these columns when empty string.
     *
     * @var string[]
     */
    const SKIP_EMPTY_COLUMNS = [
        'canonical',
        'tags',
        'referer',
        'clientIp',
        'userAgent',
        'truncation',
        'user',
    ];

    /**
     * Site columns are reusable across individual entries of a request.
     *
     * @var array
     */
    protected static $currentSite = [];

    /**
     * Request columns are reusable across individual entries of a request.
     *
     * @var array
     */
    protected static $currentRequest = [];

    /**
     * @var string
     */
    protected $level = LogLevel::DEBUG;

    /**
     * @var int
     */
    protected $severity = LOG_DEBUG;

    /**
     * @var string
     */
    protected $messageRaw = '';

    /**
     * @var array
     */
    protected $context = [];

    /**
     * @var int
     */
    protected $lengthPrepared = 0;

    /**
     * @var int
     */
    protected $lengthTruncated = 0;

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
     * Do not call this directly, use JsonLog instead.
     *
     * Does not check dependencies; JsonLog constructor does that.
     *
     * @see JsonLog::__construct()
     * @see JsonLog::getInstance()
     * @see JsonLog::log()
     *
     * @internal
     *
     * @param array $dependencies {
     *      @var CacheInterface|null $config  Optional, null will do.
     *      @var Unicode $unicode  Required.
     *      @var Sanitize $sanitize  Required.
     * }
     * @param mixed $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     * @param string $message
     *      Placeholder {word}s must correspond to keys in the context argument.
     * @param array $context
     */
    public function __construct(array $dependencies, $level, $message, array $context = [])
    {
        $this->config = $dependencies['config'];
        $this->unicode = $dependencies['unicode'];
        $this->sanitize = $dependencies['sanitize'];

        // Set LogLevel word.
        $this->level = static::levelToString($level);
        // Set RFC 5424 integer.
        $this->severity = static::levelToInteger($level);
        // Stringify if not string.
        $this->messageRaw = '' . $message;

        $this->context = $context;
    }

    /**
     * Record configured severity threshold across events of a request.
     *
     * @var integer
     */
    protected static $threshold = -1;

    /**
     * Check if this entry should be logged at all.
     *
     * @return bool
     */
    public function severe()
    {
        // Less is more.
        return $this->severity <= $this->getThreshold();
    }

    /**
     * Get event, as array.
     *
     * @return array
     */
    public function get() : array
    {
        $skip_empty = static::SKIP_EMPTY_COLUMNS;

        $site = static::$currentSite;
        if (!$site) {
            $columns = static::COLUMNS_SITE;
            foreach ($columns as $method => $name) {
                $val = $this->{$method}();
                if (!in_array($method, $skip_empty) || $val || $val !== '') {
                    $site[$name] = $val;
                }
            }
            unset($columns);
            static::$currentSite =& $site;
        }

        $request = static::$currentRequest;
        if (!$request) {
            $columns = static::COLUMNS_REQUEST;
            foreach ($columns as $method => $name) {
                $val = $this->{$method}();
                if (!in_array($method, $skip_empty) || $val || $val !== '') {
                    $request[$name] = $val;
                }
            }
            unset($columns);
            static::$currentRequest =& $request;
        }

        $event = [];
        $columns = static::COLUMNS_EVENT;
        foreach ($columns as $method => $name) {
            $val = $this->{$method}();
            if (!in_array($method, $skip_empty) || $val || $val !== '') {
                $event[$name] = $val;
            }
        }
        unset($columns);

        $sequence = static::COLUMN_SEQUENCE;
        switch ($sequence[0]) {
            case 'event':
                // Specific - generic.
                return $sequence[1] == 'request' ? ($event + $request + $site) :
                    ($event + $site + $request);
            case 'site':
                // Generic - specific.
                return $sequence[1] == 'request' ? ($site + $request + $event) :
                    ($site + $event + $request);
            default:
                // 'request'.
                // Messy sequence.
                return $sequence[1] == 'event' ? ($request + $event + $site) :
                    ($request + $site + $event);
        }
    }


    // Site column getters.-------------------------------------------------------

    /**
     * Uses conf var 'type', defaults to TYPE_DEFAULT.
     *
     * @see JsonLogEvent::TYPE_DEFAULT
     *
     * @return string
     */
    public function type() : string
    {
        return '' . $this->configGet(static::CONFIG_DOMAIN, 'type', static::TYPE_DEFAULT);
    }

    /**
     * This implementation uses what potentially is the server name provided
     * by the client; overriding in sub class is recommended.
     *
     * Beware (citing php.net):
     * Under Apache 2, you must set UseCanonicalName = On and ServerName.
     * Otherwise, this value reflects the hostname supplied by the client, which
     * can be spoofed.
     *
     * @return string
     */
    public function host() : string
    {
        return empty($_SERVER['SERVER_NAME']) ? '' :
            $this->sanitize->unicodePrintable($_SERVER['SERVER_NAME']);
    }

    /**
     * Record siteId, because may be used many times; even during each event.
     *
     * @var string
     */
    protected static $siteId = '';

    /**
     * This implementation uses document root dir (rather executed script's
     * parent dir) name as fallback, if no 'siteid' conf var set.
     *
     * Attempts to save site ID to conf var 'siteid', unless truthy arg noSave.
     *
     * @param bool $noSave
     *      Default: false; do set in config.
     *
     * @return string
     */
    public function siteId($noSave = false) : string
    {
        $site_id = static::$siteId;
        if (!$site_id) {
            $site_id = $this->configGet(static::CONFIG_DOMAIN, 'siteid', null);
            if (!$site_id) {
                // If no site ID defined: use name of last dir in document root;
                // except if last dir name is useless, then second to last.
                $dirs = explode('/', trim(getcwd(), '/'));
                $le = count($dirs);
                if ($le) {
                    if ($le > 1) {
                        // Try right-most dir.
                        $site_id = array_pop($dirs);
                        switch ($site_id) {
                            case 'http':
                            case 'html':
                            case 'public_html':
                            case 'www':
                                // Use second-to-last, if any.
                                if (count($dirs)) {
                                    $site_id = array_pop($dirs);
                                }
                                break;
                            default:
                                // Keep.
                        }
                    }
                    else {
                        $site_id = $dirs[0];
                    }
                }
                if (!$site_id) {
                    $site_id = 'unknown';
                }
                elseif (!$noSave) {
                    // Save it; kind of expensive to establish.
                    $this->configSet(static::CONFIG_DOMAIN, 'siteid', $site_id);
                }
            }
            static::$siteId = $site_id;
        }

        return $site_id;
    }

    /**
     * Site identifier across multiple instances.
     *
     * @return string
     */
    public function canonical() : string
    {
        return '' . $this->configGet(static::CONFIG_DOMAIN, 'canonical', '');
    }

    /**
     * @return string
     */
    public function tags() : string
    {
        $tags = $this->configGet(static::CONFIG_DOMAIN, 'tags');

        return !$tags ? '' : (is_array($tags) ? join(',', $tags) : ('' . $tags));
    }


    // Request column getters.----------------------------------------------------

    /**
     * HTTP request method (uppercase), or 'cli' (lowercase).
     *
     * @return string
     */
    public function method() : string
    {
        return !empty($_SERVER['REQUEST_METHOD']) ?
            preg_replace('/[^A-Z]/', '', $_SERVER['REQUEST_METHOD']) : 'cli';
    }

    /**
     * In cli mode: the line executed.
     *
     * @return string
     */
    public function requestUri() : string
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            return $this->sanitize->unicodePrintable($_SERVER['REQUEST_URI']);
        }
        if (PHP_SAPI == 'cli' && isset($_SERVER['argv'])) {
            return join(' ', $_SERVER['argv']);
        }
        return '/';
    }

    /**
     * @return string
     */
    public function referer() : string
    {
        return empty($_SERVER['HTTP_REFERER']) ? '' :
            $this->unicode->substr(
                $this->sanitize->unicodePrintable($_SERVER['HTTP_REFERER']),
                0,
                255
            );
    }

    /**
     * Excludes proxy addresses if configured accordingly.
     *
     *  Conf vars used:
     *  - reverse_proxy_addresses: comma-separated list of proxy IPd
     *  - reverse_proxy_header: default HTTP_X_FORWARDED_FOR
     *
     * @return string
     */
    public function clientIp() : string
    {
        $client_ip = !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if ($client_ip) {
            // Get list of configured trusted proxy IPs.
            $proxy_ips = $this->configGet(static::CONFIG_DOMAIN, 'reverse_proxy_addresses');
            if ($proxy_ips) {
                // Get 'forwarded for' header, ideally listing
                // 'client, proxy-1, proxy-2, ...'.
                $proxy_header = $this->configGet(
                    static::CONFIG_DOMAIN, 'reverse_proxy_header', 'HTTP_X_FORWARDED_FOR'
                );
                if ($proxy_header && !empty($_SERVER[$proxy_header])) {
                    $ips = str_replace(' ', '', $this->sanitize->ascii($_SERVER[$proxy_header]));
                    if ($ips) {
                        $ips = explode(',', $ips);
                        // Append direct client IP, in case it is missing in the
                        // 'forwarded for' header.
                        $ips[] = $client_ip;
                        // Remove trusted proxy IPs.
                        $netIps = array_diff($ips, $proxy_ips);
                        if ($netIps) {
                            // The right-most is the most specific.
                            $client_ip = end($netIps);
                        }
                        else {
                            // The 'forwarded for' header contained known proxy IPs only;
                            // use the first of them
                            $client_ip = reset($ips);
                        }
                    }
                }
            }
        }

        return $client_ip && $client_ip != filter_var($client_ip, FILTER_VALIDATE_IP) ? $client_ip : '';
    }

    /**
     * Truncate HTTP useragent.
     *
     * @var integer
     */
    const USER_AGENT_TRUNCATE = 100;

    /**
     * @return string
     */
    public function userAgent() : string
    {
        return empty($_SERVER['HTTP_USER_AGENT']) ? '' :
            $this->unicode->substr(
                $this->sanitize->unicodePrintable($_SERVER['HTTP_USER_AGENT']),
                0,
                static::USER_AGENT_TRUNCATE
            );
    }


    // Event column getters.------------------------------------------------------

    /**
     * Record configured truncate value across events of a request.
     *
     * Zero is valid value.
     *
     * @var integer
     */
    protected static $truncate = -1;

    /**
     * @return string
     */
    public function message() : string
    {
        // Get raw message, and clear instance var to save memory.
        $msg = $this->messageRaw;
        $this->messageRaw = '';

        if ($msg === '') {
            return '';
        }

        // Replace placeholder vars.
        $context =& $this->context;
        if ($context) {
            $prefix = static::PLACEHOLDER_PREFIX;
            $suffix = static::PLACEHOLDER_SUFFIX;

            // PSR-3 context 'exception'.
            if (!empty($context['exception'])) {
                $xcptn = $context['exception'];
                if (is_object($xcptn) && $xcptn instanceof \Throwable) {
                    $code = $xcptn->getCode();
                    /**
                     * Either message() or code() extracts exception code.
                     * We cannot know which is called first; sequence of COLUMNS_EVENT.
                     *
                     * @see JsonLogEvent::code()
                     * @see JsonLogEvent::COLUMNS_EVENT()
                     */
                    if (empty($context['code'])) {
                        $context['code'] = $code;
                    }

                    if (strpos($msg, $prefix . 'exception' . $suffix) !== false) {
                        $msg = str_replace(
                            $prefix . 'exception' . $suffix,
                            '(' . $code . ') @' . $xcptn->getFile() . ':' . $xcptn->getLine()
                            . "\n" . addcslashes($xcptn->getMessage(), "\0..\37"),
                            $msg
                        );
                    }
                }
                unset($context['exception'], $xcptn, $code);
            }

            foreach ($context as $key => $val) {
                $msg = str_replace($prefix . $key . $suffix, $this->sanitize->plainText($val), $msg);
            }
        }

        // Strip tags if message starts with < (Inspect logs in tag).
        // @todo: check how kibana displays HTML in message.
        /*
        if (
            !$this->configGet(static::CONFIG_DOMAIN, 'keep_enclosing_tag')
            && $msg{0} === '<'
        ) {
            $msg = strip_tags($msg);
        }
        */

        // Escape null byte.
        $msg = str_replace("\0", '_NUL_', $msg);

        // Truncation.
        $le = strlen($msg);
        if (!$le) {
            return '';
        }

        $this->lengthPrepared = $le;

        $truncate = static::$truncate;
        if ($truncate == -1) {
            $truncate = $this->configGet(static::CONFIG_DOMAIN, 'truncate', static::TRUNCATE_DEFAULT);
            if ($truncate) {
                // Kb to bytes.
                $truncate *= 1024;
                // Substract estimated max length of everything but message content.
                $truncate -= 768;
                // Useragent length is customizable; may not be used but anyway.
                $truncate -= static::USER_AGENT_TRUNCATE;
                // Message will get longer when JSON encoded, because of hex encoding of
                // <>&" chars.
                $truncate *= 7 / 8;
            }
            static::$truncate = $truncate;
        }

        if ($truncate && $le > $truncate) {
            $msg = $this->unicode->truncateToByteLength($msg, $truncate);
            $this->lengthTruncated = strlen($msg);
        }

        return $msg;
    }

    /**
     * @return string
     */
    public function truncation() : string
    {
        return !$this->lengthTruncated ? '' : ('' . $this->lengthPrepared . '/' . $this->lengthTruncated);
    }

    /**
     * @var bool
     */
    const TIMESTAMP_UTC = false;

    /**
     * Iso 8601 datetime, including milliseconds.
     *
     * Local time + timezone, unless TIMESTAMP_UTC (then UTC no-zone Z).
     *
     * @return string
     */
    public function timestamp() : string
    {
        // PHP formats iso 8601 with timezone; we use UTC Z.
        $millis = round(microtime(true) * 1000);
        $seconds = (int) floor($millis / 1000);
        $millis -= $seconds * 1000;
        $millis = str_pad('' . $millis, 3, '0', STR_PAD_LEFT);

        // PHP date('c') formats iso 8601 with timezone.
        if (!static::TIMESTAMP_UTC) {
            $local = date('c', $seconds);
            return substr($local, 0, 19) . '.' . $millis . substr($local, 19);
        }
        return substr(gmdate('c', $seconds), 0, 19) . '.' . $millis . 'Z';
    }

    /**
     * @return string
     */
    public function eventId() : string
    {
        return uniqid(
            $this->siteId(),
            true
        );
    }

    /**
     * Uses context 'subType' or 'subtype'; default SUB_TYPE_DEFAULT.
     *
     * @see JsonLogEvent::SUB_TYPE_DEFAULT
     *
     * @return string
     */
    public function subType() : string
    {
        $context =& $this->context;
        if ($context) {
            if (!empty($context['subType'])) {
                return $context['subType'];
            }
            if (!empty($context['subtype'])) {
                return $context['subtype'];
            }
        }
        return static::SUB_TYPE_DEFAULT;
    }

    /**
     * @see \Psr\Log\LogLevel
     *
     * @return string
     */
    public function level() : string
    {
        return $this->level;
    }

    /**
     * Uses context 'code', 'errorCode', 'error_code' or 'exception', def. zero.
     *
     * @return integer
     */
    public function code() : int
    {
        $context =& $this->context;
        if ($context) {
            if (!empty($context['code'])) {
                return $context['code'];
            }
            if (!empty($context['errorCode'])) {
                return $context['errorCode'];
            }
            if (!empty($context['error_code'])) {
                return $context['error_code'];
            }
            // PSR-3 context 'exception'.
            /**
             * Either message() or code() extracts exception code.
             * We cannot know which is called first; sequence of COLUMNS_EVENT.
             *
             * @see JsonLogEvent::message()
             * @see JsonLogEvent::COLUMNS_EVENT()
             */
            if (!empty($context['exception'])) {
                $xcptn = $context['exception'];
                if (is_object($xcptn) && $xcptn instanceof \Throwable) {
                    return $xcptn->getCode();
                }
            }
        }
        return 0;
    }

    /**
     * Uses context 'user'; default empty.
     *
     * @return string
     */
    public function user() : string
    {
        // Stringify, might be object.
        return '' . ($this->context['user'] ?? '');
    }


    // Non-column methods and helpers.--------------------------------------------

    /**
     * @param bool $asString
     *
     * @return integer|string
     */
    public function getThreshold($asString = false)
    {
        $threshold = static::$threshold;
        if ($threshold == -1) {
            static::$threshold = $threshold = $this->configGet(
                static::CONFIG_DOMAIN, 'threshold', static::THRESHOLD_DEFAULT
            );
        }

        return !$asString ? $threshold : static::levelToString($threshold);
    }

    /**
     * Default webserver log dirs of major *nix distros.
     *
     * @var array
     */
    const LOG_DIR_DEFAULTS = [
        // Debian Apache.
        '/var/log/apache2',
        // Redhat Apache.
        '/var/log/httpd',
        // nginx.
        '/var/log/nginx',
    ];

    /**
     * Uses ini:error_log respectively server's default web log (plus '/jsonlog')
     * as fallback when conf var 'path' not set.
     *
     * Attempts to log to error_log if failing to determine dir.
     *
     * @param bool $noSave
     *
     * @return string
     */
    public function getPath($noSave = false) : string
    {
        $path = $this->configGet(static::CONFIG_DOMAIN, 'path', '');
        if ($path) {
            return '' . $path;
        }

        $host_log = ini_get('error_log');
        if (!$host_log || $host_log === 'syslog') {
            // Try default web server log dirs for common *nix distributions.
            foreach (static::LOG_DIR_DEFAULTS as $val) {
                if (file_exists($val)) {
                    $path = $val;
                    break;
                }
            }
        }
        else {
            $path = dirname($host_log);
            if (DIRECTORY_SEPARATOR == '\\') {
                $path = str_replace('\\', '/', dirname($path));
            }
        }

        if ($path) {
            $path .= '/jsonlog';

            if (!$noSave) {
                $this->configSet(static::CONFIG_DOMAIN, 'path', $path);
            }
            return $path;
        }

        error_log('jsonlog, site ID[' . $this->siteId(true) . '], cannot determine log dir.');

        return '';
    }

    /**
     * @var string
     */
    protected static $file = '';

    /**
     * Get path and filename.
     *
     * Filename composition when non-empty conf var 'file_time':
     * [siteId].[date].json.log
     *
     * Filename composition when empty (or 'none') conf var 'file_time':
     * [siteId].json.log
     *
     * @uses JsonLogEvent::getPath()
     *
     * @return string
     *      Empty if getPath() fails.
     */
    public function getFile() : string
    {
        $file = static::$file;
        if ($file) {
            return $file;
        }
        $file = $this->getPath();
        if (!$file) {
            // No reason to log failure here; getPath() does that.
            return '';
        }

        $file .= '/' . $this->siteId(true);

        $fileTime = $this->configGet(static::CONFIG_DOMAIN, 'file_time', 'Ymd');
        if ($fileTime && $fileTime != 'none') {
            $file .= '.' . date($fileTime);
        }
        $file .= '.json.log';

        static::$file = $file;
        return $file;
    }

    /**
     * @param array $event
     *
     * @return string
     */
    public function format(array $event) {
        return json_encode(
            $event,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
    }

    /**
     * Write event to file.
     *
     * @param string $formattedEvent
     *
     * @return bool
     */
    public function commit($formattedEvent) : bool
    {
        $file = $this->getFile();
        if (!$file) {
            return false;
        }

        // File append, using lock (write, doesn't prevent reading).
        $success = !!file_put_contents($file, $formattedEvent . "\n", FILE_APPEND | LOCK_EX);
        // If failure: log filing error to host's default log.
        if (!$success) {
            error_log('jsonlog, site ID[' . $this->siteId(true) . '], failed to write to file[' . $file . '].');
        }

        return $success;
    }

    /**
     * Check/enable JsonLogEvent to write logs.
     *
     * May set access and modification time of file, which may confuse
     * a collection (logstash et al.).
     *
     * Will attempt to create log path, if truthy arg enable and the path doesn't
     * exist.
     * When creating missing dirs in the path, the file mode of the right-most
     * existing dir will be used. However mode may well become wrong (seen failing
     * group-write).
     *
     * @param bool $enable
     *      Truthy: attempt to make committable if not.
     * @param bool $getResponse
     *      Truthy: return [ success: bool, message: str, code: int ], not boolean.
     *      In CLI mode message contains explicit path or path+file.
     *
     * @return bool|array
     */
    public function committable($enable = false, $getResponse = false)
    {
        $success = false;
        $msgs = [];
        $code = 0;

        $path = $this->getPath();
        if (!$path) {
            $msgs = 'Cannot determine path.';
        } elseif (!file_exists($path)) {
            if (!$enable) {
                $code = 10;
                $msgs[] = 'Path does not exist'
                    . (PHP_SAPI != 'cli' ? '' : (', path[' . $path . ']')) . '.';
            } else {
                // Get file mode of first dir that exists.
                $mode = 11;
                $limit = 10;
                $ancestor_path = $path;
                while ((--$limit) && ($ancestor_path = dirname($ancestor_path))) {
                    if (file_exists($ancestor_path)) {
                        if (!is_dir($ancestor_path)) {
                            $code = 11;
                            $msgs[] = 'A fragment of path is not a directory'
                                . (PHP_SAPI != 'cli' ? '' : (', path[' . $path . ']')) . '.';
                        } else {
                            $mode = fileperms($ancestor_path);
                        }
                        break;
                    }
                }
                if (!$code) {
                    if (!$mode) {
                        $code = 12;
                        $msgs[] = 'Cannot determine file mode'
                            . (PHP_SAPI != 'cli' ? '' : (', path[' . $path . ']')) . '.';
                    } else {
                        $make = mkdir($path, $mode, true);
                        if (!$make) {
                            $code = 13;
                            $msgs[] = 'Failed to create path'
                                . (PHP_SAPI != 'cli' ? '' : ('[' . $path . ']')) . '.';
                        } else {
                            $msgs[] = 'Created path'
                                . (PHP_SAPI != 'cli' ? '' : ('[' . $path . ']')) . '.';
                        }
                    }
                }
            }
        } elseif (!is_dir($path)) {
            $code = 20;
            $msgs[] = 'Path is not a directory'
                . (PHP_SAPI != 'cli' ? '' : (', path[' . $path . ']')) . '.';
        }

        if (!$code) {
            if (!is_writable($path)) {
                $code = 30;
                $msgs[] = 'Path is not writable'
                    . (PHP_SAPI != 'cli' ? '' : (', path[' . $path . ']')) . '.';
            } elseif (!is_readable($path)) {
                $code = 40;
                $msgs[] = 'Path is not readable, may not be a problem'
                    . (PHP_SAPI != 'cli' ? '' : (', path[' . $path . ']')) . '.';
            } else {
                $msgs[] = 'Path is writable.';
                $file = $this->getFile();
                if (file_exists($file)) {
                    if (!is_file($file)) {
                        $code = 50;
                        $msgs[] = 'File is not a file'
                            . (PHP_SAPI != 'cli' ? '' : (', file[' . $file . ']')) . '.';
                    } elseif (!is_writable($file) || !touch($file)) {
                        $code = 60;
                        $msgs[] = 'File is not writable'
                            . (PHP_SAPI != 'cli' ? '' : (', file[' . $file . ']')) . '.';
                    } else {
                        $success = true;
                    }
                } else {
                    $make = touch($file);
                    if (!$make) {
                        $code = 70;
                        $msgs[] = 'Failed to create file'
                            . (PHP_SAPI != 'cli' ? '' : ('[' . $file . ']')) . '.';
                    } else {
                        $success = true;
                        $msgs[] = 'Created the file'
                            . (PHP_SAPI != 'cli' ? '' : ('[' . $file . ']')) . '.';
                    }
                }
            }
        }

        return !$getResponse ? $success : [
            'success' => $success,
            'message' => join(' ', $msgs),
            'code' => $code,
        ];
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
    public static function levelToString($level) : string
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
     * @throws \Psr\Log\InvalidArgumentException
     *      Invalid level argument; as proscribed by PSR-3.
     *
     * @param mixed $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     *
     * @return int
     */
    public static function levelToInteger($level) : int
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
    public function configGet($domain, $name, $default = null)
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
}
