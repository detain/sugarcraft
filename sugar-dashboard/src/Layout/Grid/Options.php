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
         * When true (default), the grid automatically fits items to the
         * available terminal space. When false, short items keep their
         * natural rendered size and the grid does not attempt to fill the
         * width — every column is still clamped to its even share, so the
         * joined frame never exceeds the grid's own width.
         */
        public bool $fitScreen = true,
    ) {}
}
