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
use SimpleComplex\Utils\Dependency;

/**
 * CLI only.
 *
 * Expose/execute JsonLog commands.
 *
 * @see simplecomplex_json_log_cli()
 *
 * @see JsonLog::committable()
 *
 * @code
 * # CLI
 * cd vendor/simplecomplex/json-log/src/cli
 * php cli.phpsh json-log -h
 * @endcode
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
     * @var string
     */
    const CLASS_JSON_LOG = JsonLog::class;

    /**
     * Registers JsonLog CliCommands at CliEnvironment.
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
        (CliEnvironment::getInstance())->registerCommands(
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
            ),
            new CliCommand(
                $this,
                static::COMMAND_PROVIDER_ALIAS . '-truncate',
                'Truncate current JsonLog log file.',
                [],
                [],
                []
            )
        );
    }


    // CliCommandInterface.-----------------------------------------------------

    /**
     * @return string
     */
    public function commandProviderAlias(): string
    {
        return static::COMMAND_PROVIDER_ALIAS;
    }

    /**
     * @param CliCommand $command
     *
     * @return mixed
     *      Return value of the executed command, if any.
     *      May well exit.
     *
     * @throws \LogicException
     *      If the command mapped by CliEnvironment
     *      isn't this provider's command.
     */
    public function executeCommand(CliCommand $command)
    {
        $environment = CliEnvironment::getInstance();
        /**
         * @see simplecomplex_json_log_cli()
         */
        $container = Dependency::container();

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
                // Get JsonLog from dependency injection container, if set.
                $json_log = null;
                if (
                    !$container->has('logger')
                    || !($json_log = $container->get('logger'))
                    || !is_a($json_log, static::CLASS_JSON_LOG)
                ) {
                    $json_log_class = static::CLASS_JSON_LOG;
                    $json_log = new $json_log_class();
                }
                $response = $json_log->committable(
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
                // Do not respect 'silent', do print even success.
                $environment->echoMessage(
                    $environment->format($msg, 'hangingIndent'),
                    !$success ? 'warning' : 'success'
                );
                exit;
            case static::COMMAND_PROVIDER_ALIAS . '-truncate':
                // Request confirmation, unless user used the --yes/-y option.
                if (
                    !$command->preConfirmed
                    && !$environment->confirm(
                        'Truncate current JsonLog log file? Type \'yes\' or \'y\' to continue:',
                        ['yes', 'y'],
                        '',
                        'Aborted truncating JsonLog log file.'
                    )
                ) {
                    exit;
                }
                // Get JsonLog from dependency injection container, if set.
                $json_log = null;
                if (
                    !$container->has('logger')
                    || !($json_log = $container->get('logger'))
                    || !is_a($json_log, static::CLASS_JSON_LOG)
                ) {
                    $json_log_class = static::CLASS_JSON_LOG;
                    $json_log = new $json_log_class();
                }
                $path_file = $json_log->truncate();
                $msg = !$path_file ? 'Failed to truncate JsonLog current log file.' :
                    ('Truncated JsonLog current log file: ' . dirname($path_file)
                        . '/' . $environment->format(basename($path_file), 'emphasize')
                    );
                $environment->echoMessage(
                    $environment->format($msg, 'hangingIndent'),
                    !$path_file ? 'warning' : 'success'
                );
                exit;
            default:
                throw new \LogicException(
                    'Command named[' . $command->name . '] is not provided by class[' . get_class($this) . '].'
                );
        }
    }
}
