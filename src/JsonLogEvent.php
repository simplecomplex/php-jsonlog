<?php
/**
 * SimpleComplex PHP JsonLog
 * @link      https://github.com/simplecomplex/php-jsonlog
 * @copyright Copyright (c) 2014-2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-jsonlog/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\JsonLog;

use SimpleComplex\Utils\Utils;

/**
 * JsonLog event.
 *
 * @internal
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLogEvent
{
    /**
     * Config var default section.
     *
     * @var string
     */
    const CONFIG_SECTION = 'lib_simplecomplex_jsonlog';

    /**
     * Default max. byte length of the 'message' column, in kilobytes.
     *
     * @var int
     */
    const TRUNCATE_DEFAULT = 32;

    /**
     * Overridable by 'type' config var.
     *
     * Using 'php' is probably a bad idea, unless the event to be logged is an
     * actual PHP error.
     *
     * @var string
     */
    const TYPE_DEFAULT = 'webapp';

    /**
     * Overridable by 'subtype' config var.
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
     * Posix compliant (non-Windows) file system.
     *
     * @var bool
     */
    const FILE_SYSTEM_POSIX = DIRECTORY_SEPARATOR == '/';

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
     *  - tags: comma-separed list of tags set site-wide, by 'tags' config var
     *
     * @see JsonLogEvent::columnMap()
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
     * @see JsonLogEvent::columnMap()
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
     * @see JsonLogEvent::columnMap()
     *
     * @var string[]
     */
    const COLUMNS_EVENT = [
        'message' => 'message',
        'timestamp' => '@timestamp',
        'eventId' => 'message_id',
        'correlationId' => 'correlation_id',
        'subType' => 'subtype',
        'level' => 'level',
        'code' => 'code',
        'exception' => 'exception',
        'truncation' => 'trunc',
        'user' => 'user',
        'session' => 'session',
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
        'correlationId',
        'exception',
        'truncation',
        'user',
        'session',
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
    protected $level;

    /**
     * @var string
     */
    protected $messageRaw = '';

    /**
     * @var array
     */
    protected $context = [];

    /**
     * Custom columns set as constructor arg (arr) context[log_custom_columns].
     *
     * @var array
     */
    protected $customColumns = [];

    /**
     * @var int
     */
    protected $lengthPrepared = 0;

    /**
     * @var int
     */
    protected $lengthTruncated = 0;

    /**
     * @var JsonLog
     */
    protected $proxy;

    /**
     * Do not call this directly, use JsonLog instead.
     *
     * @see JsonLog::getInstance()
     * @see JsonLog::log()
     *
     * @internal
     *
     * @param JsonLog $proxy
     * @param string $level
     *      String (word): value as defined by Psr\Log\LogLevel class constants.
     *      Integer|stringed integer: between zero and seven; RFC 5424.
     * @param string $message
     *      Placeholder {word}s must correspond to keys in the context argument.
     * @param array $context
     */
    public function __construct(JsonLog $proxy, string $level, $message, array $context = [])
    {
        $this->proxy = $proxy;

        // LogLevel word.
        $this->level = $level;
        // Stringify if not string.
        $this->messageRaw = '' . $message;

        $this->context = $context;
        if (!empty($this->context['log_custom_columns'])) {
            $this->customColumns = $this->context['log_custom_columns'];
        }
        unset($this->context['log_custom_columns']);
    }

    /**
     * Get event, as array.
     *
     * @return array
     */
    public function get(): array
    {
        $skip_empty = static::SKIP_EMPTY_COLUMNS;

        // Site columns are reusable across individual entries of a request.
        $site = static::$currentSite;
        // Request columns are reusable across individual entries of a request.
        $request = static::$currentRequest;
        // Event columns are ad hoc.
        $event = [];

        // Column methods might throw or propagate an exception;
        // particularly methods of extending class.
        try {
            if (!$site) {
                $columns = static::columnMap('site');
                foreach ($columns as $method => $name) {
                    $val = $this->{$method}();
                    if (!in_array($method, $skip_empty) || $val || $val !== '') {
                        $site[$name] = $val;
                    }
                }
                unset($columns);
                static::$currentSite =& $site;
            }

            if (!$request) {
                $columns = static::columnMap('request');
                foreach ($columns as $method => $name) {
                    $val = $this->{$method}();
                    if (!in_array($method, $skip_empty) || $val || $val !== '') {
                        $request[$name] = $val;
                    }
                }
                unset($columns);
                static::$currentRequest =& $request;
            }

            $columns = static::columnMap('event');
            foreach ($columns as $method => $name) {
                $val = $this->{$method}();
                if (!in_array($method, $skip_empty) || $val || $val !== '') {
                    $event[$name] = $val;
                }
            }
            unset($columns);

            if ($this->customColumns) {
                foreach ($this->customColumns as $column => $value) {
                    if (is_callable($value)) {
                        $event[$column] = '' . $value();
                    } else {
                        $event[$column] = '' . $value;
                    }
                }
            }
        }
        catch (\Throwable $xcptn) {
            // Apparantly some column method threw or propagated an exception.

            // Reset site and request column lists, for later events.
            static::$currentSite = static::$currentRequest = [];

            $site_id = 'unknown';
            $msg_orig = '';
            $exception = 'unknown exception';
            try {
                // Try to establish siteId.
                if (!empty(static::COLUMNS_SITE['siteId'])) {
                    if (!empty($site[static::COLUMNS_SITE['siteId']])) {
                        $site_id = $site[static::COLUMNS_SITE['siteId']];
                    } else {
                        try {
                            $site_id = $this->siteId(true);
                        } catch (\Throwable $xcptn) {
                            $site_id = 'unknown';
                        }
                        $site[static::COLUMNS_SITE['siteId']] = $site_id;
                    }
                }
                // Try to save original message.
                try {
                    $msg_orig = $site[static::COLUMNS_EVENT['message']] ?? '';
                } catch (\Throwable $xcptn) {
                    $msg_orig = '';
                }
                // Try to stringify exception.
                try {
                    $exception = get_class($xcptn) . '(' . $xcptn->getCode() . ')@' . $xcptn->getFile() . ':'
                        . $xcptn->getLine() . ': ' . addcslashes($xcptn->getMessage(), "\0..\37");
                } catch (\Throwable $xcptn) {
                    $site_id = 'unknown';
                }
                // Log to standard log.
                error_log(
                    'jsonlog, site ID[' . $site_id . '], failed due to exception raised via a column method call: '
                    . $exception
                );

            } catch (\Throwable $ignore) {
            }
            $site[static::COLUMNS_EVENT['message']] =
                'jsonlog failed due to exception raised via a column method call, do check standard error log. '
                . (!$msg_orig ? 'Original message lost.' : ('Original message: ' . $msg_orig));
        }

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

    /**
     * For simpler column name and/or sequence override in extending classes.
     *
     * @see JsonLogEvent::COLUMNS_SITE
     * @see JsonLogEvent::COLUMNS_REQUEST
     * @see JsonLogEvent::COLUMNS_EVENT
     *
     * @param string $scope
     *      Values: site|request|event
     * @return array
     */
    public static function columnMap(string $scope): array
    {
        switch ($scope) {
            case 'event':
                return static::COLUMNS_EVENT;
            case 'request':
                return static::COLUMNS_REQUEST;
            default:
                return static::COLUMNS_SITE;
        }
    }

    // Site column getters.-------------------------------------------------------

    /**
     * Uses config var 'type', defaults to TYPE_DEFAULT.
     *
     * @see JsonLogEvent::TYPE_DEFAULT
     *
     * @return string
     */
    public function type(): string
    {
        return '' . $this->proxy->config->get(static::CONFIG_SECTION, 'type', static::TYPE_DEFAULT);
    }

    /**
     * Host name and port.
     *
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
    public function host(): string
    {
        return empty($_SERVER['SERVER_NAME']) ? '' : (
            $this->proxy->sanitize->unicodePrintable($_SERVER['SERVER_NAME'])
            . (
            empty($_SERVER['SERVER_PORT']) || !ctype_digit('' . $_SERVER['SERVER_PORT']) ? '' :
                (':' . $_SERVER['SERVER_PORT'])
            )
        );
    }

    /**
     * Record siteId, because may be used many times; even during each event.
     *
     * @var string
     */
    protected static $siteId = '';

    /**
     * This implementation uses document root dir (rather executed script's
     * parent dir) name as fallback, if no 'siteid' config var set.
     *
     * Attempts to save site ID to config var 'siteid', unless truthy arg noSave.
     *
     * @param bool $noSave
     *      Default: false; do set in config.
     *
     * @return string
     */
    public function siteId($noSave = false): string
    {
        $site_id = static::$siteId;
        if (!$site_id) {
            $site_id = $this->proxy->config->get(static::CONFIG_SECTION, 'siteid');
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
                    $this->proxy->config->set(static::CONFIG_SECTION, 'siteid', $site_id);
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
    public function canonical(): string
    {
        return '' . $this->proxy->config->get(static::CONFIG_SECTION, 'canonical', '');
    }

    /**
     * @return string
     */
    public function tags(): string
    {
        $tags = $this->proxy->config->get(static::CONFIG_SECTION, 'tags');

        return !$tags ? '' : (is_array($tags) ? join(',', $tags) : ('' . $tags));
    }


    // Request column getters.----------------------------------------------------

    /**
     * HTTP request method (uppercase), or 'cli' (lowercase).
     *
     * @return string
     */
    public function method(): string
    {
        return !empty($_SERVER['REQUEST_METHOD']) ?
            preg_replace('/[^A-Z]/', '', $_SERVER['REQUEST_METHOD']) : 'cli';
    }

    /**
     * In cli mode: the line executed.
     *
     * @return string
     */
    public function requestUri(): string
    {
        if (isset($_SERVER['REQUEST_URI'])) {
            return $this->proxy->sanitize->unicodePrintable($_SERVER['REQUEST_URI']);
        }
        if (PHP_SAPI == 'cli' && isset($_SERVER['argv'])) {
            return join(' ', $_SERVER['argv']);
        }
        return '/';
    }

    /**
     * @return string
     */
    public function referer(): string
    {
        return empty($_SERVER['HTTP_REFERER']) ? '' :
            $this->proxy->unicode->substr(
                $this->proxy->sanitize->unicodePrintable($_SERVER['HTTP_REFERER']),
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
    public function clientIp(): string
    {
        $client_ip = !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if ($client_ip) {
            // Get list of configured trusted proxy IPs.
            $proxy_ips = $this->proxy->config->get(static::CONFIG_SECTION, 'reverse_proxy_addresses');
            if ($proxy_ips) {
                // Get 'forwarded for' header, ideally listing
                // 'client, proxy-1, proxy-2, ...'.
                $proxy_header = $this->proxy->config->get(
                    static::CONFIG_SECTION, 'reverse_proxy_header', 'HTTP_X_FORWARDED_FOR'
                );
                if ($proxy_header && !empty($_SERVER[$proxy_header])) {
                    $ips = str_replace(' ', '', $this->proxy->sanitize->ascii($_SERVER[$proxy_header]));
                    if ($ips) {
                        $ips = explode(',', $ips);
                        if (is_string($proxy_ips)) {
                            $proxy_ips = explode(',', str_replace(' ', '', $proxy_ips));
                        }
                        // Append direct client IP, in case it is missing in the
                        // 'forwarded for' header.
                        $ips[] = $client_ip;
                        // Remove trusted proxy IPs.
                        $net_ips = array_diff($ips, $proxy_ips);
                        if ($net_ips) {
                            // The right-most is the most specific.
                            $client_ip = end($net_ips);
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

        return $client_ip && $client_ip == filter_var($client_ip, FILTER_VALIDATE_IP) ? $client_ip : '';
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
    public function userAgent(): string
    {
        return empty($_SERVER['HTTP_USER_AGENT']) ? '' :
            $this->proxy->unicode->substr(
                $this->proxy->sanitize->unicodePrintable($_SERVER['HTTP_USER_AGENT']),
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
    public function message(): string
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
                        $prvs = '';
                        if (($previous = $xcptn->getPrevious())) {
                            $prvs = "\n" . 'Previous: '
                                . get_class($previous) . '(' . $previous->getCode() . ')@' . $previous->getFile() . ':'
                                . $previous->getLine() . "\n" . addcslashes($previous->getMessage(), "\0..\37");
                        }
                        $msg = str_replace(
                            $prefix . 'exception' . $suffix,
                            get_class($xcptn) . '(' . $code . ')@' . $xcptn->getFile() . ':' . $xcptn->getLine()
                            . "\n" . addcslashes($xcptn->getMessage(), "\0..\37")
                            . $prvs,
                            $msg
                        );
                    }
                }
                // Don't unset context[exception]; exception() needs it.
                unset($xcptn, $code);
            }

            foreach ($context as $key => $val) {
                $msg = str_replace($prefix . $key . $suffix, $this->proxy->sanitize->plainText($val), $msg);
            }
        }

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
            $truncate = (int) $this->proxy->config->get(static::CONFIG_SECTION, 'truncate', static::TRUNCATE_DEFAULT);
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
                // Must be integer.
                $truncate = (int) floor($truncate);
            }
            static::$truncate = $truncate;
        }

        if ($truncate && $le > $truncate) {
            $msg = $this->proxy->unicode->truncateToByteLength($msg, $truncate);
            $this->lengthTruncated = strlen($msg);
        }

        return $msg;
    }

    /**
     * @return string
     */
    public function truncation(): string
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
    public function timestamp(): string
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
     * Uses site ID as salt.
     *
     * @return string
     */
    public function eventId(): string
    {
        return uniqid(
            // Salt.
            $this->siteId(),
            true
        );
    }

    /**
     * Correlation ID identifying a service-based workflow sequence,
     * or likewise.
     *
     * @return string
     */
    public function correlationId(): string
    {
        $context =& $this->context;
        if ($context) {
            if (!empty($context['correlationId'])) {
                return $context['correlationId'];
            }
            if (!empty($context['correlation_id'])) {
                return $context['correlation_id'];
            }
        }
        return '';
    }

    /**
     * Uses context 'subType' or 'subtype'; default SUB_TYPE_DEFAULT.
     *
     * @see JsonLogEvent::SUB_TYPE_DEFAULT
     *
     * @return string
     */
    public function subType(): string
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
    public function level(): string
    {
        return $this->level;
    }

    /**
     * Uses context 'code', 'errorCode', 'error_code' or 'exception', def. zero.
     *
     * @return integer
     */
    public function code(): int
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
     * Exception class name, if context has exception bucket.
     *
     * @return string
     */
    public function exception(): string
    {
        if (!empty($this->context['exception'])) {
            $xcptn = $this->context['exception'];
            if (is_object($xcptn)) {
                // Namespace backslash to forward, because searching
                // for backslashed value might be a pain.
                return str_replace('\\', '/', get_class($xcptn));
            }
        }
        return '';
    }

    /**
     * Uses context 'user'; default empty.
     *
     * @return string
     */
    public function user(): string
    {
        // Stringify, might be object.
        return '' . ($this->context['user'] ?? '');
    }

    /**
     * Uses context 'session'; default empty.
     *
     * @return string
     */
    public function session(): string
    {
        return '' . ($this->context['session'] ?? '');
    }


    // Non-column methods and helpers.--------------------------------------------

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
     * Uses ini:error_log respectively server's default web log
     * (plus '/php-jsonlog') as fallback when config var 'path' not set.
     *
     * Attempts to log to error_log if failing to determine dir.
     *
     * @see Utils::resolvePath()
     *
     * @param bool $noSave
     *
     * @return string
     *
     * @throws \Throwable
     *      Propagated; from Utils::resolvePath().
     */
    public function getPath($noSave = false): string
    {
        $path = '' . $this->proxy->config->get(static::CONFIG_SECTION, 'path', '');
        if ($path) {
            if ($path{0} !== '/') {
                // Convert relative to absolute.
                $path = \SimpleComplex\Utils\Utils::getInstance()->resolvePath($path);
            }
            // Update configuration.
            $this->proxy->config->set(static::CONFIG_SECTION, 'path', $path);

            return $path;
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
            $path .= '/php-jsonlog';

            if (!$noSave) {
                $this->proxy->config->set(static::CONFIG_SECTION, 'path', $path);
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
     * Filename composition when non-empty config var 'file_time':
     * [siteId].[date].json.log
     *
     * Filename composition when empty (or 'none') config var 'file_time':
     * [siteId].json.log
     *
     * @uses JsonLogEvent::getPath()
     *
     * @return string
     *      Empty if getPath() fails.
     */
    public function getFile(): string
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

        $file_time = $this->proxy->config->get(static::CONFIG_SECTION, 'file_time', 'Ymd');
        if ($file_time && $file_time != 'none') {
            $file .= '.' . date($file_time);
        }
        $file .= '.json.log';

        static::$file = $file;
        return $file;
    }

    /**
     * @param array $event
     * @param string $format
     *
     * @return string
     */
    public function format(array $event, string $format = 'default'): string {
        switch ($format) {
            case 'pretty':
                return json_encode(
                    $event,
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT |
                    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                );
            case 'prettier':
                // Move 'message' to bottom, no matter what COLUMNS_EVENT says.
                // And do not JSON-encode 'message' at all.
                $message = $event['message'];
                unset($event['message']);
                $event['message'] = '';
                $formatted = json_encode(
                    $event,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                );
                return '' . preg_replace(
                        '/\n([ \t]+)(\"message\":[ ]*\")\"/',
                        "\n" . '$1$2' . "\n" . $message . "\n" . '$1"',
                        $formatted,
                        1
                    )
                    // Divide 'prettier' (non-parsable) events clearly.
                    . "\n////////////////////////////////////////////////////////////////////////////////";
        }
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
    public function commit($formattedEvent): bool
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

        $is_cli = \SimpleComplex\Utils\CliEnvironment::cli();

        $utils = Utils::getInstance();
        $group_write = false;

        $path = $this->getPath();
        if (!$path) {
            $msgs = 'Cannot determine path.';
        } elseif (!file_exists($path)) {
            if (!$enable) {
                $code = 10;
                $msgs[] = 'Path does not exist' . (!$is_cli ? '' : (', path[' . $path . ']')) . '.';
            } else {
                // Get file mode of first dir that exists.
                $mode = 0700;
                $limit = 10;
                $ancestor_path = $path;
                while ((--$limit) && ($ancestor_path = dirname($ancestor_path))) {
                    if (file_exists($ancestor_path)) {
                        if (!is_dir($ancestor_path)) {
                            $code = 11;
                            $msgs[] = 'A fragment of path is not a directory'
                                . (!$is_cli ? '' : (', path[' . $path . ']')) . '.';
                        } elseif (static::FILE_SYSTEM_POSIX) {
                            $mode = fileperms($ancestor_path);
                            $group_write = $utils->isFileGroupWrite($mode);
                        }
                        break;
                    }
                }
                if (!$code) {
                    if (!$mode) {
                        $code = 12;
                        $msgs[] = 'Cannot determine file mode' . (!$is_cli ? '' : (', path[' . $path . ']')) . '.';
                    } else {
                        try {
                            $utils->ensurePath($path, $mode);
                            $msgs[] = 'Created path' . (!$is_cli ? '' : ('[' . $path . ']')) . '.';
                        } catch (\Throwable $xcptn) {
                            $code = 13;
                            $msgs[] = 'Failed to create path' . (!$is_cli ? '' : ('[' . $path . ']')) . '.';
                            $msgs[] = get_class($xcptn) . '(' . $xcptn->getCode() . ')@' . $xcptn->getFile() . ':'
                                . $xcptn->getLine() . ': ' . addcslashes($xcptn->getMessage(), "\0..\37");
                        }
                    }
                }
            }
        } elseif (!is_dir($path)) {
            $code = 20;
            $msgs[] = 'Path is not a directory' . (!$is_cli ? '' : (', path[' . $path . ']')) . '.';
        } elseif (static::FILE_SYSTEM_POSIX) {
            $group_write = $utils->isFileGroupWrite(fileperms($path));
        }

        if (!$code) {
            if (!is_writable($path)) {
                $code = 30;
                $msgs[] = 'Path is not writable' . (!$is_cli ? '' : (', path[' . $path . ']')) . '.';
            } elseif (!is_readable($path)) {
                $code = 40;
                $msgs[] = 'Path is not readable, may not be a problem'
                    . (!$is_cli ? '' : (', path[' . $path . ']')) . '.';
            } else {
                $msgs[] = 'Path is writable.';
                $file = $this->getFile();
                if (file_exists($file)) {
                    if (!is_file($file)) {
                        $code = 50;
                        $msgs[] = 'File is not a file' . (!$is_cli ? '' : (', file[' . $file . ']')) . '.';
                    } elseif (!is_writable($file) || !touch($file)) {
                        $code = 60;
                        $msgs[] = 'File is not writable' . (!$is_cli ? '' : (', file[' . $file . ']')) . '.';
                    } else {
                        $success = true;
                        $msgs[] = 'File is writable' . (!$is_cli ? '' : (', file[' . $file . ']')) . '.';
                    }
                } else {
                    $make = touch($file);
                    if (!$make) {
                        $code = 70;
                        $msgs[] = 'Failed to create file' . (!$is_cli ? '' : ('[' . $file . ']')) . '.';
                    } else {
                        $success = true;
                        $msgs[] = 'Created the file' . (!$is_cli ? '' : ('[' . $file . ']')) . '.';
                    }
                }
                if ($group_write && !chmod($file, 0660)) {
                    $msgs[] = 'Failed to chmod'
                        . (!$is_cli ? '' : (', path[' . $path . ']')) . '.';
                }
            }
        }

        if (!$getResponse) {
            return $success;
        }
        return !$getResponse ? $success : [
            'success' => $success,
            'message' => (!$success ? 'JsonLog is NOT committable' : 'JsonLog is committable')
                .  '; using configuration provided by ' . get_class($this->proxy->config) . ' instance.'
                . (!$msgs ? '' : ("\n" . join(' ', $msgs))),
            'code' => $code,
        ];
    }

    /**
     * Truncate current log file.
     *
     * @return string
     *      Non-empty: path+filename; succeeded.
     *      Empty: failed.
     *
     * @throws \RuntimeException
     *      If not in CLI mode.
     */
    public function truncate(): string
    {
        if (!\SimpleComplex\Utils\CliEnvironment::cli()) {
            throw new \RuntimeException('JsonLog truncate is only allowed in CLI mode.');
        }

        $file = $this->getFile();
        if (!$file) {
            return '';
        }

        // Lock (write, doesn't prevent reading).
        $success = !!file_put_contents($file, "\n", LOCK_EX);
        // If failure: log filing error to host's default log.
        if (!$success) {
            error_log('jsonlog, site ID[' . $this->siteId(true) . '], failed to truncate file[' . $file . '].');
            return '';
        }

        return $file;
    }
}
