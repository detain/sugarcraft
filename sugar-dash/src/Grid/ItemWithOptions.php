<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Internal pairing of an item with its placement options.
 *
 * @internal
 */
final readonly class ItemWithOptions
{
    public function __construct(
        public Item $item,
        public ItemOptions $options,
    ) {}
}
