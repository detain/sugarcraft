<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * Anything that can be placed in a StackedGrid and rendered as a string.
 */
interface Item
{
    /**
     * Render the item to a string.
     */
    public function render(): string;
}
