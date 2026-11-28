<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Core\Concerns\Mutable;

/**
 * One declared command-line option of a {@see CommandSpec}: a flag
 * (`--verbose`) or a value option (`--model gpt`), with its short alias,
 * environment fallback, value type, allowed values, default and help group.
 *
 * Immutable: every `with*()` returns a new instance. Names are validated at
 * build time and a bad one throws, because a malformed spec is a programmer
 * error, not a usage error.
 *
 * Original work: no charmbracelet upstream parses argv (fang hands its
 * command tree to spf13/cobra), so this is SugarCraft's own spec, shaped so
 * help, completions and man pages can be generated from the same object.
 */
final class Option
{
    use Mutable;

    /**
     * @param list<string> $choices
     * @param class-string<\BackedEnum>|null $enum
     */
    private function __construct(
        private readonly string $name,
        private readonly OptionKind $kind,
        private readonly ?string $short,
        private readonly ?string $env,
        private readonly string $description,
        private readonly ValueType $type,
        private readonly array $choices,
        private readonly ?string $enum,
        private readonly bool $repeatable,
        private readonly bool $required,
        private readonly mixed $default,
        private readonly string $group,
        private readonly string $valueName,
        private readonly bool $persistent,
    ) {}

    /**
     * A switch with no value: `--name` (or `-x` once {@see withShort()} is set).
     *
     * @throws \InvalidArgumentException when `$name` is not a valid long name
     */
    public static function flag(string $name): self
    {
        return self::build($name, OptionKind::Flag);
    }

    /**
     * An option that takes one value: `--name value` or `--name=value`.
     *
     * @throws \InvalidArgumentException when `$name` is not a valid long name
     */
    public static function value(string $name): self
    {
        return self::build($name, OptionKind::Value);
    }

    /**
     * Single-character alias, used as `-x`. Letters and digits only.
     *
     * @throws \InvalidArgumentException when `$short` is not one ASCII letter or digit
     */
    public function withShort(string $short): self
    {
        if (preg_match('/^[A-Za-z0-9]$/', $short) !== 1) {
            throw new \InvalidArgumentException(sprintf('Short option must be one letter or digit, got "%s".', $short));
        }
        return $this->mutate(['short' => $short]);
    }

    /**
     * Environment variable read when the option is absent from argv. An empty
     * variable counts as unset.
     *
     * @throws \InvalidArgumentException when `$env` is not a valid variable name
     */
    public function withEnv(string $env): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $env) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid environment variable name "%s".', $env));
        }
        return $this->mutate(['env' => $env]);
    }

    /** One-line help text. */
    public function withDescription(string $description): self
    {
        return $this->mutate(['description' => $description]);
    }

    /**
     * Convert the raw value into an int or float; a value that does not
     * convert is a usage error. Only meaningful on a value option.
     *
     * @throws \LogicException on a flag
     */
    public function withType(ValueType $type): self
    {
        $this->assertTakesValue('withType');
        return $this->mutate(['type' => $type]);
    }

    /**
     * Restrict the value to a fixed set of strings (compared exactly).
     * Replaces any {@see withEnum()} binding.
     *
     * @param list<string> $choices
     * @throws \LogicException on a flag
     * @throws \InvalidArgumentException when `$choices` is empty
     */
    public function withChoices(array $choices): self
    {
        $this->assertTakesValue('withChoices');
        if ($choices === []) {
            throw new \InvalidArgumentException('Choices must not be empty.');
        }
        return $this->mutate(['choices' => array_values(array_map('strval', $choices)), 'enum' => null]);
    }

    /**
     * Restrict the value to the backing values of a backed enum; the parsed
     * value is the enum case, not the string.
     *
     * @param class-string<\BackedEnum> $enum
     * @throws \LogicException on a flag
     * @throws \InvalidArgumentException when `$enum` is not a backed enum
     */
    public function withEnum(string $enum): self
    {
        $this->assertTakesValue('withEnum');
        if (!enum_exists($enum) || !is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a backed enum.', $enum));
        }
        $choices = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());
        return $this->mutate(['choices' => $choices, 'enum' => $enum]);
    }

    /**
     * Accept the option more than once. A repeatable value option collects a
     * list; a repeatable flag counts its occurrences (`-vvv` is 3). A
     * non-repeatable option given twice keeps the last value.
     */
    public function withRepeatable(bool $repeatable = true): self
    {
        return $this->mutate(['repeatable' => $repeatable]);
    }

    /** Fail with a usage error when neither argv nor the env variable supplies the option. */
    public function withRequired(bool $required = true): self
    {
        return $this->mutate(['required' => $required]);
    }

    /**
     * Value reported when neither argv nor env supplies the option. Taken
     * as given: it is not converted or checked against the choices.
     */
    public function withDefault(mixed $default): self
    {
        return $this->mutate(['default' => $default]);
    }

    /** Help-page section this option is listed under; `''` is the ungrouped section. */
    public function withGroup(string $group): self
    {
        return $this->mutate(['group' => $group]);
    }

    /** Placeholder for the value in help output, e.g. `--model <MODEL>`. */
    public function withValueName(string $valueName): self
    {
        return $this->mutate(['valueName' => $valueName]);
    }

    /** Also accept this option after any subcommand of the command that declares it. */
    public function withPersistent(bool $persistent = true): self
    {
        return $this->mutate(['persistent' => $persistent]);
    }

    /** Long name, without the leading `--`. */
    public function name(): string
    {
        return $this->name;
    }

    public function kind(): OptionKind
    {
        return $this->kind;
    }

    /** True for a value option, false for a flag. */
    public function takesValue(): bool
    {
        return $this->kind === OptionKind::Value;
    }

    /** Short alias without the `-`, or null. */
    public function short(): ?string
    {
        return $this->short;
    }

    /** Fallback environment variable, or null. */
    public function env(): ?string
    {
        return $this->env;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function type(): ValueType
    {
        return $this->type;
    }

    /**
     * Allowed raw values; empty means any value.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        return $this->choices;
    }

    /**
     * Backed enum the value maps to, or null.
     *
     * @return class-string<\BackedEnum>|null
     */
    public function enum(): ?string
    {
        return $this->enum;
    }

    public function repeatable(): bool
    {
        return $this->repeatable;
    }

    public function required(): bool
    {
        return $this->required;
    }

    /** The configured default, or null when none was set. */
    public function default(): mixed
    {
        return $this->default;
    }

    public function group(): string
    {
        return $this->group;
    }

    /** Value placeholder for help output; defaults to the upper-cased name. */
    public function valueName(): string
    {
        return $this->valueName;
    }

    public function persistent(): bool
    {
        return $this->persistent;
    }

    /** `--name`, the spelling used in usage errors and help. */
    public function display(): string
    {
        return '--' . $this->name;
    }

    private static function build(string $name, OptionKind $kind): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Option name must start with a letter or digit and contain only letters, digits, "-" and "_", got "%s".',
                $name,
            ));
        }
        return new self(
            name: $name,
            kind: $kind,
            short: null,
            env: null,
            description: '',
            type: ValueType::String,
            choices: [],
            enum: null,
            repeatable: false,
            required: false,
            default: null,
            group: '',
            valueName: strtoupper(str_replace('-', '_', $name)),
            persistent: false,
        );
    }

    private function assertTakesValue(string $method): void
    {
        if ($this->kind !== OptionKind::Value) {
            throw new \LogicException(sprintf('%s() applies to value options; "--%s" is a flag.', $method, $this->name));
        }
    }
}
