<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

/**
 * URL/path derivation utilities for navigation stacks.
 */
final class Url
{
    /**
     * Derive a URL path string from the navigation stack.
     * e.g. NavStack with items ["home", "settings", "display"] → "/home/settings/display"
     *
     * An EMPTY stack derives to `/` — the base case of the join, and the
     * inverse shape {@see parse()} reads back as an empty stack (a depth-0
     * trail is representable even though a depth-1 root is the normal floor).
     */
    public static function derive(NavStack $stack): string
    {
        $segments = \array_map(
            static fn(NavigationItem $item): string => $item->title,
            $stack->items()
        );
        if ($segments === []) {
            return '/';
        }
        return '/' . \implode('/', \array_map('rawurlencode', $segments));
    }

    /**
     * Parse a URL path back into a NavStack.
     *
     * Empty AND whitespace-only segments (including percent-encoded ones
     * like `/%20/`) are dropped — the counterpart to
     * {@see NavigationItem}'s refusal of blank titles, which keeps the
     * TITLE leg of a `parse(derive($stack))` round trip lossless for valid
     * stacks. The round trip is title-level ONLY: {@see derive()} carries
     * titles and nothing else, so item payloads (data) do not travel and
     * every restored item comes back with `data === null`.
     *
     * @throws \InvalidArgumentException When a segment decodes non-blank
     *                                   but sanitizes to empty (refused by
     *                                   {@see NavigationItem}'s guards)
     */
    public static function parse(string $path): NavStack
    {
        $segments = \array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $s): bool => \trim(rawurldecode($s)) !== ''
        );
        $stack = new NavStack();
        foreach ($segments as $segment) {
            $stack->push(rawurldecode($segment));
        }
        return $stack;
    }
}
