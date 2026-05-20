<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

/**
 * A numbered item for use in Hermit filter lists.
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

    public function number(): int
    {
        return $this->number;
    }

    public function value(): string
    {
        return $this->value;
    }
}
