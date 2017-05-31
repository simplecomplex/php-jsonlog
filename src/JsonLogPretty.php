<?php
/**
 * SimpleComplex PHP JsonLog
 * @link      https://github.com/simplecomplex/php-jsonlog
 * @copyright Copyright (c) 2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-jsonlog/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\JsonLog;

/**
 * PSR-3 logger which files events as 'pretty' - but invalid - JSON.
 *
 * The JSON output is invalid because the message column is not JSON-encoded.
 *
 * For development purposes only.
 * The JSON output is not compatible with collectors like logstash and beats,
 * because:
 * - multi-lined (pretty)
 * - 'message' not JSON-encoded
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLogPretty extends JsonLog
{
    /**
     * @var string
     */
    const CLASS_JSON_LOG_EVENT = JsonLogEventPretty::class;
}
