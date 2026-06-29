<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Output;

use SugarCraft\Core\Util\Ansi;
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
                // Strip ANSI from the word for output (keep width calculation using original)
                $wordClean = Ansi::strip($word);

                // Handle whitespace
                if ($word !== '' && preg_match('/^\s+$/', $word) !== 0) {
                    $spaceWidth = $wordWidth;
                    if ($currentWidth + $spaceWidth <= $width) {
                        $currentLine .= $wordClean;
                        $currentWidth += $spaceWidth;
                    }
                    continue;
                }

                // Handle regular words
                if ($wordWidth <= $width) {
                    if ($currentWidth + $wordWidth <= $width) {
                        $currentLine .= $wordClean;
                        $currentWidth += $wordWidth;
                    } else {
                        if ($currentLine !== '') {
                            $lines[] = $currentLine;
                        }
                        $currentLine = $wordClean;
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

                            // Strip ANSI so that charsForWidth (which uses Width::string
                            // internally) finds the right character boundary in terms of
                            // display width. The stripped text is only used to determine
                            // how many characters fit; we then extract those characters
                            // from the original $remaining to preserve any surrounding ANSI.
                            $remainingClean = Ansi::strip($remaining);
                            $charsForThisChunk = self::charsForWidth($remainingClean, $chunkWidth);

                            // When charsForWidth returns 0, a single char is too wide for
                            // chunkWidth (e.g. CJK 2-cells in 1-cell slot). Extract 1 char
                            // anyway to prevent infinite loop, and flush current line.
                            if ($charsForThisChunk === 0) {
                                $charsForThisChunk = 1;
                            }

                            $chunk = mb_substr($remaining, 0, $charsForThisChunk, 'UTF-8');
                            $chunkClean = Ansi::strip($chunk);
                            $chunkWidth = Width::string($chunk);

                            // When charsForThisChunk was forced to 1 (single char too wide for
                            // remaining slot), the char doesn't fit on the current line.
                            // Flush current line first, then start a new line with the char.
                            if ($charsForThisChunk === 1 && $chunkWidth > $width - $currentWidth) {
                                if ($currentLine !== '') {
                                    $lines[] = $currentLine;
                                }
                                $currentLine = $chunkClean;
                                $currentWidth = $chunkWidth;
                            } else {
                                $currentLine .= $chunkClean;
                                $currentWidth += $chunkWidth;
                            }
                            $remaining = mb_substr($remaining, mb_strlen($chunk, 'UTF-8'), null, 'UTF-8');

                            if ($currentWidth >= $width && $currentLine !== '') {
                                $lines[] = $currentLine;
                                $currentLine = '';
                                $currentWidth = 0;
                            }
                        }
                    } else {
                        // Long word that overflows: push the accumulated line
                        // (even if empty, to count the "new line" slot), then
                        // place the word on its own line.
                        $lines[] = $currentLine;
                        $currentLine = $wordClean;
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
