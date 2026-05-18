<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Grid;

/**
 * Internal pairing of an item with its placement options.
 *
 * @internal
 */
final readonly class ItemWithOptions
{
    public function __construct(
        public \SugarCraft\Dash\Foundation\Item $item,
        public ItemOptions $options,
    ) {}
}
