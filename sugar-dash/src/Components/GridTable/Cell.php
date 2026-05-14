<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\GridTable;

/**
 * Cell value object for data grid tables.
 *
 * Represents a single cell's value with optional formatting and styling.
 */
final class Cell
{
    public function __construct(
        private readonly mixed $value = null,
        private readonly ?string $format = null,
    ) {}

    public static function new(mixed $value = null, ?string $format = null): self
    {
        return new self(value: $value, format: $format);
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function toString(): string
    {
        if ($this->value === null) {
            return '';
        }

        return match ($this->format) {
            'currency' => number_format((float) $this->value, 2),
            'integer' => number_format((int) $this->value),
            'percent' => number_format((float) $this->value * 100, 1) . '%',
            default => (string) $this->value,
        };
    }
}
