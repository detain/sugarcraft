<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * A numbered item for use in QuickPick filter lists.
 *
 * Stores a 1-based ordinal number alongside the display text,
 * enabling numbered lists with custom filter functions.
 */
final readonly class FilteredItem implements Item
{
    public function __construct(
        private int $number,
        private string $value,
    ) {
    }

    /**
     * House factory (`::new()`), mirroring {@see QuickPick::new()}: same
     * signature as the constructor, which stays public (the docs promise a
     * bare construction site).
     */
    public static function new(int $number, string $value): self
    {
        return new self($number, $value);
    }

    public function number(): int
    {
        return $this->number;
    }

    public function value(): string
    {
        return $this->value;
    }
}
