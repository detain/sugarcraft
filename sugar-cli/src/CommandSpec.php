<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Cli\Internal\SpecParser;
use SugarCraft\Core\Concerns\Mutable;

/**
 * Declarative description of a command: its options, subcommands, the
 * rules between options (requires, conflicts) and how many positional
 * arguments it takes. {@see parse()} turns an argv list into
 * {@see ParsedArgs} or a {@see UsageError}. It is meant to be the single
 * input that help, completions and man pages are generated from once those
 * generators land; today only parse() reads it.
 *
 *     $spec = CommandSpec::new('candy-code')
 *         ->withOption(Option::value('model')->withShort('m')->withEnv('CANDY_CODE_MODEL'))
 *         ->withOption(Option::flag('json'))
 *         ->withOption(Option::flag('plain'))
 *         ->conflicts('json', 'plain')
 *         ->withSubcommand(CommandSpec::new('run')->withMaxArguments(1));
 *
 * Immutable: every builder method returns a new instance. A malformed spec
 * (a duplicate name, a rule naming an undeclared option) throws, because it
 * is a programmer error; bad user input never throws, it returns a
 * {@see UsageError}.
 *
 * Original work: no charmbracelet upstream parses argv (fang hands its
 * command tree to spf13/cobra). The parse rules follow common GNU/POSIX
 * practice: `--name value`, `--name=value`, `-x value`, `-xvalue`, bundled
 * short flags `-abc`, and `--` ending option parsing.
 */
final class CommandSpec
{
    use Mutable;

