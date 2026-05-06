<?php

declare(strict_types=1);

namespace CandyCore\Crumbs;

/**
 * A single item on the navigation stack.
 *
 * Holds the title (used in breadcrumbs) and optional arbitrary data
 * that each navigation level wants to carry (e.g. filter state, selected ID, etc.)
 */
final class NavigationItem
{
    public function __construct(
        public readonly string $title,
        public readonly mixed $data = null,
    ) {}
}
