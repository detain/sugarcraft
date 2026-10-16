<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * Contract for items displayed in the QuickPick filter list.
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
