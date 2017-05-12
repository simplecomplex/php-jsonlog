<?php

namespace SimpleComplex\JsonLog;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\InvalidArgumentException;

/**
 * PSR-3 logger which files events as JSON.
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLog extends AbstractLogger {

  // Psr\Log\AbstractLogger properties.

  /**
   * Logs if level is equal to or more severe than a threshold; default warning.
   *
   * @throws \Psr\Log\InvalidArgumentException
   *   Propagated; invalid level argument.
   *
   * @param mixed $level
   *   String (word): value as defined by Psr\Log\LogLevel class constants.
   *   Integer|stringed integer: between zero and seven.
   * @param string $message
   *   Placeholder {word}s must correspond to keys in the context argument.
   * @param array $context
   *   Contrary to
   *
   * @return void
   */
  final public function log($level, $message, array $context = array()) {
    // Convert RFC 5424 integer to PSR-3 word.
    $severity = static::severity($level);

    $threshold = static::$threshold;
    if ($threshold == -1) {
      static::$threshold = $threshold = static::configGet(static::CONFIG_DOMAIN, 'threshold', static::THRESHOLD_DEFAULT);
    }
    // More is less.
    if ($severity > $threshold) {
      return;
    }

    $class = '\\SimpleComplex\\JsonLog\\JsonLogSite';

    $system = new $class();

    $this->setEventGenerics();
    $this->setEventSpecifics($severity, $message, $context);

    $this->write();

    // Pass event to class var, to make generic properties and property sequence
    // reusable by later log call.

    // Clear message, to save memory.
    $this->event['message'] = '';

    static::$eventLast = $this->event;
  }


  public function getEntry() {

  }


  // Idiosyncratic properties.

  /**
   * Conf var default namespace.
   *
   * @var string
   */
  const CONFIG_DOMAIN = 'lib_simplecomplex_jsonlog_jsonlog';

  /**
   * Less severe (higher valued) events will not be logged.
   *
   * Overridable by 'threshold' conf var.
   *
   * @var integer
   */
  const THRESHOLD_DEFAULT = LOG_WARNING;

  /**
   * Default max. byte length of a log entry, in kilobytes.
   *
   * @var integer
   */
  const TRUNCATE_DEFAULT = 64;

  /**
   * Overridable by 'type' conf var.
   *
   * Using 'php' is probably a bad idea, unless the event to be logged is an
   * actual (and infamous) PHP error caught by a custom error handler.
   *
   * @var string
   */
  const TYPE_DEFAULT = 'webapp';

  /**
   * Overridable by 'subtype' conf var.
   *
   * @var string
   */
  const SUBTYPE_DEFAULT = 'component';

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
   * List of event properties used; key is internal name, value is the name used
   * in actual log entry.
   *
   * Extending classes may set other order, less or more props, and set other
   * values (log entry prop names).
   * The only mandatory bucket is 'message'.
   *
   *  Non-obvious property specs:
   *  - timestamp: iso 8601 datetime with milliseconds. UTC Z; not timezone
   *  - (int) version: don't know what's versioned, but Kibana seem to like it
   *  - canonical: canonical site identifier across site instances
   *  - tags: comma-separed list of tags set site-wide, by 'tags' conf var
   *  - type: could be the name of a PHP framework
   *  - subtype: should be the name of a component, module or equivalent
   *  - (int) code: could be an error code
   *  - (null|arr) trunc: [orig. length, final length] if message truncated
   *
   *  Props generic across log events of a request:
   *  - version, site_id, canonical, tags, type,
   *    method, request_uri, referer, client_ip, useragent
   *
   *  Event-specific props:
   *  - message, timestamp, message_id, subtype, severity, username, code, trunc
   *
   *  Beware - these properties will only be set if non-empty:
   *  - canonical
   *  - tags
   *
   * @var array
   */
  protected static $eventTemplate = array(
    'message' => 'message',
    'timestamp' => '@timestamp',
    'version' => '@version',
    'message_id' => 'message_id',
    'site_id' => 'site_id',
    'canonical' => 'canonical',
    'tags' => 'tags',
    'type' => 'type',
    'subtype' => 'subtype',
    'severity' => 'severity',
    'method' => 'method',
    'request_uri' => 'request_uri',
    'referer' => 'referer',
    'username' => 'username',
    'client_ip' => 'client_ip',
    'useragent' => 'useragent',
    'code' => 'code',
    'trunc' => 'trunc',
  );

  protected static $columnsSite = array(
    'type' => 'type',
    'siteId' => 'site_id',
    'instanceOf' => 'canonical',
    'tags' => 'tags',
  );

  protected static $columnsRequest = array(
    'method' => 'method',
    'requestUri' => 'request_uri',
    'referer' => 'referer',
    'clientIp' => 'client_ip',
    'userAgent' => 'useragent',
  );

  protected static $columnsEvent = array(
    'message' => 'message',
    'timestamp' => '@timestamp',
    'eventId' => 'message_id',
    'subType' => 'subtype',
    'severity' => 'severity',
    'code' => 'code',
    'truncaction' => 'trunc',
    'userName' => 'username',
  );


  protected static $columnSequence = array(
    'event',
    'request',
    'site',
  );

  /**
   * Site columns are reusable across individual entries of a request.
   *
   * @var array
   */
  protected static $currentSite = array();

  /**
   * Request columns are reusable across individual entries of a request.
   *
   * @var array
   */
  protected static $currentRequest = array();

  /**
   * @return array
   */
  protected function createEntry() {
    $site = static::$currentSite;
    if (!$site) {
      $columns =& static::$columnsSite;
      foreach ($columns as $method => $name) {
        $site[$name] = $this->{$method}();
      }
      unset($columns);
      static::$currentSite =& $site;
    }

    $request = static::$currentRequest;
    if (!$request) {
      $columns =& static::$columnsRequest;
      foreach ($columns as $method => $name) {
        $request[$name] = $this->{$method}();
      }
      unset($columns);
      static::$currentRequest =& $request;
    }

    $event = array();
    $columns =& static::$columnsEvent;
    foreach ($columns as $method => $name) {
      $event[$name] = $this->{$method}();
    }
    unset($columns);

    $sequence = static::$columnSequence;
    switch ($sequence[0]) {
      case 'event':
        // Specific - generic.
        return $sequence[1] == 'request' ? ($event + $request + $site) : ($event + $site + $request);
      case 'site':
        // Generic - specific.
        return $sequence[1] == 'request' ? ($site + $request + $event) : ($site + $event + $request);
      default:
        // 'request'.
        // Messy sequence.
        return $sequence[1] == 'event' ? ($request + $event + $site) : ($request + $site + $event);
    }
  }

  /**
   * Record siteId, because may be used many times; even during each entry.
   *
   * @var string
   */
  protected static $siteId = '';

  /**
   * This implementation uses a document root dir name as fallback, if no
   * 'siteid' conf var set.
   *
   * Attempts to save site ID to conf var 'siteid'.
   *
   * @return string
   */
  public function siteId() {
    $site_id = static::$siteId;
    if (!$site_id) {
      $site_id = static::configGet(static::CONFIG_DOMAIN, 'siteid', null);
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
              case 'www':
              case 'http':
              case 'html':
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
        if ($site_id) {
          // Save it; kind of expensive to establish.
          static::configSet(static::CONFIG_DOMAIN, 'siteid', $site_id);
        }
        else {
          $site_id = 'unknown';
        }
      }
      static::$siteId = $site_id;
    }

    return $site_id;
  }


  /**
  'message' => 'message',
  'timestamp' => '@timestamp',
  'version' => '@version',
  'message_id' => 'message_id',
  'site_id' => 'site_id',
  'canonical' => 'canonical',
  'tags' => 'tags',
  'type' => 'type',
  'subtype' => 'subtype',
  'severity' => 'severity',
  'method' => 'method',
  'request_uri' => 'request_uri',
  'referer' => 'referer',
  'username' => 'username',
  'client_ip' => 'client_ip',
  'useragent' => 'useragent',
  'code' => 'code',
  'trunc' => 'trunc',
   *
   *
   */

  /**
   * On later log calls, event generics are reused by reusing last event
   * but overwriting event specific properties.
   *
   * The event's 'message' is/should be empty - cleared upon log write - to save
   * memory.
   *
   * @var array
   */
  protected static $eventLast = array();

  /**
   * @var integer
   */
  protected static $threshold = -1;

  /**
   * @var integer
   */
  protected static $eventTruncate = 64;

  /**
   * @var string
   */
  protected static $siteId = '';

  /**
   * @var string
   */
  protected static $file = '';

  /**
   * @var array
   */
  protected $event = array();

  /**
   * Sets event prop values that are common across events of the same request.
   *
   * @return void
   */
  protected function setEventGenerics() {
    $siteId = static::$siteId;
    if (!$siteId) {
      static::$siteId = $siteId = static::siteId();
    }

    // If there's a previous event, copy that to reuse generic properties
    // and keep the property sequence of eventTemplate.
    if (static::$eventLast) {
      $this->event = static::$eventLast;
      return;
    }

    $eventTemplate =& static::$eventTemplate;
    $event = array_flip($eventTemplate);

    if (isset($eventTemplate['version'])) {
      $event[$eventTemplate['version']] = 1;
    }

    if (isset($eventTemplate['site_id'])) {
      $event[$eventTemplate['site_id']] = $siteId;
    }

    // Don't set canonical unless non-empty.
    if (isset($eventTemplate['canonical'])) {
      $canonical = static::configGet(static::CONFIG_DOMAIN, 'canonical', '');
      if ($canonical) {
        $event[$eventTemplate['canonical']] = $canonical;
      }
    }

    // Don't set tags unless non-empty.
    if (isset($eventTemplate['tags'])) {
      $tags = static::configGet(static::CONFIG_DOMAIN, 'tags');
      if ($tags) {
        $event[$eventTemplate['tags']] = join(',', $tags);
      }
    }

    if (isset($eventTemplate['type'])) {
      $event[$eventTemplate['type']] = static::configGet(static::CONFIG_DOMAIN, 'type', static::TYPE_DEFAULT);
    }

    if (isset($eventTemplate['method'])) {
      $event[$eventTemplate['method']] = !empty($_SERVER['REQUEST_METHOD']) ?
        static::sanitizeAscii($_SERVER['REQUEST_METHOD']) : 'cli';
    }

    if (isset($eventTemplate['request_uri'])) {
      if (!empty($_SERVER['REQUEST_URI'])) {
        $requestUri = static::sanitizeUnicode($_SERVER['REQUEST_URI']);
      }
      elseif (!empty($_SERVER['SCRIPT_NAME'])) {
        $requestUri = $_SERVER['SCRIPT_NAME'];
        // CLI.
        if (isset($_SERVER['argv'])) {
          if ($_SERVER['argv']) {
            $requestUri .= '?' . $_SERVER['argv'][0];
          }
        }
        elseif (!empty($_SERVER['QUERY_STRING'])) {
          $requestUri .= '?' . static::sanitizeUnicode($_SERVER['QUERY_STRING']);
        }
      }
      else {
        $requestUri = '/';
      }
      $event[$eventTemplate['request_uri']] = $requestUri;
    }

    if (isset($eventTemplate['referer'])) {
      $event[$eventTemplate['referer']] = !empty($_SERVER['HTTP_REFERER']) ?
        static::sanitizeUnicode($_SERVER['HTTP_REFERER']) : '';
    }

    if (isset($eventTemplate['client_ip'])) {
      $clientIp = !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
      if ($clientIp) {
        // Get list of configured trusted proxy IPs.
        $proxyIps = static::configGet(static::CONFIG_DOMAIN, 'reverse_proxy_addresses');
        if ($proxyIps) {
          // Get 'forwarded for' header, ideally listing
          // 'client, proxy-1, proxy-2, ...'.
          $proxyHeader = static::configGet(static::CONFIG_DOMAIN, 'reverse_proxy_header', 'HTTP_X_FORWARDED_FOR');
          if ($proxyHeader && !empty($_SERVER[$proxyHeader])) {
            $ips = str_replace(' ', '', static::sanitizeAscii($_SERVER[$proxyHeader]));
            if ($ips) {
              $ips = explode(',', $ips);
              // Append direct client IP, in case it is missing in the
              // 'forwarded for' header.
              $ips[] = $clientIp;
              // Remove trusted proxy IPs.
              $netIps = array_diff($ips, $proxyIps);
              if ($netIps) {
                // The right-most is the most specific.
                $clientIp = end($netIps);
              }
              else {
                // The 'forwarded for' header contained known proxy IPs only;
                // use the first of them
                $clientIp = reset($ips);
              }
            }
          }
        }
      }
      if ($clientIp && $clientIp != filter_var($clientIp, FILTER_VALIDATE_IP)) {
        $clientIp = '';
      }
      $event[$eventTemplate['client_ip']] = $clientIp;
    }

    $userAgent = '';
    if (isset($eventTemplate['useragent'])) {
      $event[$eventTemplate['useragent']] = !empty($_SERVER['HTTP_USER_AGENT']) ?
        static::multiByteSubString(static::sanitizeUnicode($_SERVER['HTTP_USER_AGENT']), 0, 100) : '';
    }

    // Establish configured truncation once and for all.
    $truncate = static::configGet(static::CONFIG_DOMAIN, 'truncate', static::TRUNCATE_DEFAULT);
    if ($truncate) {
      // Kb to bytes.
      $truncate *= 1024;
      // Substract estimated max length of everything but message content.
      $truncate -= 768;
      if ($userAgent) {
        $truncate -= strlen($userAgent);
      }
      // Message will get longer when JSON encoded, because of hex encoding of
      // <>&" chars.
      $truncate *= 7 / 8;
    }
    static::$eventTruncate = $truncate;

    $this->event =& $event;
  }

  /**
   * Sets event prop values that are common across events of the same request.
   *
   * @param integer $severity
   * @param mixed $message
   *   Gets stringed.
   * @param array $context
   *
   * @return void
   */
  protected function setEventSpecifics($severity, $message, $context = []) {
    $event =& $this->event;
    $eventTemplate =& static::$eventTemplate;

    // 'message' is the only mandatory property.
    $messageNTrunc = $this->eventMessageNTrunc($message, $context);
    $event[$eventTemplate['message']] = $messageNTrunc[0];

    if (isset($eventTemplate['trunc'])) {
      $event[$eventTemplate['trunc']] = $messageNTrunc[1];
    }

    if (isset($eventTemplate['timestamp'])) {
      // PHP formats iso 8601 with timezone; we use UTC Z.
      $millis = round(microtime(true) * 1000);
      $seconds = (int) floor($millis / 1000);
      $millis -= $seconds * 1000;
      $millis = str_pad($millis, 3, '0', STR_PAD_LEFT);
      $event[$eventTemplate['timestamp']] = substr(gmdate('c', $seconds), 0, 19) . '.' . $millis . 'Z';
    }

    if (isset($eventTemplate['subtype'])) {
      $event[$eventTemplate['subtype']] = $this->eventSubtype($context);
    }

    if (isset($eventTemplate['severity'])) {
      $event[$eventTemplate['severity']] = $severity;
    }

    // username is event specific, because it may change between log calls.
    if (isset($eventTemplate['username'])) {
      $event[$eventTemplate['username']] = $this->eventUsername($context);
    }

    if (isset($eventTemplate['code'])) {
      $event[$eventTemplate['code']] = $this->eventCode($context);
    }

    // 'message_id' must be set as last, because eventMessageId() algo may use
    // any event property(ies) to define unique identifier.
    if (isset($eventTemplate['message_id'])) {
      $event[$eventTemplate['message_id']] = $this->eventMessageId($event);
    }
  }

  /**
   * Truncates (if required), replaces arg context placeholders.
   *
   * @param mixed $message
   *   Will be stringed.
   * @param array $context
   *
   * @return array
   */
  protected function eventMessageNTrunc($message, array $context) {
    $trunc = null;
    // Make sure it's a string, before manipulating as such.
    $msg = '' . $message;
    if ($msg) {
      // Replace placeholder vars.
      if ($context) {
        $placeHolderPrefix = static::PLACEHOLDER_PREFIX;
        $placeHolderSuffix = static::PLACEHOLDER_SUFFIX;
        foreach ($context as $key => $val) {
          $msg = str_replace($placeHolderPrefix . $key . $placeHolderSuffix, static::plaintext($val), $msg);
        }
      }

      // Strip tags if message starts with < (Inspect logs in tag).
      if (
        !static::configGet(static::CONFIG_DOMAIN, 'keep_enclosing_tag')
        && $msg{0} === '<'
      ) {
        $msg = strip_tags($msg);
      }

      // Escape null byte.
      $msg = str_replace("\0", '_NUL_', $msg);

      // Truncate message.
      $truncate = static::$eventTruncate;
      // Deliberately multibyte length.
      if ($truncate && ($le = strlen($msg)) > $truncate) {
        // Truncate multibyte safe until ASCII length is equal to/less than max.
        // byte length.
        $trunc = array(
          $le,
          strlen($msg = static::truncateBytes($msg, $truncate))
        );
      }
    }
    return array(
      $msg,
      $trunc
    );
  }

  /**
   * @param array $context
   *
   * @return string
   */
  protected function eventSubtype(array $context) {
    return !empty($context['subtype']) ? $context['subtype'] : static::SUBTYPE_DEFAULT;
  }

  /**
   * @param array $context
   *
   * @return string
   */
  protected function eventUsername(array $context) {
    return !empty($context['username']) ? $context['username'] : '';
  }

  /**
   * @param array $context
   *
   * @return integer
   */
  protected function eventCode(array $context) {
    return !empty($context['code']) ? (int) $context['code'] : 0;
  }

  /**
   * @param array $event
   *   Must be fully populated (see $eventTemplate).
   *
   * @return string
   */
  protected function eventMessageId(array $event) {
    return uniqid(
      isset($eventTemplate['site_id']) ? $event[$eventTemplate['site_id']] : '',
      true
    );
  }

  /**
   * Establish path and file.
   *
   * Logs to default error_log if the dir (path really) cannot be established.
   *
   * @return string
   */
  protected function file() {
    $siteId = static::$siteId;
    if (!$siteId) {
      static::$siteId = $siteId = static::siteId();
    }

    $dir = static::configGet(static::CONFIG_DOMAIN, 'dir');
    if (!$dir) {
      $dir = static::dir();
    }
    if (!$dir) {
      error_log('jsonlog, site ID[' . $siteId . '], cannot establish log dir.');
      return '';
    }

    $fileTime = static::configGet(static::CONFIG_DOMAIN, 'file_time', 'Ymd');
    if ($fileTime == 'none') {
      $fileTime = '';
    }
    if ($fileTime) {
      $fileTime = '.' . date($fileTime);
    }

    return rtrim($dir, '/') . '/' . $siteId . $fileTime . '.json.log';
  }

  /**
   * @return boolean
   */
  protected function write() {
    $siteId = static::$siteId;
    if (!$siteId) {
      static::$siteId = $siteId = static::siteId();
    }

    $file = static::$file;
    if (!$file) {
      static::$file = $file = $this->file();
    }

    // File append, using lock (write, doesn't prevent reading).
    $success = file_put_contents(
      $file,
      json_encode($this->event, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
      FILE_APPEND | LOCK_EX
    );
    // If failure: log filing error to host's default log.
    if (!$success) {
      error_log('jsonlog, site ID[' . $siteId . '], failed to write to file[' . $file . '].');
    }

    return $success;
  }

  /**
   * @throws \Psr\Log\InvalidArgumentException
   *   Invalid level argument.
   *
   * @param mixed $level
   *   String (word): value as defined by Psr\Log\LogLevel class constants.
   *   Integer|stringed integer: between zero and seven; RFC 5424.
   *
   * @return string
   *   Equivalent to a Psr\Log\LogLevel class constant.
   */
  public static function severity($level) {
    // Support RFC 5424 integer as well as words defined by PSR-3.
    $severity = '' . $level;
    if (ctype_digit($level)) {
      switch ($severity) {
        case '' . LOG_EMERG:
          $severity = LogLevel::EMERGENCY;
          break;
        case '' . LOG_ALERT:
          $severity = LogLevel::ALERT;
          break;
        case '' . LOG_CRIT:
          $severity = LogLevel::CRITICAL;
          break;
        case '' . LOG_ERR:
          $severity = LogLevel::ERROR;
          break;
        case '' . LOG_WARNING:
          $severity = LogLevel::WARNING;
          break;
        case '' . LOG_NOTICE:
          $severity = LogLevel::NOTICE;
          break;
        case '' . LOG_INFO:
          $severity = LogLevel::INFO;
          break;
        case '' . LOG_DEBUG:
          $severity = LogLevel::DEBUG;
          break;
        default:
          throw new InvalidArgumentException('Invalid log level argument [' . $level . '].');
      }
    }
    else {
      switch ($severity) {
        case LogLevel::EMERGENCY;
        case LogLevel::ALERT;
        case LogLevel::CRITICAL;
        case LogLevel::ERROR;
        case LogLevel::WARNING;
        case LogLevel::NOTICE;
        case LogLevel::INFO;
        case LogLevel::DEBUG;
          // Level/severity A-OK.
          break;
        default:
          throw new InvalidArgumentException('Invalid log level argument [' . $level . '].');
      }
    }

    return $severity;
  }

  /**
   * Uses ini:error_log respectively server's default web log (plus '/jsonlog')
   * as fallback when conf var 'dir' not set.
   *
   * Attempts to save the directory to conf var 'dir'.
   *
   * @return string
   */
  public static function dir() {
    $dir = static::configGet(static::CONFIG_DOMAIN, 'dir');
    if ($dir) {
      return $dir;
    }

    // Default web server log dir for common *nix distributions.
    $defaultLogDirs = array(
      'debian' => '/var/log/apache2',
      'redhat' => '/var/log/httpd',
    );

    $dir = '';
    $hostLog = ini_get('error_log');
    if (!$hostLog || $hostLog === 'syslog') {
      // Try default web server log dirs for common *nix distributions.
      foreach ($defaultLogDirs as $val) {
        if (file_exists($val)) {
          $dir = $val;
          break;
        }
      }
    }
    elseif (DIRECTORY_SEPARATOR != '/') {
      $dir = str_replace('\\', '/', dirname($hostLog));
    }

    if ($dir) {
      $dir .= '/jsonlog';
      // Save it; kind of expensive to establish.
      static::configSet(static::CONFIG_DOMAIN, 'dir', $dir);

      return $dir;
    }

    return '';
  }

  /**
   * Get config var.

   *  Vars:
   *  - (int) threshold
   *  - (int) truncate
   *  - (str) siteid
   *  - (str) type
   *  - (str) dir
   *  - (str) file_time: date() pattern
   *  - (str) canonical
   *  - (str) tags: comma-separated list
   *  - (str) reverse_proxy_addresses: comma-separated list
   *  - (str) reverse_proxy_header: default HTTP_X_FORWARDED_FOR
   *  - (bool|int) keep_enclosing_tag
   *
   * This implementation attempts to get from server environment variables.
   * Their actual names will be prefixed by CONFIG_DOMAIN; example
   * lib_simplecomplex_jsonlog_jsonlog_siteid.
   * Beware that environment variables are always strings.
   *
   * @param string $domain
   *   Default: static::CONFIG_DOMAIN.
   * @param string $name
   * @param mixed $default
   *   Default: null.
   *
   * @return mixed
   *   String, unless no such var and arg default isn't string.
   */
  protected static function configGet($domain, $name, $default = null) {
    return ($val = getenv(($domain ? $domain : static::CONFIG_DOMAIN) . '_' . $name)) !== false ? $val : $default;
  }

  /**
   * This implementation does nothing, since you can't save an environment var.
   *
   * @param string $domain
   * @param string $name
   * @param mixed $value
   */
  protected static function configSet($domain, $name, $value) {
    //$key = ($domain ? $domain : static::CONFIG_DOMAIN) . '_' . $name;
  }

  /**
   * @param string $str
   *
   * @return string
   */
  protected static function plaintext($str) {
    return htmlspecialchars(strip_tags($str), ENT_QUOTES, 'UTF-8');
  }

  /**
   * @param string $str
   *
   * @return string
   */
  protected static function sanitizeAscii($str) {
    return filter_var($str, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
  }

  /**
   * @param string $str
   *
   * @return string
   */
  protected static function sanitizeUnicode($str) {
    return filter_var($str, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW);
  }

  /**
   * Multibyte-safe string length.
   *
   * @param string $str
   *
   * @return integer
   */
  protected static function multiByteStringLength($str) {
    static $mb = -1;
    if ($str === '') {
      return 0;
    }
    if ($mb == -1) {
      $mb = function_exists('mb_strlen');
    }
    if ($mb) {
      return mb_strlen($str);
    }

    $n = 0;
    $le = strlen($str);
    $leading = false;
    for ($i = 0; $i < $le; $i++) {
      // ASCII.
      if (($ord = ord($str{$i})) < 128) {
        ++$n;
        $leading = false;
      }
      // Continuation char.
      elseif ($ord < 192) {
        $leading = false;
      }
      // Leading char.
      else {
        // A sequence of leadings only counts as a single.
        if (!$leading) {
          ++$n;
        }
        $leading = true;
      }
    }
    return $n;
  }

  /**
   * Multibyte-safe sub string.
   *
   * @param string $str
   * @param integer $start
   * @param integer|null $length
   *    Default: null.
   *
   * @return string
   */
  protected static function multiByteSubString($str, $start, $length = null) {
    static $mb = -1;
    // Interprete non-null falsy length as zero.
    if ($str === '' || (!$length && $length !== null)) {
      return '';
    }

    if ($mb == -1) {
      $mb = function_exists('mb_substr');
    }
    if ($mb) {
      return !$length ? mb_substr($str, $start) : mb_substr($str, $start, $length);
    }

    // The actual algo (further down) only works when start is zero.
    if ($start > 0) {
      // Trim off chars before start.
      $str = substr($str, strlen(static::multiByteSubString($str, 0, $start)));
    }
    // And the algo needs a length.
    if (!$length) {
      $length = static::multiByteStringLength($str);
    }

    $n = 0;
    $le = strlen($str);
    $leading = false;
    for ($i = 0; $i < $le; $i++) {
      // ASCII.
      if (($ord = ord($str{$i})) < 128) {
        if ((++$n) > $length) {
          return substr($str, 0, $i);
        }
        $leading = false;
      }
      // Continuation char.
      elseif ($ord < 192) { // continuation char
        $leading = false;
      }
      // Leading char.
      else {
        // A sequence of leadings only counts as a single.
        if (!$leading) {
          if ((++$n) > $length) {
            return substr($str, 0, $i);
          }
        }
        $leading = true;
      }
    }
    return $str;
  }

  /**
   * Truncate multibyte safe until ASCII length is equal to/less than arg
   * length.
   *
   * Does not check if arg $str is valid UTF-8.
   *
   * @param string $str
   * @param integer $length
   *   Byte length (~ ASCII char length).
   *
   * @return string
   */
  protected static function truncateBytes($str, $length) {
    if (strlen($str) <= $length) {
      return $str;
    }

    // Truncate to UTF-8 char length (>= byte length).
    $str = static::multiByteSubString($str, 0, $length);
    // If all ASCII.
    if (($le = strlen($str)) == $length) {
      return $str;
    }

    // This algo will truncate one UTF-8 char too many,
    // if the string ends with a UTF-8 char, because it doesn't check
    // if a sequence of continuation bytes is complete.
    // Thus the check preceding this algo (actual byte length matches
    // required max length) is vital.
    do {
      --$le;
      // String not valid UTF-8, because never found an ASCII or leading UTF-8
      // byte to break before.
      if ($le < 0) {
        return '';
      }
      // An ASCII byte.
      elseif (($ord = ord($str{$le})) < 128) {
        // We can break before an ASCII byte.
        $ascii = true;
        $leading = false;
      }
      // A UTF-8 continuation byte.
      elseif ($ord < 192) {
        $ascii = $leading = false;
      }
      // A UTF-8 leading byte.
      else {
        $ascii = false;
        // We can break before a leading UTF-8 byte.
        $leading = true;
      }
    } while($le > $length || (!$ascii && !$leading));

    return substr($str, 0, $le);
  }
}
