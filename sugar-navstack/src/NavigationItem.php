<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

/**
 * A single item on the navigation stack.
 *
 * Holds the title (used in breadcrumbs) and optional arbitrary data
 * that each navigation level wants to carry (e.g. filter state, selected ID, etc.)
 *
 * Implements {@see Closable}; see that interface for who dispatches the
 * lifecycle hooks (the CONSUMING component does — this library never calls
 * onEnter/onLeave on items it renders). This class is `final`, so the
 * extension point is your own Closable implementation, not subclassing.
 */
final class NavigationItem implements Closable
{
    public function __construct(
        public readonly string $title,
        public readonly mixed $data = null,
    ) {}

    /**
     * Contract default from {@see Closable}: a no-op. Nothing in this
     * library dispatches it — a consumer that wires enter/leave events
     * calls it, and subclasses are impossible (final); implement
     * {@see Closable} on your own type instead.
     */
    public function onEnter(): void
    {
        // no-op by default
    }

    /**
     * Contract default from {@see Closable}: a no-op. See
     * {@see onEnter()} for who dispatches.
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
