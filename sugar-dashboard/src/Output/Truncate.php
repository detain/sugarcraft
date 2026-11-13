<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Output;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Truncation helper with ANSI awareness — the canonical cut for this
 * library (sugar-dashboard-018's consolidation seat).
 *
 * Every renderer that must fit a string into a cell budget routes here;
 * the hand-rolled binary-search copies this replaced could cut
 * mid-escape-sequence (dangling SGR bytes) and each carried its own
 * ellipsis behavior, which is how double-ellipsis titles were born.
 *
 * ANSI contract: a string that FITS the budget comes back byte-identical
 * (styles are never destroyed by a no-op truncation); a string that must be
 * cut keeps its escape sequences — the cut is the style-preserving
 * `Core\Util\Width::truncateAnsi` walk, which harvests trailing resets even
 * past the budget. When an ellipsis is requested it rides AFTER the
 * harvested reset, so the cut marker is always unstyled, and only this
 * canonical ever appends it.
 */
final class Truncate
{
    /**
     * Truncate a string to a maximum width.
     *
     * @param string $text The text to truncate
     * @param int $width Maximum width in cells
     * @param string $ellipsis Marker appended when a cut happened ('' for a plain cut)
     * @return string Truncated string
     */
    public static function truncate(string $text, int $width, string $ellipsis = '…'): string
    {
        if ($width <= 0) {
            return '';
        }

        $textWidth = Width::string($text);
        if ($textWidth <= $width) {
            // Fits: return the input byte-identical. Stripping here (the
            // old behavior) silently destroyed styles through the
            // canonical helper even though nothing was truncated.
            return $text;
        }

        // Account for ellipsis width
        $ellipsisWidth = Width::string($ellipsis);
        $availableWidth = $width - $ellipsisWidth;

        if ($availableWidth <= 0) {
            // No room for content AND marker: the marker alone owns the
            // budget when it fits, otherwise the honest answer is ''.
            return $ellipsisWidth <= $width ? $ellipsis : '';
        }

        // Style-preserving walk: the kept prefix retains its SGR runs and
        // the trailing-reset harvest (even from past the cut point) leaves
        // the sequence state closed, so the ellipsis lands unstyled.
        return Width::truncateAnsi($text, $availableWidth) . $ellipsis;
    }

    /**
     * Truncate with left alignment (pad right).
     *
     * @param string $text The text to truncate
     * @param int $width Maximum width
     * @param string $fill Character to fill remaining space
     * @return string Truncated and padded string
     */
    public static function truncateLeft(string $text, int $width, string $fill = ' '): string
    {
        $truncated = self::truncate($text, $width);
        $truncatedWidth = Width::string($truncated);
        $padding = max(0, $width - $truncatedWidth);

        return $truncated . str_repeat($fill, $padding);
    }

    /**
     * Truncate with right alignment (pad left).
     *
     * Truncates from the LEFT side (keeps rightmost characters, ellipsis on left),
     * then pads on the left.
     *
     * @param string $text The text to truncate
     * @param int $width Maximum width
     * @param string $fill Character to fill remaining space
     * @return string Truncated and padded string
     */
    public static function truncateRight(string $text, int $width, string $fill = ' '): string
    {
        $textWidth = Width::string($text);
        if ($textWidth <= $width) {
            $padding = max(0, $width - $textWidth);

            // Fits: pad the ORIGINAL bytes (139's strip-on-fits destroyed
            // styles even when nothing was cut).
            return str_repeat($fill, $padding) . $text;
        }

        // Truncate from the left: keep rightmost chars that fit, prepend ellipsis.
        $ellipsis = '…';
        $ellipsisWidth = Width::string($ellipsis);
        $availableWidth = $width - $ellipsisWidth;

        if ($availableWidth <= 0) {
            return mb_substr($ellipsis, 0, Width::string($ellipsis) <= $width ? 1 : 0);
        }

        // Walk backwards from end of string to find how many chars fit.
        $len = mb_strlen($text, 'UTF-8');
        $lo = 0;
        $hi = $len;

        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($text, -$mid, $mid, 'UTF-8');

            if (Width::string($candidate) <= $availableWidth) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($lo === 0) {
            return Ansi::strip($ellipsis);
        }

        $kept = mb_substr($text, -$lo, $lo, 'UTF-8');

        return $ellipsis . Ansi::strip($kept);
    }

    /**
     * Truncate with center alignment.
     *
     * @param string $text The text to truncate
     * @param int $width Maximum width
     * @param string $fill Character to fill remaining space
     * @return string Truncated and padded string
     */
    public static function truncateCenter(string $text, int $width, string $fill = ' '): string
    {
        $truncated = self::truncate($text, $width);
        $truncatedWidth = Width::string($truncated);
        $padding = max(0, $width - $truncatedWidth);
        $leftPad = (int) floor($padding / 2);
        $rightPad = $padding - $leftPad;

        return str_repeat($fill, $leftPad) . $truncated . str_repeat($fill, $rightPad);
    }
}
