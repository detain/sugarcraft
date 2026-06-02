<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

/**
 * Immutable wrapper for a raw status variable value.
 *
 * Provides type-safe access to string values from SHOW GLOBAL STATUS.
 */
final readonly class RawValue
{
    public function __construct(
        public string $value,
    ) {}

    public function asString(): string
    {
        return $this->value;
    }

    public function asInt(): int
    {
        return (int) $this->value;
    }

    public function asFloat(): float
    {
        return (float) $this->value;
    }

    public function asBool(): bool
    {
        return $this->value === '1' || strtolower($this->value) === 'true' || $this->value === 'ON';
    }
}
