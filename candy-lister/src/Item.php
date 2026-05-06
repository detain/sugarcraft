<?php

declare(strict_types=1);

namespace CandyCore\Lister;

/**
 * Internal item wrapper — pairs a Stringable value with a unique integer ID.
 *
 * The ID allows stable identity comparison even when two items have the same
 * string representation.
 */
final class Item
{
    public readonly \Stringable $value;
    public readonly int $id;

    public function __construct(\Stringable $value, int $id)
    {
        $this->value = $value;
        $this->id    = $id;
    }

    public function string(): string
    {
        return (string) $this->value;
    }
}
