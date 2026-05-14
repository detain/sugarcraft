<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Output;

use SugarCraft\Core\Util\Width;

/**
 * Helper for wrapping text into cells/columns.
 *
 * Provides word-wrapping functionality that respects ANSI escape codes
 * and maintains proper cell alignment.
 */
final class WrapCells
{
    /**
     * Wrap text to fit within a given width.
     *
     * @param string $text The text to wrap
     * @param int $width Maximum width per line
     * @param bool $breakWords Whether to break words that are too long
     * @return list<string> Wrapped lines
     */
    public static function wrap(string $text, int $width, bool $breakWords = false): array
    {
        if ($width <= 0) {
            return [''];
        }

        $lines = [];
        $paragraphs = explode("\n", $text);

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $words = preg_split('/(\s+)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE);
            $currentLine = '';
            $currentWidth = 0;

            foreach ($words as $word) {
                $wordWidth = Width::string($word);

                // Handle whitespace
                if ($word !== '' && preg_match('/^\s+$/', $word) !== 0) {
                    $spaceWidth = $wordWidth;
                    if ($currentWidth + $spaceWidth <= $width) {
                        $currentLine .= $word;
                        $currentWidth += $spaceWidth;
                    }
                    continue;
                }

                // Handle regular words
                if ($wordWidth <= $width) {
                    if ($currentWidth + $wordWidth <= $width) {
                        $currentLine .= $word;
                        $currentWidth += $wordWidth;
                    } else {
                        if ($currentLine !== '') {
                            $lines[] = $currentLine;
                        }
                        $currentLine = $word;
                        $currentWidth = $wordWidth;
                    }
                } else {
                    // Long word that needs to be broken
                    if ($breakWords) {
                        $remaining = $word;
                        while ($remaining !== '') {
                            $chunkWidth = min($wordWidth, $width - $currentWidth);
                            if ($chunkWidth <= 0) {
                                if ($currentLine !== '') {
                                    $lines[] = $currentLine;
                                }
                                $currentLine = '';
                                $currentWidth = 0;
                                $chunkWidth = min($wordWidth, $width);
                            }

                            $chunk = mb_substr($remaining, 0, self::charsForWidth($remaining, $chunkWidth), 'UTF-8');
                            $currentLine .= $chunk;
                            $currentWidth += Width::string($chunk);
                            $remaining = mb_substr($remaining, mb_strlen($chunk, 'UTF-8'), null, 'UTF-8');

                            if ($currentWidth >= $width) {
                                $lines[] = $currentLine;
                                $currentLine = '';
                                $currentWidth = 0;
                            }
                        }
                    } else {
                        // Just start a new line
                        if ($currentLine !== '') {
                            $lines[] = $currentLine;
                        }
                        $currentLine = $word;
                        $currentWidth = $wordWidth;
                    }
                }
            }

            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }
        }

        return $lines;
    }

    /**
     * Calculate how many characters fit within a given width.
     */
    private static function charsForWidth(string $text, int $maxWidth): int
    {
        $lo = 0;
        $hi = mb_strlen($text, 'UTF-8');

        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($text, 0, $mid, 'UTF-8');

            if (Width::string($candidate) <= $maxWidth) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo;
    }

    /**
     * Wrap and pad lines to a consistent width.
     *
     * @param string $text The text to wrap
     * @param int $width Maximum width per line
     * @param string $pad Character to use for padding
     * @return list<string> Wrapped and padded lines
     */
    public static function wrapAndPad(string $text, int $width, string $pad = ' '): array
    {
        $lines = self::wrap($text, $width);
        return array_map(
            fn($line) => str_pad($line, $width, $pad),
            $lines
        );
    }
}
