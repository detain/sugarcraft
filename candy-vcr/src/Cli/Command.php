<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

/**
 * Base shape for `bin/candy-vcr` subcommands.
 */
interface Command
{
    /**
     * Execute the subcommand. Receives positional args (without the
     * leading subcommand name). Writes user-facing output to $stdout
     * and diagnostic / error messages to $stderr.
     *
     * @param list<string> $args
     * @param resource $stdout
     * @param resource $stderr
     * @return int Exit code (0 = success).
     */
    public function run(array $args, $stdout, $stderr): int;

    /**
     * One-line description for `bin/candy-vcr` usage output.
     */
    public function summary(): string;
}
