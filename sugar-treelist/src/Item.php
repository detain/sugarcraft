<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

/**
 * Internal item wrapper — pairs a Stringable value with a unique integer ID
 * per constructing Model.
 *
 * NOT public API (sugar-treelist-026): the constructor is private and the
 * only door is the @internal Item::new() mint, which Model's item intake
 * calls exclusively. Hand-built items could forge or duplicate ids, and
 * cursor relocation + filter write-through key on id — a forged id teleports
 * or strands the cursor. Values reach a list through
 * Model::withItem()/withItems()/withItemsFromArray(), which mint ids from
 * the model's own counter.
 *
 * The ID allows stable identity comparison even when two items have the same
 * string representation. IDs are issued from the model's own counter, so they
 * are unique within one Model's item list — sibling forks of a model can
 * re-mint the same IDs (see Model's "Per-instance state").
 *
 * @internal
 */
final class Item
{
    public readonly \Stringable $value;
    public readonly int $id;

    private function __construct(\Stringable $value, int $id)
    {
        $this->value = $value;
        $this->id    = $id;
    }

    /**
     * @internal Mint the wrapper — reserved for Model's item doors, which
     *             issue ids from the receiving model's own counter. A
     *             hand-minted duplicate id teleports or strands the cursor
     *             (identity-keyed relocation); do not construct outside Model.
     */
    public static function new(\Stringable $value, int $id): self
    {
        return new self($value, $id);
    }

    public function string(): string
    {
        return (string) $this->value;
    }
}
