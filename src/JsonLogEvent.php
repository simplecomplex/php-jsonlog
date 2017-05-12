<?php

namespace SimpleComplex\JsonLog;

use Psr\Log\LogLevel;
use Psr\Log\InvalidArgumentException;

/**
 * JsonLog event.
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLogEvent {

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
   * Default max. byte length of the 'message' column, in kilobytes.
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
   * List of site/system columns.
   *
   * Key is the property's get method; value is the property's name when logged.
   * Beware: Fatal error if a method doesn't exist.
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
   * @var array
   */
  protected static $columnsSite = array(
    'type' => 'type',
    'host' => 'host',
    'siteId' => 'site_id',
    'canonical' => 'canonical',
    'tags' => 'tags',
  );

  /**
   * List of request/process columns.
   *
   * @see JsonLog::$columnsSite
   *
   * @var array
   */
  protected static $columnsRequest = array(
    'method' => 'method',
    'requestUri' => 'request_uri',
    'referer' => 'referer',
    'clientIp' => 'client_ip',
    'userAgent' => 'useragent',
  );

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
   * 'truncation' has to go after 'message', otherwise it will always be empty;
   * that is: unknown.
   *
   * @see JsonLog::$columnsSite
   *
   * @var array
   */
  protected static $columnsEvent = array(
    'message' => 'message',
    'timestamp' => '@timestamp',
    'eventId' => 'message_id',
    'subType' => 'subtype',
    'severity' => 'severity',
    'code' => 'code',
    'truncation' => 'trunc',
    'userName' => 'username',
  );

  /**
   * Lists the sequence of column groups (site, request, event).
   *
   * @var array
   */
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
   * @var string
   */
  protected $level = '';

  /**
   * @var integer
   */
  protected $severity = LOG_DEBUG;

  /**
   * @var string
   */
  protected $messageRaw = '';

  /**
   * @var array
   */
  protected $context = array();

  /**
   * @var integer
   */
  protected $lengthPrepared = 0;

  /**
   * @var integer
   */
  protected $lengthTruncated = 0;

  /**
   * @param mixed $level
   *   String (word): value as defined by Psr\Log\LogLevel class constants.
   *   Integer|stringed integer: between zero and seven; RFC 5424.
   * @param string $message
   *   Placeholder {word}s must correspond to keys in the context argument.
   * @param array $context
   */
  public function __construct($level, $message, array $context = array()) {
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
   * @return boolean
   */
  public function severe() {
    $threshold = static::$threshold;
    if ($threshold == -1) {
      static::$threshold = $threshold = static::configGet(static::CONFIG_DOMAIN, 'threshold', static::THRESHOLD_DEFAULT);
    }
    // Less is more.
    return $this->severity <= $threshold;
  }

  /**
   * @return array
   */
  public function get() {
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
   * @return string
   */
  public function type() {
    return static::configGet(static::CONFIG_DOMAIN, 'type', static::TYPE_DEFAULT);
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
  public function host() {
    return !empty($_SERVER['SERVER_NAME']) ? static::sanitizeUnicode($_SERVER['SERVER_NAME']) : '';
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
   * Attempts to save site ID to conf var 'siteid', unless truthy arg noSave.
   *
   * @param boolean $noSave
   *   Default: false; do save.
   *
   * @return string
   */
  public function siteId($noSave = false) {
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
        if (!$site_id) {
          $site_id = 'unknown';
        }
        elseif (!$noSave) {
          // Save it; kind of expensive to establish.
          static::configSet(static::CONFIG_DOMAIN, 'siteid', $site_id);
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
  public function canonical() {
    return static::configGet(static::CONFIG_DOMAIN, 'canonical', '');
  }

  /**
   * @return string
   */
  public function tags() {
    $tags = static::configGet(static::CONFIG_DOMAIN, 'tags');

    return !$tags ? '' : (is_array($tags) ? join(',', $tags) : ('' . $tags));
  }


  // Request column getters.----------------------------------------------------

  /**
   * HTTP request method (uppercase), or 'cli' (lowercase).
   *
   * @return string
   */
  public function method() {
    return !empty($_SERVER['REQUEST_METHOD']) ? preg_replace('/[^A-Z]/', '', $_SERVER['REQUEST_METHOD']) : 'cli';
  }

  /**
   * In cli mode: the line executed.
   *
   * @return string
   */
  public function requestUri() {
    if (isset($_SERVER['REQUEST_URI'])) {
      return static::sanitizeUnicode($_SERVER['REQUEST_URI']);
    }
    if (PHP_SAPI == 'cli' && isset($_SERVER['argv'])) {
      return join(' ', $_SERVER['argv']);
    }
    return '/';
  }

  /**
   * @return string
   */
  public function referer() {
    return !empty($_SERVER['HTTP_REFERER']) ? static::sanitizeUnicode($_SERVER['HTTP_REFERER']) : '';
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
  public function clientIp() {
    $client_ip = !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if ($client_ip) {
      // Get list of configured trusted proxy IPs.
      $proxy_ips = static::configGet(static::CONFIG_DOMAIN, 'reverse_proxy_addresses');
      if ($proxy_ips) {
        // Get 'forwarded for' header, ideally listing
        // 'client, proxy-1, proxy-2, ...'.
        $proxy_header = static::configGet(static::CONFIG_DOMAIN, 'reverse_proxy_header', 'HTTP_X_FORWARDED_FOR');
        if ($proxy_header && !empty($_SERVER[$proxy_header])) {
          $ips = str_replace(' ', '', static::sanitizeAscii($_SERVER[$proxy_header]));
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
  public function userAgent() {
    return !empty($_SERVER['HTTP_USER_AGENT']) ?
      static::sanitizeUnicode(static::multiByteSubString($_SERVER['HTTP_USER_AGENT'], 0, static::USER_AGENT_TRUNCATE)) : '';
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
  public function message() {
    // Get raw message, and clear instance var to save memory.
    $msg = $this->messageRaw;
    $this->messageRaw = '';

    if ($msg === '') {
      return '';
    }

    // Replace placeholder vars.
    $context =& $this->context;
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

    // Truncation.
    $le = strlen($msg);
    if (!$le) {
      return '';
    }

    $this->lengthPrepared = $le;

    $truncate = static::$truncate;
    if ($truncate == -1) {
      $truncate = static::configGet(static::CONFIG_DOMAIN, 'truncate', static::TRUNCATE_DEFAULT);
      if ($truncate) {
        // Kb to bytes.
        $truncate *= 1024;
        // Substract estimated max length of everything but message content.
        $truncate -= 768;
        // Useragent length is customizable; may be used but anyway.
        $truncate -= static::USER_AGENT_TRUNCATE;
        // Message will get longer when JSON encoded, because of hex encoding of
        // <>&" chars.
        $truncate *= 7 / 8;
      }
      static::$truncate = $truncate;
    }

    if ($truncate && $le > $truncate) {
      $msg = static::truncateBytes($msg, $truncate);
      $this->lengthTruncated = strlen($msg);
    }

    return $msg;
  }

  /**
   * @return string
   */
  public function truncation() {
    return !$this->lengthTruncated ? '' : ('' . $this->lengthPrepared . '/' . $this->lengthTruncated);
  }

  /**
   * @var boolean
   */
  const TIMESTAMP_UTC = false;

  /**
   * Iso 8601 datetime, including milliseconds.
   *
   * Local time + timezone, unless TIMESTAMP_UTC (then UTC no-zone Z).
   *
   * @return string
   */
  public function timestamp() {
    // PHP formats iso 8601 with timezone; we use UTC Z.
    $millis = round(microtime(true) * 1000);
    $seconds = (int) floor($millis / 1000);
    $millis -= $seconds * 1000;
    $millis = str_pad($millis, 3, '0', STR_PAD_LEFT);
    
    // PHP date('c') formats iso 8601 with timezone.
    if (!static::TIMESTAMP_UTC) {
      $local = date('c', $seconds);
      return substr($local, 0, 19) . '.' . $millis . substr($local, 19);
    }
    return substr(gmdate('c', $seconds), 0, 19) . '.' . $millis . 'Z';
  }

  /**
  'eventId' => 'message_id',
  'subType' => 'subtype',
  'severity' => 'severity',
  'code' => 'code',
  'truncation' => 'trunc',
  'userName' => 'username',
   */


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
   *
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
  }*/

  /**
   * PSR LogLevel doesn't define numeric values of levels,
   * but RFC 5424 'emergency' is 0 and 'debug' is 7.
   *
   * @see \Psr\Log\LogLevel
   *
   * @var array
   */
  protected static $levelBySeverity = array(
    LogLevel::EMERGENCY,
    LogLevel::ALERT,
    LogLevel::CRITICAL,
    LogLevel::ERROR,
    LogLevel::WARNING,
    LogLevel::NOTICE,
    LogLevel::INFO,
    LogLevel::DEBUG,
  );

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
  protected static function levelToString($level) {
    // Support RFC 5424 integer as well as words defined by PSR-3.
    $lvl = '' . $level;

    // RFC 5424 integer.
    if (ctype_digit($lvl)) {
      if ($lvl >= 0 && $lvl < count(self::$levelBySeverity)) {
        return self::$levelBySeverity[$lvl];
      }
    }
    // Word defined by PSR-3.
    elseif (in_array($lvl, self::$levelBySeverity)) {
      return $lvl;
    }

    throw new InvalidArgumentException('Invalid log level argument [' . $level . '].');
  }

  /**
   * @throws \Psr\Log\InvalidArgumentException
   *   Invalid level argument.
   *
   * @param mixed $level
   *   String (word): value as defined by Psr\Log\LogLevel class constants.
   *   Integer|stringed integer: between zero and seven; RFC 5424.
   *
   * @return integer
   */
  protected static function levelToInteger($level) {
    // Support RFC 5424 integer as well as words defined by PSR-3.
    $lvl = '' . $level;

    if (ctype_digit($lvl)) {
      if ($lvl >= 0 && $lvl < count(self::$levelBySeverity)) {
        return (int) $lvl;
      }
    }
    else {
      $index = array_search($lvl, self::$levelBySeverity);
      if ($index !== false) {
        return $index;
      }
    }

    throw new InvalidArgumentException('Invalid log level argument [' . $level . '].');
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


  // String manipulators.-------------------------------------------------------
  // @todo: Move string string manipulators to separate library.

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
