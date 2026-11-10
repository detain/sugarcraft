<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Core\Util\Width;

/**
 * Re-wraps the `--help` page for terminals wider than the 80-column budget
 * the page was authored to, so a wide window reads the same document in far
 * fewer rows without losing a word.
 *
 * WHY THIS EXISTS. The page ({@see Help::screen()}) ships as one aligned
 * catalogue entry, and at or below the authored budget it must stay
 * byte-identical — CI, pipes and default terminals all take that path via
 * the guard clause below, not via a happy accident of the walk.
 *
 * WHAT IS PRESERVED. Structure is not content: blocks, blank-line
 * separators, section order, key columns and every word survive exactly.
 * A table row's raw prefix (key, gap, and the column the descriptions share)
 * is re-emitted byte-exact; only the description re-wraps into the wider
 * budget. Runs of two or more spaces INSIDE wrapped prose collapse to one
 * space in the wider render — the collapse is the re-wrap, and the
 * whitespace-stripped byte equality of the whole screen against the page
 * is pinned by HelpTest. Blocks with no repeated description column to
 * honour (Examples, the footer) are opaque passthroughs: their bytes never
 * move at any width.
 */
final class HelpPageReflow
{
    /**
     * The authored budget: at or below it the page is returned verbatim.
     * This is the same number the help resolver falls back to — one source.
     */
    public const MIN_WIDTH = 80;

    /**
     * Layout ceiling: past this the key and description columns of a row
     * drift so far apart the block stops reading as a table.
     */
    public const MAX_WIDTH = 200;

