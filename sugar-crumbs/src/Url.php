<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * URL/path derivation utilities for navigation stacks.
 */
final class Url
{
    /**
     * Derive a URL path string from the navigation stack.
     * e.g. NavStack with items ["home", "settings", "display"] → "/home/settings/display"
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
     */
    public static function parse(string $path): NavStack
    {
        $segments = \array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $s): bool => $s !== ''
        );
        $stack = new NavStack();
        foreach ($segments as $segment) {
            $stack->push(rawurldecode($segment));
        }
        return $stack;
    }
}
