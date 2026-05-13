<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Represents an item positioned in a GridLayout.
 */
final class GridItem
{
    public function __construct(
        public readonly Item $content,
        public readonly int $column = 0,
        public readonly int $row = 0,
        public readonly int $columnSpan = 1,
        public readonly int $rowSpan = 1,
    ) {}
}