    /**
     * @param array<string, Option> $options  keyed by long name, in declaration order
     * @param array<string, CommandSpec> $subcommands  keyed by name, in declaration order
     * @param list<list<string>> $conflictSets
     * @param array<string, list<string>> $requirements
     */
    private function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $options,
        private readonly array $subcommands,
        private readonly array $conflictSets,
        private readonly array $requirements,
        private readonly int $minArguments,
        private readonly ?int $maxArguments,
        private readonly bool $maxArgumentsSet,
    ) {}

    /**
     * @throws \InvalidArgumentException when `$name` is empty, starts with `-`, or contains whitespace
     */
    public static function new(string $name): self
    {
        if (preg_match('/^[^\s-]\S*\z/u', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Command name must be non-empty, must not start with "-" and must not contain whitespace, got "%s".',
                $name,
            ));
        }
        return new self($name, '', [], [], [], [], 0, null, false);
    }

    /** One-line summary for help and completion output. */
    public function withDescription(string $description): self
    {
        return $this->mutate(['description' => $description]);
    }

    /**
     * Declare an option. Long and short names must be unique on this command.
     *
     * @throws \InvalidArgumentException on a duplicate long or short name
     */
    public function withOption(Option $option): self
    {
        if (isset($this->options[$option->name()])) {
            throw new \InvalidArgumentException(sprintf('Option "--%s" is already declared on "%s".', $option->name(), $this->name));
        }
        $short = $option->short();
        if ($short !== null) {
            foreach ($this->options as $existing) {
                if ($existing->short() === $short) {
                    throw new \InvalidArgumentException(sprintf(
                        'Short option "-%s" is already used by "--%s" on "%s".',
                        $short,
                        $existing->name(),
                        $this->name,
                    ));
                }
            }
        }
        return $this->mutate(['options' => $this->options + [$option->name() => $option]]);
    }

    /**
     * Declare a subcommand, selected by its name as the first positional.
     *
     * @throws \InvalidArgumentException on a duplicate subcommand name
     */
    public function withSubcommand(CommandSpec $subcommand): self
    {
        if (isset($this->subcommands[$subcommand->name()])) {
            throw new \InvalidArgumentException(sprintf('Subcommand "%s" is already declared on "%s".', $subcommand->name(), $this->name));
        }
        return $this->mutate(['subcommands' => $this->subcommands + [$subcommand->name() => $subcommand]]);
    }

    /**
     * At most one of these options may be given. Options supplied on argv
     * also silence the environment fallback of the others in the set, so an
     * exported variable never makes an explicit command line invalid.
     *
     * Named without `with` to read as the rule it adds; it is still
     * immutable and returns a new instance.
     *
     * @throws \InvalidArgumentException when fewer than two distinct names are given
     */
    public function conflicts(string ...$options): self
    {
        $options = array_values(array_unique($options));
        if (count($options) < 2) {
            throw new \InvalidArgumentException('A conflict set needs at least two distinct options.');
        }
        return $this->mutate(['conflictSets' => [...$this->conflictSets, $options]]);
    }

    /**
     * When `$option` is given (argv or env), each of `$required` must be too.
     * Repeated calls for one option add to its list.
     *
     * @throws \InvalidArgumentException when `$required` is empty
     */
    public function requires(string $option, string ...$required): self
    {
        if ($required === []) {
            throw new \InvalidArgumentException(sprintf('requires("%s") needs at least one required option.', $option));
        }
        $requirements = $this->requirements;
        $requirements[$option] = array_values(array_unique([...($requirements[$option] ?? []), ...$required]));
        return $this->mutate(['requirements' => $requirements]);
    }

    /**
     * Fewest positional arguments accepted.
     *
     * @throws \InvalidArgumentException when negative
     */
    public function withMinArguments(int $min): self
    {
        if ($min < 0) {
            throw new \InvalidArgumentException(sprintf('Minimum arguments must be >= 0, got %d.', $min));
        }
        return $this->mutate(['minArguments' => $min]);
    }

    /**
     * Most positional arguments accepted; null is unbounded. When never
     * called, a command with subcommands accepts none, so a mistyped
     * subcommand is reported as one instead of being taken as an argument;
     * an explicit null keeps such a command unbounded.
     *
     * @throws \InvalidArgumentException when negative
     */
    public function withMaxArguments(?int $max): self
    {
        if ($max !== null && $max < 0) {
            throw new \InvalidArgumentException(sprintf('Maximum arguments must be >= 0, got %d.', $max));
        }
        return $this->mutate(['maxArguments' => $max, 'maxArgumentsSet' => true]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * Declared options, keyed by long name, in declaration order. PHP turns
     * an all-digit name such as `5` into an int key; read the name from
     * {@see Option::name()} when a string is needed.
     *
     * @return array<array-key, Option>
     */
    public function options(): array
    {
        return $this->options;
    }

    /** The option with this long name, or null. */
    public function option(string $name): ?Option
    {
        return $this->options[$name] ?? null;
    }

    /**
     * Declared subcommands, keyed by name, in declaration order. An
     * all-digit name comes back as an int key; read the name from
     * {@see CommandSpec::name()} when a string is needed.
     *
     * @return array<array-key, CommandSpec>
     */
    public function subcommands(): array
    {
        return $this->subcommands;
    }

    /** The subcommand with this name, or null. */
    public function subcommand(string $name): ?CommandSpec
    {
        return $this->subcommands[$name] ?? null;
    }

    /**
     * Options grouped for help output: group name => options, groups in
     * order of first appearance; the ungrouped options sit under `''`. PHP
     * turns an all-digit group name such as `2024` into an int key; cast the
     * key with `(string)` or read {@see Option::group()} when a string is
     * needed.
     *
     * @return array<array-key, list<Option>>
     */
    public function groups(): array
    {
        $groups = [];
        foreach ($this->options as $option) {
            $groups[$option->group()][] = $option;
        }
        return $groups;
    }

    /** @return list<list<string>> */
    public function conflictSets(): array
    {
        return $this->conflictSets;
    }

    /** @return array<array-key, list<string>> option => options it requires (an all-digit name is an int key) */
    public function requirements(): array
    {
        return $this->requirements;
    }

    public function minArguments(): int
    {
        return $this->minArguments;
    }

    /** The configured maximum, or null when unbounded or never set (see {@see effectiveMaxArguments()}). */
    public function maxArguments(): ?int
    {
        return $this->maxArguments;
    }

    /** The maximum parse() enforces: the configured one, else 0 with subcommands, else unbounded (null). */
    public function effectiveMaxArguments(): ?int
    {
        if ($this->maxArgumentsSet) {
            return $this->maxArguments;
        }
        return $this->subcommands === [] ? null : 0;
    }

    /**
     * Parse an argument list against this spec.
     *
     * `$argv` excludes the program name: pass `array_slice($argv, 1)`.
     * `$env` defaults to the process environment; pass an array to make a
     * parse independent of it. The first broken rule is returned, checked in
     * this order: argv tokens left to right, environment values, conflicts,
     * required options, requirements, argument counts.
     *
     * @param list<string> $argv
     * @param array<string, string>|null $env
     * @throws \LogicException when the spec itself is inconsistent (a rule
     *         names an undeclared option, an option name repeats along the
     *         command path, or the minimum exceeds the effective maximum,
     *         including the implicit 0 of a command with subcommands)
     */
    public function parse(array $argv, ?array $env = null): ParsedArgs|UsageError
    {
        return SpecParser::parse($this, array_values($argv), $env ?? getenv());
    }
}
