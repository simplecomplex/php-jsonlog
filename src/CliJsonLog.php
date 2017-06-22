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
 * php json_log.phpsh json-log-committable --enable --commit --verbose --pretty
 * @endcode
 *
 * @see JsonLog::committable()
 *
 * @package SimpleComplex\JsonLog
 */
class CliJsonLog implements CliCommandInterface
{
    const CLASS_JSON_LOG = JsonLog::class;

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

        // Declare provided commands.
        (CliEnvironment::getInstance())->addCommandsAvailable(
            new CliCommand(
                $this,
                static::COMMAND_PROVIDER_ALIAS . '-committable',
                'Check/enable JsonLog to write logs.',
                [],
                [
                    'verbose' => 'List success+message+code response.',
                    'enable' => 'Attempt to make committable if not.',
                    'commit' => 'Commit (write to log) on success.',
                ],
                [
                    'v' => 'verbose',
                    'e' => 'enable',
                    'c' => 'commit',
                ]
            )
        );
    }

    /**
     * @return \SimpleComplex\JsonLog\JsonLog
     */
    protected function getMainInstance()
    {
        return JsonLog::getInstance();
    }

    /**
     * @param CliCommand $command
     *
     * @return void
     *      Must exit.
     *
     * @throws \LogicException
     *      If the command mapped by CliEnvironment
     *      isn't this provider's command.
     */
    public function executeCommand(CliCommand $command)
    {
        $environment = CliEnvironment::getInstance();

        if ($command->inputErrors) {
            foreach ($command->inputErrors as $msg) {
                $environment->echoMessage(
                    $environment->format($msg, 'hangingIndent'),
                    'notice'
                );
            }
            // This command's help text.
            $environment->echoMessage("\n" . $command);
            exit;
        }

        switch ($command->name) {
            case static::COMMAND_PROVIDER_ALIAS . '-committable':
                $verbose = !empty($command->options['verbose']);
                $logger = $this->getMainInstance();
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
                $environment->echoMessage(
                    $environment->format($msg, 'hangingIndent'),
                    !$success ? 'warning' : 'success'
                );
                exit;
            default:
                throw new \LogicException(
                    'Command named[' . $command->name . '] is not provided by class[' . get_class($this) . '].'
                );
        }
    }
}
