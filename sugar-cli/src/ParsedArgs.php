<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * The successful result of {@see CommandSpec::parse()}: the selected command
 * path, every declared option's resolved value with its source, and the
 * positional arguments.
 *
 * Every option declared on the command path has an entry, so {@see value()}
 * never needs a fallback at the call site: an option nobody supplied reports
 * its default, or the kind's empty value (`false` or `0` for a flag, `null`
 * or `[]` for a value option).
 *
 * Original work: no charmbracelet upstream parses argv.
 */
final class ParsedArgs
{
    /**
     * @param list<string> $command
     * @param array<string, mixed> $values
     * @param array<string, ValueSource> $sources
     * @param list<string> $arguments
     */
    private function __construct(
        private readonly array $command,
        private readonly array $values,
        private readonly array $sources,
        private readonly array $arguments,
    ) {}

    /**
     * @param list<string> $command  command path, root first
     * @param array<string, mixed> $values  long name => resolved value, for every declared option
     * @param array<string, ValueSource> $sources  long name => source, for options that resolved one
     * @param list<string> $arguments  positionals in order
     */
    public static function new(array $command, array $values, array $sources, array $arguments): self
    {
        return new self(array_values($command), $values, $sources, array_values($arguments));
    }

    /**
     * Selected command path, root first (`['git', 'remote', 'add']`).
     *
     * @return list<string>
     */
    public function command(): array
    {
        return $this->command;
    }

    /** True when the option came from argv or its env variable (a default does not count). */
    public function has(string $name): bool
    {
        $this->assertKnown($name);
        $source = $this->sources[$name] ?? null;
        return $source === ValueSource::Argv || $source === ValueSource::Env;
    }

    /**
     * Resolved value of an option by long name.
     *
     * @throws \InvalidArgumentException when no option of that name is part of this command's parse
     *         (its own options, persistent ancestors, and an ancestor option typed before the subcommand)
     */
    public function value(string $name): mixed
    {
        $this->assertKnown($name);
        return $this->values[$name];
    }

    /** Where the value came from, or null when nothing (not even a default) supplied one. */
    public function source(string $name): ?ValueSource
    {
        $this->assertKnown($name);
        return $this->sources[$name] ?? null;
    }

    /**
     * Every declared option's resolved value, keyed by long name. PHP turns
     * an all-digit name such as `5` into an int key; cast it with
     * `(string)` before passing it on as a name.
     *
     * @return array<array-key, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Positional arguments in order, including everything after `--`.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    private function assertKnown(string $name): void
    {
        if (!array_key_exists($name, $this->values)) {
            throw new \InvalidArgumentException(sprintf('No option "--%s" is declared on this command.', $name));
        }
    }
}
