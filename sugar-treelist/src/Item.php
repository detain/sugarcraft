<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

/**
 * Internal item wrapper — pairs a Stringable value with a unique integer ID
 * per constructing Model.
 *
 * The ID allows stable identity comparison even when two items have the same
 * string representation. IDs are issued from the model's own counter, so they
 * are unique within one Model's item list — sibling forks of a model can
 * re-mint the same IDs (see Model's "Per-instance state").
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
