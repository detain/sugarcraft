<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

/**
 * Contract for items displayed in the Hermit filter list.
 *
 * Allows the Hermit to work with structured items rather than raw strings,
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
