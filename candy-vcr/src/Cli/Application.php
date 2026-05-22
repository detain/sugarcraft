<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Subcommand router for `bin/candy-vcr`. Dispatches to one of the
 * registered {@see Command} implementations based on the first
 * positional arg.
 */
final class Application
{
    /** @var array<string, Command|Symfony\Component\Console\Command\Command> */
    private array $commands;

    public function __construct(?array $commands = null)
    {
        $this->commands = $commands ?? [
            'record' => new RecordCommand(),
            'inspect' => new InspectCommand(),
            'replay' => new ReplayCommand(),
            'diff' => new DiffCommand(),
            'stats' => new StatsCommand(),
            'migrate' => new MigrateCommand(),
            'render-tape' => new RenderTapeCommand(),
            'render-batch' => new RenderBatchCommand(),
        ];
    }

    /**
     * @param list<string> $argv  Full argv from the bin script (argv[0] is the script).
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $args = array_slice($argv, 1);
        if ($args === [] || in_array($args[0], ['-h', '--help', 'help'], true)) {
            $this->printUsage($stdout);
            return $args === [] ? 2 : 0;
        }

        $name = $args[0];
        $rest = array_slice($args, 1);
        if (!isset($this->commands[$name])) {
            fwrite($stderr, "candy-vcr: unknown subcommand '{$name}'\n\n");
            $this->printUsage($stderr);
            return 2;
        }

        $command = $this->commands[$name];

        if ($command instanceof \Symfony\Component\Console\Command\Command) {
            return $this->runSymfonyCommand($command, $rest, $stdout, $stderr);
        }

        return $command->run($rest, $stdout, $stderr);
    }

    /**
     * @param list<string> $argv
     * @param resource $stdout
     * @param resource $stderr
     */
    private function runSymfonyCommand(
        \Symfony\Component\Console\Command\Command $command,
        array $argv,
        $stdout,
        $stderr,
    ): int {
        $symfonyApp = new SymfonyApp();
        $symfonyApp->setAutoExit(false);
        $symfonyApp->add($command);

        $input = new ArrayInput(array_merge(['command' => $command->getName()], $argv));

        $output = new \Symfony\Component\Console\Output\StreamOutput($stdout);
        $errOutput = new \Symfony\Component\Console\Output\StreamOutput($stderr);

        $exitCode = $symfonyApp->run($input, $output);

        return is_int($exitCode) ? $exitCode : ($exitCode === null ? 0 : 1);
    }

    /**
     * @param resource $out
     */
    private function printUsage($out): void
    {
        fwrite($out, "usage: candy-vcr <subcommand> [options...]\n\n");
        fwrite($out, "subcommands:\n");
        foreach ($this->commands as $name => $cmd) {
            if ($cmd instanceof \Symfony\Component\Console\Command\Command) {
                fwrite($out, sprintf("  %-14s %s\n", $name, $cmd->getDescription() ?? ''));
            } else {
                fwrite($out, sprintf("  %-10s %s\n", $name, $cmd->summary()));
            }
        }
    }
}
