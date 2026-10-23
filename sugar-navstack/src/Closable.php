<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

/**
 * Interface for navigation items that respond to enter/leave lifecycle events.
 *
 * Implement this to run side-effects when a navigation item becomes active
 * (user navigates TO it) or inactive (user navigates AWAY from it).
 *
 * DISPATCH CONTRACT: sugar-navstack never calls these hooks itself — the
 * stack and renderers are data-only. The consuming component (the app that
 * owns the navigation flow) decides when a level is entered or left and
 * invokes the hooks on whatever items it manages. Treat this as a
 * lifecycle vocabulary the library standardizes on, not an event bus.
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
