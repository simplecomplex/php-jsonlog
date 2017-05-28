<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

/**
 * JsonLog event which pretty-prints JSON,
 * and doesn't collapse newlines in the message.
 *
 * @internal
 *
 * @package SimpleComplex\JsonLog
 */
class JsonLogEventPretty extends JsonLogEvent
{
    /**
     * @param array $event
     *
     * @return string
     */
    public function format(array $event) : string {
        // Move message to bottom, no matter what COLUMNS_EVENT says.
        $message = $event['message'];
        unset($event['message']);
        $event['message'] = '';

        $formatted = json_encode(
            $event,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );

        return '' . preg_replace(
            '/(\n[ \t]+\"message\":[ ]*\")\"/',
                '$1' . $message . '"',
                $formatted,
                1
            );
    }
}
