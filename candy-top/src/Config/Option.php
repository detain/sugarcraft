<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

use SugarCraft\Top\Lang;

/**
 * One config option: its btop key, storage type, default and validation law.
 *
 * Mirrors aristocratos/btop src/btop_config.cpp — the default comes from the
 * `bools`/`ints`/`strings` maps, the file tokenisation from `Config::load`
 * (`isbool`/`isint` gates), and the value law from `intValid`/`stringValid`.
 * Invalid input is never coerced: btop keeps the previous value and reports a
 * warning, so {@see parse()} and {@see check()} throw {@see InvalidOptionValue}
 * and the caller decides whether that is a warning (load) or an error (menu).
 */
final class Option
{
    /**
     * Largest value btop's `stoi()` accepts; anything wider is
     * "Value out of range!" rather than a silently-wrapped number.
     */
    public const INT_MAX = 2147483647;

    /**
     * @param list<string>|null $allowed  Enumerated values (exact, case-sensitive — btop uses v_contains).
     * @param string|null       $enumWarning Lang key used when a value falls outside $allowed.
     * @param (\Closure(string): void)|null $validator Extra string law; throws InvalidOptionValue.
     * @param bool $validateOnLoad False when btop skips the value law while reading the file.
     */
    private function __construct(
        public readonly string $name,
        public readonly OptionType $type,
        public readonly bool|int|string $default,
        public readonly bool $persisted,
        public readonly ?array $allowed,
        public readonly ?string $enumWarning,
        public readonly ?int $min,
        public readonly ?int $max,
        private readonly ?\Closure $validator,
        public readonly bool $validateOnLoad = true,
    ) {
    }

    /** A boolean option (`true`/`false`/`True`/`False` in the file). */
    public static function bool(string $name, bool $default, bool $persisted = true): self
    {
        return new self($name, OptionType::Bool, $default, $persisted, null, null, null, null, null);
    }

    /** A non-negative integer option with an optional inclusive range. */
    public static function int(string $name, int $default, int $min = 0, int $max = self::INT_MAX, bool $persisted = true): self
    {
        return new self($name, OptionType::Int, $default, $persisted, null, null, $min, $max, null);
    }

    /**
     * A string option, optionally restricted to an enumeration and/or a
     * custom validator.
     *
     * @param list<string>|null              $allowed
     * @param (\Closure(string): void)|null  $validator
     * @param bool                           $validateOnLoad False for options btop accepts verbatim from
     *                                                       the file and settles later (shown_boxes).
     */
    public static function string(
        string $name,
        string $default,
        ?array $allowed = null,
        ?string $enumWarning = null,
        ?\Closure $validator = null,
        bool $persisted = true,
        bool $validateOnLoad = true,
    ): self {
        return new self($name, OptionType::String, $default, $persisted, $allowed, $enumWarning, null, null, $validator, $validateOnLoad);
    }

    /**
     * The option's description as written above it in config.conf, in the
     * active locale; empty when btop writes none (net_upload).
     */
    public function description(): string
    {
        $key = 'config.desc.' . $this->name;
        $text = Lang::t($key);

        return $text === 'top.' . $key ? '' : $text;
    }

    /**
     * Convert a raw config-file token to a typed value, validating it.
     *
     * Mirrors btop Config::load: bools accept exactly `true|false|True|False`,
     * ints must be all digits (so a leading `-` or quotes are rejected before
     * the range law runs), strings pass through the string law — except, with
     * $fromFile, for an option btop does not validate during load
     * ({@see $validateOnLoad}).
     *
     * @throws InvalidOptionValue
     */
    public function parse(string $raw, bool $fromFile = false): bool|int|string
    {
        return match ($this->type) {
            OptionType::Bool => match ($raw) {
                'true', 'True' => true,
                'false', 'False' => false,
                default => throw $this->fail('config.warn.invalid_bool'),
            },
            OptionType::Int => $this->parseInt($raw),
            OptionType::String => $this->checkString($raw, $fromFile && !$this->validateOnLoad),
        };
    }

    /**
     * Validate an already-typed value and return it unchanged.
     *
     * @throws InvalidOptionValue On a type mismatch or a value btop rejects.
     */
    public function check(bool|int|string $value): bool|int|string
    {
        $matches = match ($this->type) {
            OptionType::Bool => \is_bool($value),
            OptionType::Int => \is_int($value),
            OptionType::String => \is_string($value),
        };
        if (!$matches) {
            throw $this->fail('config.warn.type_mismatch', ['type' => $this->type->value]);
        }

        if (\is_int($value)) {
            $this->checkRange($value);
        } elseif (\is_string($value)) {
            $this->checkString($value, false);
        }

        return $value;
    }

    private function parseInt(string $raw): int
    {
        // btop isint(): all_of(isdigit) — empty passes it, then stoi throws.
        if ($raw === '' || !ctype_digit($raw)) {
            throw $this->fail($raw === '' ? 'config.warn.int_not_numeric' : 'config.warn.invalid_int');
        }
        $trimmed = ltrim($raw, '0');
        if (\strlen($trimmed) > 10 || ($trimmed !== '' && (int) $trimmed > self::INT_MAX)) {
            throw $this->fail('config.warn.int_out_of_range');
        }

        $value = (int) $raw;
        $this->checkRange($value);

        return $value;
    }

    private function checkRange(int $value): void
    {
        $min = $this->min ?? 0;
        $max = $this->max ?? self::INT_MAX;
        if ($value < $min) {
            throw $min === 0
                ? $this->fail('config.warn.int_negative')
                : $this->fail('config.warn.int_too_low', ['min' => $min]);
        }
        if ($value > $max) {
            throw $max === self::INT_MAX
                ? $this->fail('config.warn.int_out_of_range')
                : $this->fail('config.warn.int_too_high', ['max' => $max]);
        }
    }

    private function checkString(string $value, bool $skipLaw): string
    {
        // The writer emits `key = "value"` and the reader stops at the next
        // quote, so a value holding `"` or a line break can never round-trip.
        if (strpbrk($value, "\"\r\n") !== false) {
            throw $this->fail('config.warn.unquotable');
        }
        if ($skipLaw) {
            return $value;
        }
        if ($this->allowed !== null && !\in_array($value, $this->allowed, true)) {
            throw $this->fail($this->enumWarning ?? 'config.warn.invalid_value', ['value' => $value]);
        }
        if ($this->validator !== null) {
            ($this->validator)($value);
        }

        return $value;
    }

    /** @param array<string, string|int> $params */
    private function fail(string $key, array $params = []): InvalidOptionValue
    {
        return new InvalidOptionValue(Lang::t($key, ['name' => $this->name] + $params), $this->name);
    }
}
