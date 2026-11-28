<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * The successful result of {@see CommandSpec::parse()}: the selected command
 * path, the resolved value and source of every option in that command's
 * parse, and the positional arguments.
 *
 * The parse holds the selected command's own options and its ancestors'
 * persistent ones; a non-persistent ancestor option never joins it, even when
 * argv typed it at its own command's level, before the subcommand. Every
 * option in the parse has an entry, so {@see value()} needs no fallback at the
 * call site: one nobody supplied reports its default, or the kind's empty
 * value (`false` or `0` for a flag, `null` or `[]` for a value option). Any
 * other name, a non-persistent ancestor option included, throws.
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
     * @param array<string, mixed> $values  long name => resolved value, for every option in the command's parse
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
     *         (its own options and its ancestors' persistent ones)
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
     * Resolved value of every option in this command's parse (see the class
     * docblock), keyed by long name. PHP turns
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
