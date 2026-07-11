<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs;

/**
 * Separator escaping for navigation item titles.
 *
 * Escaping is OPT-IN: {@see Breadcrumb::render()} does NOT auto-apply it.
 * A caller that wants a title containing the separator to render as a single
 * crumb escapes it here before pushing, then {@see unescape()}s on the way
 * back out. Because the separator is configurable
 * ({@see Breadcrumb::setSeparator()}), both methods take a $separator so the
 * caller can pass the SAME one they gave the breadcrumb; the default matches
 * {@see Breadcrumb}'s own default (' › '), not the ASCII ' > ' NavStack uses.
 */
final class Escape
{
    /** Matches {@see Breadcrumb}'s default separator. */
    private const DEFAULT_SEPARATOR = ' › ';

    /**
     * Escape a title so it can safely appear in a breadcrumb render.
     * If the title contains $separator, prefix each occurrence with a backslash.
     */
    public static function title(string $title, string $separator = self::DEFAULT_SEPARATOR): string
    {
        return \str_replace($separator, '\\' . $separator, $title);
    }

    /**
     * Reverse {@see title()}. Pass the SAME $separator that was used to escape.
     */
    public static function unescape(string $title, string $separator = self::DEFAULT_SEPARATOR): string
    {
        return \str_replace('\\' . $separator, $separator, $title);
    }
}
