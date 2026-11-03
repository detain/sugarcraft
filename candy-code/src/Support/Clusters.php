<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * Grapheme-cluster boundary walks over UTF-8 strings, codepoint-indexed.
 *
 * Three call sites walked their own copy of the same loop — the renderer's
 * caret snap, the palette's backspace cut, and the palette's forward motion
 * — each re-deriving with `grapheme_extract` what the others had already
 * settled. A drifted copy is a caret that splits an emoji, which is the bug
 * class every copy here was written against once. One walker now owns the
 * step; the callers keep only the boundary-selection rule they each need.
 *
 * Offsets are CODEPOINTS (mb_strlen units), matching every caller's existing
 * cursor arithmetic; bytes are the walk's internal currency.
 */
final class Clusters
{
    private function __construct()
    {
    }

    /**
     * The last cluster boundary at or before `$offset` — `$offset` itself
     * when it names a boundary, the cluster start when it sits inside one.
     * Out-of-range offsets clamp to the string's ends.
     */
    public static function boundaryAtOrBefore(string $text, int $offset): int
    {
        [$atOrBefore, ] = self::boundaries($text, $offset);

        return $atOrBefore;
    }

    /**
     * The last cluster boundary strictly before `$offset` — the start of the
     * cluster a caret would erase with Backspace. At or past the end of the
     * string this is the start of the final cluster; at 0 it is 0.
     */
    public static function boundaryBefore(string $text, int $offset): int
    {
        [, $before] = self::boundaries($text, $offset);

        return $before;
    }

    /**
     * The first cluster boundary strictly after `$offset`, or the string's
     * length when nothing follows — the cell a caret lands on with Right
     * even when it starts on a boundary.
     */
    public static function boundaryAfter(string $text, int $offset): int
    {
        $total = \mb_strlen($text, 'UTF-8');
        if ($offset >= $total) {
            return $total;
        }

        $cp = 0;
        $byte = 0;
        while ($cp < $total) {
            $cluster = \grapheme_extract($text, 1, 0, $byte, $next);
            if ($cluster === false || $cluster === '') {
                break;
            }
            $cp += \mb_strlen($cluster, 'UTF-8');
            $byte = $next;
            if ($cp > $offset) {
                return $cp;
            }
        }

        return $total;
    }

    /**
     * One walk, both backward-facing answers: [at-or-before, strictly-before]
     * for `$offset` clamped into `0..length`.
     *
     * @return array{0: int, 1: int}
     */
    private static function boundaries(string $text, int $offset): array
    {
        $total = \mb_strlen($text, 'UTF-8');
        if ($offset <= 0) {
            return [0, 0];
        }
        if ($offset >= $total) {
            // Past the end, the at-or-before boundary is the string's end;
            // the start of the final cluster is found by the same walk.
            $offset = $total;
        }

        $prev = 0;
        $cp = 0;
        $byte = 0;
        while ($cp < $offset) {
            $cluster = \grapheme_extract($text, 1, 0, $byte, $next);
            if ($cluster === false || $cluster === '') {
                break;
            }
            $prev = $cp;
            $cp += \mb_strlen($cluster, 'UTF-8');
            $byte = $next;
        }

        return [$cp === $offset ? $offset : $prev, $prev];
    }
}
