<?php

declare(strict_types=1);
/*
 * Scalar parameter type declaration is a no-go until everything is strict (coercion or TypeError?).
 */

namespace SimpleComplex\JsonLog;

/**
 * PSR-3 logger which files events as 'pretty' JSON.
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
