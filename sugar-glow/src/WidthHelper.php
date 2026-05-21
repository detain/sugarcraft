<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

/**
 * CJK and emoji width handling via mb_strwidth.
 *
 * Mirrors charmbracelet/glow's Unicode width handling.
 */
final class WidthHelper
{
    /**
     * Compute the visual width of a string (accounts for full-width CJK/emoji).
     */
    public static function visualWidth(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return mb_strwidth($text, 'UTF-8');
    }

    /**
     * Pad a string on the right to a fixed visual width.
     */
    public static function padRight(string $text, int $totalWidth, string $padChar = ' '): string
    {
        if ($totalWidth <= 0) {
            return '';
        }

        $currentWidth = self::visualWidth($text);

        if ($currentWidth >= $totalWidth) {
            return $text;
        }

        $padLength = $totalWidth - $currentWidth;
        return $text . str_repeat($padChar, $padLength);
    }

    /**
     * Slice a string by visual width (start and end are visual widths).
     */
    public static function slice(string $text, int $start, int $end): string
    {
        if ($text === '' || $start >= $end || $start >= self::visualWidth($text)) {
            return '';
        }

        $result = '';
        $cursor = 0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            return '';
        }

        foreach ($chars as $char) {
            $charWidth = self::visualWidth($char);

            if ($cursor + $charWidth > $start) {
                if ($cursor >= $end) {
                    break;
                }
                $result .= $char;
            }

            $cursor += $charWidth;
        }

        return $result;
    }

    /**
     * Determine if a single character is full-width (CJK or emoji).
     */
    public static function isFullWidth(string $char): bool
    {
        if ($char === '') {
            return false;
        }

        return mb_strwidth($char, 'UTF-8') > 1;
    }

    /**
     * Truncate a string to a maximum visual width.
     */
    public static function truncate(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        $currentWidth = self::visualWidth($text);

        if ($currentWidth <= $maxWidth) {
            return $text;
        }

        return self::slice($text, 0, $maxWidth);
    }
}
