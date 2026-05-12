<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Per-item placement options within a StackedGrid.
 */
final readonly class ItemOptions
{
    public function __construct(
        /**
         * Zero-based column index. Items in the same column are stacked
         * vertically; items in different columns are placed side by side.
         */
        public int $column = 0,

        /**
         * When true, the item expands to fill any remaining vertical space
         * in its column after non-expanding items have taken their natural
         * height. Useful for making a panel fill the full height of a row.
         */
        public bool $expandVertical = false,
    ) {}
}
