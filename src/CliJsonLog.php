<?php
/**
 * SimpleComplex PHP JsonLog
 * @link      https://github.com/simplecomplex/php-jsonlog
 * @copyright Copyright (c) 2014-2017 Jacob Friis Mathiasen
 * @license   https://github.com/simplecomplex/php-jsonlog/blob/master/LICENSE (MIT License)
 */
declare(strict_types=1);

namespace SimpleComplex\JsonLog;

use SimpleComplex\Utils\CliCommandInterface;
use SimpleComplex\Utils\CliEnvironment;
use SimpleComplex\Utils\CliCommand;

/**
 * CLI only.
 *
 * Expose/execute JsonLog 'committable' command.
 *
 * Example:
 * @code
 * cd vendor/simplecomplex/json-log/src/cli
 * # Execute 'committable' command.
 * php json_log.phpsh committable --enable --commit --verbose --pretty
 * @endcode
 *
 * @see JsonLog::committable()
 *
 * @package SimpleComplex\JsonLog
 */
class CliJsonLog implements CliCommandInterface
{
    /**
     * @var string
     */
    const COMMAND_PROVIDER_ALIAS = 'json-log';

    /**
     * Uses CliEnvironment/CliCommand to detect and execute commands.
     *
     * @throws \LogicException
     *      If executed in non-CLI mode.
     */
    public function __construct()
    {
        if (!CliEnvironment::cli()) {
            throw new \LogicException('Cli mode only.');
        }

        $environment = CliEnvironment::getInstance();
        // Declare supported commands.
        $environment->addCommandsAvailable(
            new CliCommand(
                static::COMMAND_PROVIDER_ALIAS,
                'committable',
                'Check/enable JsonLog to write logs.',
                [],
                [
                    'verbose' => 'List success+message+code response.',
                    'enable' => 'Attempt to make committable if not.',
                    'commit' => 'Commit (write to log) on success.',
                    'pretty' => 'Use \'pretty\'-formatted JSON.',
                ],
                [
                    'v' => 'verbose',
                    'e' => 'enable',
                    'c' => 'commit',
                    'p' => 'pretty',
                ]
            )
        );
    }

    /**
     * @param CliCommand|null $command
     */
    public function executeCommandOnMatch($command)
    {
        if ($command && $command->provider == static::COMMAND_PROVIDER_ALIAS) {
            switch ($command->name) {
                case 'committable':
                    $verbose = !empty($command->options['verbose']);
                    $logger = empty($command->options['pretty']) ?
                        JsonLog::getInstance() :
                        JsonLogPretty::getInstance();
                    $response = $logger->committable(
                        !empty($command->options['enable']),
                        !empty($command->options['commit']),
                        $verbose
                    );
                    if (!$verbose) {
                        $success = $response;
                    } else {
                        $success = $response['success'];
                    }
                    if (!$verbose) {
                        $msg = !$success ? 'JsonLog is NOT committable.' : 'JsonLog is committable.';
                    } else {
                        $msg = $response['message'];
                        if (!$success) {
                            $msg .= "\n" . 'Code: ' . $response['code'];
                        }
                    }

                    $environment = CliEnvironment::getInstance();

                    $environment->echoMessage($msg, !$success ? 'warning' : 'success', true);
                    break;
                default:
                    return;
            }
            exit;
        }
    }
}
