<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * A single item on the navigation stack.
 *
 * Holds the title (used in breadcrumbs) and optional arbitrary data
 * that each navigation level wants to carry (e.g. filter state, selected ID, etc.)
 *
 * Implements {@see Closable} so subclasses or decorated items can respond
 * to enter/leave lifecycle events.
 */
final class NavigationItem implements Closable
{
    public function __construct(
        public readonly string $title,
        public readonly mixed $data = null,
    ) {}

    /**
     * Called when this item becomes the current/active item.
     * No-op by default; override in a subclass or decorator to run side-effects.
     */
    public function onEnter(): void
    {
        // no-op by default
    }

    /**
     * Called when this item is no longer the current item.
     * No-op by default; override in a subclass or decorator to run side-effects.
     */
    public function onLeave(): void
    {
        // no-op by default
    }

    /**
     * Returns the item's title.
     */
    public function title(): string
    {
        return $this->title;
    }
}
