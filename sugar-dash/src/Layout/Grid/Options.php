<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Grid;

/**
 * Grid-level configuration options.
 */
readonly class Options
{
    public function __construct(
        /**
         * When true (default), the grid automatically fits items to the
         * available terminal space. When false, items get their natural
         * rendered size and the grid does not attempt to fill the width.
         */
        public bool $fitScreen = true,
    ) {}
}
