<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Subcommand router for `bin/candy-vcr`. Dispatches to one of the
 * registered {@see Command} implementations based on the first
 * positional arg.
 */
final class Application
{
    /** @var array<string, Command|SymfonyCommand> */
    private array $commands;

    /**
     * @param array<string, Command|SymfonyCommand>|null $commands
     */
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

        if ($command instanceof SymfonyCommand) {
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
        SymfonyCommand $command,
        array $argv,
        $stdout,
        $stderr,
    ): int {
        $symfonyApp = new SymfonyApp();
        $symfonyApp->setAutoExit(false);
        $symfonyApp->add($command);

        $name = $command->getName() ?? '';
        $argString = $name;
        foreach ($argv as $arg) {
            $argString .= ' ' . self::escapeArg($arg);
        }
        $input = new StringInput($argString);

        $output = new StreamOutput($stdout);

        return $symfonyApp->run($input, $output);
    }

    private static function escapeArg(string $arg): string
    {
        if ($arg === '') {
            return "''";
        }
        if (preg_match('/^[A-Za-z0-9_\-\.\/=@:]+$/', $arg) === 1) {
            return $arg;
        }
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }

    /**
     * @param resource $out
     */
    private function printUsage($out): void
    {
        fwrite($out, "usage: candy-vcr <subcommand> [options...]\n\n");
        fwrite($out, "subcommands:\n");
        foreach ($this->commands as $name => $cmd) {
            if ($cmd instanceof SymfonyCommand) {
                fwrite($out, sprintf("  %-14s %s\n", $name, $cmd->getDescription()));
            } else {
                fwrite($out, sprintf("  %-10s %s\n", $name, $cmd->summary()));
            }
        }
    }
}
