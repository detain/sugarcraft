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
     * Titles are sanitized BEFORE encoding (NS-09's sibling, NS-22): each
     * one runs through the same {@see TitleSanitizer} sweep the display
     * surfaces apply, then through rawurlencode. The URL therefore carries
     * what the user SEES — never percent-reanimated terminal junk (%1B[31m
     * style debris that survives a naive raw-title encode and re-enters the
     * stack as hostile bytes on decode). rawurlencode is RFC 3986 §2
     * percent-encoding over UTF-8 bytes, so multibyte titles travel as
     * percent-exile ("Café" → "Caf%C3%A9") and restore byte-exactly.
     *
     * An EMPTY stack derives to `/` — the base case of the join, and the
     * inverse shape {@see parse()} reads back as an empty stack (a depth-0
     * trail is representable even though a depth-1 root is the normal floor).
     */
    public static function derive(NavStack $stack): string
    {
        $segments = \array_map(self::segment(...), $stack->items());
        if ($segments === []) {
            return '/';
        }
        return '/' . \implode('/', $segments);
    }

    /**
     * Derive the ancestor-prefix path up to AND INCLUDING stack index
     * $index: the first $index+1 crumbs of {@see derive()}'s output.
     *
     * This is the `$href` pairing for {@see NavStack::viewHtml()} — the
     * callback receives (item, index) and the index selects how much of
     * the trail the link carries. Encoding is the SAME single source as
     * {@see derive()}: the shared segment() sweep (sanitize-then-
     * rawurlencode, NS-22), so every prefix is byte-for-byte a segment
     * slice of the full path and the two can never drift. A duplicate
     * title at two depths still yields two DIFFERENT hrefs — the index,
     * not the title, bounds the prefix.
     *
     * @throws \InvalidArgumentException When $index is outside the stack.
     *                                   A prefix of a trail that does not
     *                                   exist is a caller bug, not a clamp
     *                                   case: clamping would mint a link
     *                                   to the wrong depth.
     */
    public static function deriveTo(NavStack $stack, int $index): string
    {
        $items = $stack->items();
        if ($index < 0 || $index >= \count($items)) {
            throw new \InvalidArgumentException(
                'deriveTo() index ' . $index . ' is outside the stack of ' . \count($items) . ' items'
            );
        }
        $segments = \array_map(self::segment(...), \array_slice($items, 0, $index + 1));
        return '/' . \implode('/', $segments);
    }

    /**
     * The ONE segment encoder — sanitize for display, then RFC 3986 §2
     * percent-encode. Shared by derive()/deriveTo() so the full path and
     * every prefix travel under identical bytes (NS-07).
     */
    private static function segment(NavigationItem $item): string
    {
        return \rawurlencode(TitleSanitizer::sanitize($item->title));
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
