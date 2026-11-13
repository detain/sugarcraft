<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Output;

use SugarCraft\Core\Util\Width;

/**
 * Helper for wrapping text into cells/columns.
 *
 * Provides word-wrapping functionality that respects ANSI escape codes
 * and maintains proper cell alignment. Slices happen at DISPLAY-CELL
 * boundaries over the original string via Width's canonical takeAnsi/
 * dropAnsi pair — nothing is stripped, no escape is cut mid-sequence,
 * and no word remainder is ever discarded (finding sugar-dashboard-037).
 */
final class WrapCells
{
    /**
     * Wrap text to fit within a given width.
     *
     * A whitespace run that no longer fits at the current position is
     * dropped: that is the wrap point itself. When $breakWords is false,
     * a word that does not fit the current line moves whole to the next
     * line — even if it is wider than the line itself (it then owns that
     * line untouched, an honest overflow rather than a silent break).
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

            $words = preg_split('/(\s+)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $currentLine = '';
            $currentWidth = 0;

            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }

                $wordWidth = Width::string($word);

                // Handle whitespace: keep it inside a line when it fits; a
                // run that overflows marks the break and is dropped.
                if ($wordWidth === 0 || preg_match('/^\s+$/', $word) !== 0) {
                    if ($wordWidth <= $width - $currentWidth) {
                        $currentLine .= $word;
                        $currentWidth += $wordWidth;
                    }
                    continue;
                }

                // Word fits on the current line — place it untouched.
                if ($wordWidth <= $width - $currentWidth) {
                    $currentLine .= $word;
                    $currentWidth += $wordWidth;
                    continue;
                }

                // The word needs the next line. Without breakWords it moves
                // whole (over-wide words own their line as-is).
                if (!$breakWords) {
                    if ($currentLine !== '') {
                        $lines[] = $currentLine;
                    }
                    $currentLine = $word;
                    $currentWidth = $wordWidth;
                    continue;
                }

                // breakWords: slice the ORIGINAL at cell boundaries so SGR
                // sequences survive the cut and every remainder is carried.
                $remaining = $word;
                while ($remaining !== '') {
                    if (Width::string($remaining) === 0) {
                        // Only escape bytes remain (e.g. a harvested reset):
                        // they carry no cells, so let them ride the current
                        // line instead of spinning on a zero-width piece.
                        $currentLine .= $remaining;
                        $remaining = '';
                        continue;
                    }

                    $budget = $width - $currentWidth;
                    if ($budget <= 0) {
                        $lines[] = $currentLine;
                        $currentLine = '';
                        $currentWidth = 0;
                        continue;
                    }

                    $piece = self::fitAnsi($remaining, $budget);

                    if ($piece === null) {
                        // The leading cluster alone exceeds the remaining
                        // budget: flush and let it start a fresh line.
                        if ($currentLine !== '') {
                            $lines[] = $currentLine;
                            $currentLine = '';
                            $currentWidth = 0;
                            continue;
                        }
                        // Even a whole line cannot hold one cluster (e.g. a
                        // 2-cell glyph at width 1): it owns the line anyway
                        // — an honest overflow beats losing the glyph.
                        $piece = self::firstCluster($remaining);
                    }

                    $pieceWidth = Width::string($piece);
                    $currentLine .= $piece;
                    $currentWidth += $pieceWidth;
                    $remaining = Width::dropAnsi($remaining, $pieceWidth);

                    if ($remaining !== '' && $currentWidth >= $width) {
                        $lines[] = $currentLine;
                        $currentLine = '';
                        $currentWidth = 0;
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
     * The longest cell-accurate ANSI-preserving prefix of $text that fits
     * within $budget display cells, or null when not even the leading
     * cluster fits. Width::takeAnsi includes a straddling wide cluster
     * whole, so the first probe can overshoot; walking the budget down a
     * cell at a time is bounded by that overshoot (a cluster is never
     * more than a couple of cells wide).
     */
    private static function fitAnsi(string $text, int $budget): ?string
    {
        for ($cells = $budget; $cells >= 1; $cells--) {
            $piece = Width::takeAnsi($text, $cells);
            if ($piece === '') {
                return null;
            }
            if (Width::string($piece) <= $cells) {
                return $piece;
            }
        }

        return null;
    }

    /** The leading cluster (grapheme) of $text. */
    private static function firstCluster(string $text): string
    {
        return Width::nextCluster($text, 0);
    }

    /**
     * Wrap and pad lines to a consistent width.
     *
     * Padding is measured in DISPLAY CELLS, not codepoints, so styled or
     * wide-character lines still land on the grid.
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
            static fn (string $line): string => Width::padRight($line, $width, $pad),
            $lines
        );
    }
}
