<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

/**
 * Separator escaping for navigation item titles.
 *
 * Escaping is OPT-IN: {@see Breadcrumb::render()} does NOT auto-apply it.
 * A caller that wants a title containing the separator to render as a single
 * crumb escapes it here before pushing, then {@see unescape()}s on the way
 * back out. Because the separator is configurable
 * ({@see Breadcrumb::setSeparator()}), both methods take a $separator so the
 * caller can pass the SAME one they gave the breadcrumb; the default matches
 * {@see Breadcrumb}'s (and {@see NavStack::view()}'s) default separator (' › ').
 *
 * Limits (this is a display-splitting aid, not a general quoting system):
 * both operations are purely mechanical left-to-right replacements of whole
 * non-overlapping occurrences — {@see title()} prefixes each separator with
 * a backslash, {@see unescape()} removes a backslash before each separator.
 * unescape() is only an inverse for strings title() produced: applied to a
 * never-escaped title it rewrites any backslash-plus-separator pair the
 * title happened to contain, and a title that ALREADY had a backslash glued
 * to a separator occurrence does not survive the title()→unescape()
 * round-trip either (the leftmost pair is ambiguous about which backslash
 * was inserted). There is no double-escape marker. If verbatim round-trip
 * fidelity of raw-backslash titles matters, carry the exact title in the
 * item's data payload and keep the display label escape-safe.
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
