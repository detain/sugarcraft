<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Grid;

/**
 * Grid-level configuration options.
 */
final readonly class Options
{
    public function __construct(
        /**
         * When true (default), the grid divides its frame evenly: every
         * column is padded to the same share of the terminal width.
         *
         * When false, short columns keep their natural rendered width and
         * the grid does not attempt to fill the width — but every column is
         * still clamped to its even share, so the joined frame never
         * exceeds the grid's own width. A column containing a nested grid
         * or any Sizer child (which renders to the box it is handed and has
         * no natural width to measure) takes its full share either way.
         * Narrow terminals (< 90 cells) collapse to a single full-width
         * column before this option is consulted at all.
         */
        public bool $fitScreen = true,
    ) {}
}
