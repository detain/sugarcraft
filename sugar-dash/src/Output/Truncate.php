<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Output;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Truncation helper with ANSI awareness.
 *
 * Extracts truncation logic from Layout, StackedGrid, and Boxer
 * to provide a single, consistent truncation function that
 * correctly handles ANSI escape codes.
 */
final class Truncate
{
    /**
     * Truncate a string to a maximum width.
     *
     * Uses binary search to find the longest substring that fits
     * within the given width, accounting for ANSI codes.
     *
     * @param string $text The text to truncate
     * @param int $width Maximum width in cells
     * @param string $ellipsis Ellipsis to append if truncated (default: '…')
     * @return string Truncated string
     */
    public static function truncate(string $text, int $width, string $ellipsis = '…'): string
    {
        if ($width <= 0) {
            return '';
        }

        $textWidth = Width::string($text);
        if ($textWidth <= $width) {
            // Strip ANSI from the output for consistency (width was measured on clean text)
            return Ansi::strip($text);
        }

        // Account for ellipsis width
        $ellipsisWidth = Width::string($ellipsis);
        $availableWidth = $width - $ellipsisWidth;

        if ($availableWidth <= 0) {
            return mb_substr($ellipsis, 0, Width::string($ellipsis) <= $width ? 1 : 0);
        }

        // Binary search for the longest substring that fits
        $lo = 0;
        $hi = mb_strlen($text, 'UTF-8');

        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($text, 0, $mid, 'UTF-8');

            if (Width::string($candidate) <= $availableWidth) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($lo === 0) {
            return Ansi::strip($ellipsis);
        }

        return Ansi::strip(mb_substr($text, 0, $lo, 'UTF-8')) . $ellipsis;
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

            return str_repeat($fill, $padding) . Ansi::strip($text);
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
