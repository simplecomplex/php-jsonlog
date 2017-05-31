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
 * JsonLog event which pretty-prints  - but invalid - JSON,
 * and doesn't collapse newlines in the message.
 *
 * The JSON output is invalid because the message column is not JSON-encoded.
 *
 * @internal
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLogEventPretty extends JsonLogEvent
{
    /**
     * Produces invalid JSON.
     *
     * Invalid because:
     * - multi-lined (pretty)
     * - 'message' not JSON-encoded
     *
     * @param array $event
     *
     * @return string
     */
    public function format(array $event) : string {
        // Move 'message' to bottom, no matter what COLUMNS_EVENT says.
        // And do not JSON-encode 'message' at all.
        $message = $event['message'];
        unset($event['message']);
        $event['message'] = '';

        $formatted = json_encode(
            $event,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );

        return '' . preg_replace(
            '/\n([ \t]+)(\"message\":[ ]*\")\"/',
                "\n" . '$1$2' . "\n" . $message . "\n" . '$1"',
                $formatted,
                1
            );
    }
}