    /**
     * @return string the page re-wrapped to render at $width columns
     */
    public static function reflow(string $page, int $width): string
    {
        if ($width <= self::MIN_WIDTH) {
            return $page;
        }

        $lines = explode("\n", $page);
        $out = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            if (trim($lines[$i]) === '') {
                $out[] = $lines[$i];
                $i++;

                continue;
            }

            $start = $i;
            while ($i < $n && trim($lines[$i]) !== '') {
                $i++;
            }
            foreach (self::reflowBlock(array_slice($lines, $start, $i - $start), $width) as $row) {
                $out[] = $row;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Reflow one block (a maximal run of non-blank lines).
     *
     * @param list<string> $block
     *
     * @return list<string>
     */
    private static function reflowBlock(array $block, int $width): array
    {
        $d = self::modalIndent($block);
        if ($d === null) {
            return $block; // opaque: no repeated description column to honour
        }

        $n = count($block);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $line = $block[$i];
            $ind = self::indentOf($line);
            $gap = self::gapColumn($line);

            if ($ind <= $d - 2 && $gap !== null) {
                // Inline record: raw prefix + gap + description, absorbing
                // the wrapped continuation rows the author left underneath.
                $offset = self::cellToByte($line, $gap);
                $prefix = rtrim(substr($line, 0, $offset), ' ');
                $gapRaw = substr($line, strlen($prefix), $offset - strlen($prefix));
                $parts = [trim(substr($line, $offset))];
                $j = $i + 1;
                while ($j < $n && !self::isRecordStart($block, $j, $d, $n) && self::indentOf($block[$j]) >= $d - 1) {
                    $parts[] = trim($block[$j]);
                    $j++;
                }

                $pad = Width::of($prefix) + Width::of($gapRaw);
                $wrapped = explode("\n", Width::wrap(self::paragraph($parts), max(1, $width - $pad)));
                $out[] = $prefix . $gapRaw . array_shift($wrapped);
                foreach ($wrapped as $row) {
                    $out[] = str_repeat(' ', $pad) . $row;
                }
                $i = $j;

                continue;
            }

            if ($ind <= $d - 2 && $i + 1 < $n && self::indentOf($block[$i + 1]) >= $d - 1) {
                // Stacked record: a long key line owns its row, and the
                // description begins indented under it at the column D.
                $parts = [trim($block[$i + 1])];
                $j = $i + 2;
                while ($j < $n && !self::isRecordStart($block, $j, $d, $n) && self::indentOf($block[$j]) >= $d - 1) {
                    $parts[] = trim($block[$j]);
                    $j++;
                }
                $out[] = rtrim($line);
                foreach (explode("\n", Width::wrap(self::paragraph($parts), max(1, $width - $d))) as $row) {
                    $out[] = str_repeat(' ', $d) . $row;
                }
                $i = $j;

                continue;
            }

            if ($ind >= $d - 1) {
                // Stray description rows with no open record: wrap in place.
                $parts = [];
                $j = $i;
                while ($j < $n && !self::isRecordStart($block, $j, $d, $n) && self::indentOf($block[$j]) >= $d - 1) {
                    $parts[] = trim($block[$j]);
                    $j++;
                }
                foreach (explode("\n", Width::wrap(self::paragraph($parts), max(1, $width - $ind))) as $row) {
                    $out[] = str_repeat(' ', $ind) . $row;
                }
                $i = $j;

                continue;
            }

            // Prose run: consecutive low lines with no description column
            // form one paragraph and re-wrap at their shared indent.
            $parts = [];
            $pad = $ind;
            $j = $i;
            while ($j < $n && !self::isRecordStart($block, $j, $d, $n) && self::indentOf($block[$j]) <= $d - 2) {
                $parts[] = trim($block[$j]);
                $pad = min($pad, self::indentOf($block[$j]));
                $j++;
            }
            if ($parts === []) {
                $out[] = $line;
                $i++;

                continue;
            }
            foreach (explode("\n", Width::wrap(self::paragraph($parts), max(1, $width - $pad))) as $row) {
                $out[] = str_repeat(' ', $pad) . $row;
            }
            $i = $j;
        }

        return $out;
    }

    /**
     * A row starts a new record when it sits left of the description column
     * and either carries its own description inline (the gap) or is followed
     * by a stacked description at the column.
     *
     * @param list<string> $block
     */
    private static function isRecordStart(array $block, int $i, int $d, int $n): bool
    {
        if (self::indentOf($block[$i]) > $d - 2) {
            return false;
        }
        if (self::gapColumn($block[$i]) !== null) {
            return true;
        }

        return $i + 1 < $n && self::indentOf($block[$i + 1]) >= $d - 1;
    }

    /**
     * The block's description column: the modal indent of its deep lines.
     * A block whose deep indents do not repeat has no table to honour and
     * stays opaque.
     *
     * @param list<string> $lines
     */
    private static function modalIndent(array $lines): ?int
    {
        $hist = [];
        foreach ($lines as $line) {
            $ind = self::indentOf($line);
            if ($ind < 6) {
                continue;
            }
            $hist[$ind] = ($hist[$ind] ?? 0) + 1;
        }
        if ($hist === []) {
            return null;
        }
        arsort($hist);
        $best = array_key_first($hist);

        return $hist[$best] < 2 ? null : $best;
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * Cell column of the first token that follows a run of at least two
     * spaces past the leading indent — the column an inline description
     * starts at — or null when the line carries no such gap.
     */
    private static function gapColumn(string $line): ?int
    {
        $indent = self::indentOf($line);
        $body = substr($line, $indent);
        if (preg_match('/\S+(\s{2,})(\S)/u', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $runStart = $indent + $m[0][1] + strlen($m[0][0]) - strlen($m[1][0]) - strlen($m[2][0]);
        if ($runStart < $indent + 1) {
            return null;
        }

        return Width::of(substr($line, 0, $runStart + strlen($m[1][0])));
    }

    /**
     * Join record fragments into one paragraph: interior whitespace runs
     * (including the double spaces the author used after sentence-ending
     * gaps inside wrapped rows) collapse to single spaces, which is exactly
     * what re-wrapping a paragraph across a wider budget means.
     *
     * @param list<string> $parts
     */
    private static function paragraph(array $parts): string
    {
        return implode(' ', array_map(
            static fn (string $s): string => implode(' ', preg_split('/\s+/u', trim($s)) ?: []),
            $parts,
        ));
    }

    /**
     * Byte offset where $cells display columns have been consumed — the
     * cut point between a row's raw prefix and its description.
     */
    private static function cellToByte(string $line, int $cells): int
    {
        $bytes = 0;
        $seen = 0;
        $len = strlen($line);
        while ($bytes < $len && $seen < $cells) {
            $ch = mb_substr($line, $seen, 1, 'UTF-8');
            $bytes += strlen($ch);
            $seen += Width::of($ch);
        }

        return $bytes;
    }
}
