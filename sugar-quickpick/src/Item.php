<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * Contract for items displayed in the QuickPick filter list.
 *
 * Mirrors Genekkion/theHermit `list.Item` (item.go) — whose single `Title()`
 * member maps to `value()` here; `number()` is package-local, added because
 * the ordinals are part of this lib's display contract (and replayed through
 * FileHistory).
 *
 * Allows the QuickPick to work with structured items rather than raw strings,
 * enabling numbered items, rich item types, and persistent history.
 */
interface Item
{
    /**
     * Returns the ordinal number of this item (1-based for display).
     */
    public function number(): int;

    /**
     * Returns the display text of this item.
     */
    public function value(): string;
}
