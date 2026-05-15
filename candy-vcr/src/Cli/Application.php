<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

/**
 * Subcommand router for `bin/candy-vcr`. Dispatches to one of the
 * registered {@see Command} implementations based on the first
 * positional arg.
 */
final class Application
{
    /** @var array<string, Command> */
    private array $commands;

    public function __construct(?array $commands = null)
    {
        $this->commands = $commands ?? [
            'inspect' => new InspectCommand(),
            'replay' => new ReplayCommand(),
            'diff' => new DiffCommand(),
            'stats' => new StatsCommand(),
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
        return $this->commands[$name]->run($rest, $stdout, $stderr);
    }

    /**
     * @param resource $out
     */
    private function printUsage($out): void
    {
        fwrite($out, "usage: candy-vcr <subcommand> [options...]\n\n");
        fwrite($out, "subcommands:\n");
        foreach ($this->commands as $name => $cmd) {
            fwrite($out, sprintf("  %-10s %s\n", $name, $cmd->summary()));
        }
    }
}
