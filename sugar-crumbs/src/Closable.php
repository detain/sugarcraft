<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Interface for navigation items that respond to enter/leave lifecycle events.
 *
 * Implement this to run side-effects when a navigation item becomes active
 * (user navigates TO it) or inactive (user navigates AWAY from it).
 */
interface Closable
{
    /** Called when this item becomes the current/active item. */
    public function onEnter(): void;

    /** Called when this item is no longer the current item. */
    public function onLeave(): void;

    /** Returns the item's title. */
    public function title(): string;
}
