<?php

declare(strict_types=1);

/**
 * candy-tetris/src/Support/Paint.php
 *
 * The one home for the overlay machinery both renderers share (candy-tetris-027):
 * the banner stripe, the no-TTY refusal, the sub-floor resize notice and the
 * cell-map builder existed as character-identical copies in Renderer and
 * VsRenderer — and the copy drift is exactly the bug class that bred the
 * 20-column notice floor "fixed… nowhere, twice" (candy-tetris-024). One
 * implementation now answers for both frames: a change to the stripe, the
 * refusal wording door or the notice wrap lands on solo and VS together.
 */
namespace SugarCraft\Tetris\Support;

use SugarCraft\Core\Util\Width;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Lang;

final class Paint
{
    /**
     * Replace the vertically-centred band of full-width rows through the
     * body with the given plain-text lines, each centred across that
     * width. The frame's row and column counts NEVER change — an overlay
     * is a stripe across the playfield, not content appended past the
     * fold (the C-2 below-the-bend defect this posture cured).
     *
     * Candy-tetris-055, stated honestly: the stripe spans the WHOLE row,
     * border cells included, for the rows it covers — it is a pause-sign,
     * not a translucent band. A repaint that kept the side borders alive
     * underneath SGR-carrying frame bytes measured as a style-lie (the
     * border colour would belong to cells the eye reads as erased); the
     * contract here is instead exact: same row count, same width budget,
     * centred text, truncated to width when a locale's line outgrows the
     * board. The README framing follows this wording (chain step -36).
     *
     * @param list<string> $lines
     */
    public static function banner(string $body, array $lines): string
    {
        $rows = explode("\n", $body);
        $width = 0;
        foreach ($rows as $row) {
            $width = max($width, Width::of($row));
        }

        $top = intdiv(Board::VISIBLE_ROWS - count($lines), 2);
        foreach ($lines as $i => $line) {
            $row = $top + $i;
            if ($row < 0 || $row >= count($rows)) {
                continue;
            }
            $text = Width::of($line) > $width
                ? Width::truncateAnsi($line, $width)
                : $line;
            $left = max(0, intdiv($width - Width::of($text), 2));
            $rows[$row] = \str_repeat(' ', $left) . $text;
        }

        return implode("\n", $rows);
    }

    /**
     * Plain-text refusal for a terminal with no rendering capability at
     * all (MA-12): zero SGR bytes, nothing to misparse. The floor is the
     * TTY itself — colour is optional (NO_COLOR/CLICOLOR=0 play fine),
     * so the copy must not demand colour (candy-tetris-041); one line
     * now answers for both modes (candy-tetris-027).
     */
    public static function refusal(): string
    {
        return Lang::t('cli.refusal') . "\n";
    }

    /**
     * Notice shown below the frame floor; wrapped to the ACTUAL terminal
     * width (candy-tetris-024: the old max(20, cols) let the very notice
     * explaining that the frame won't fit overflow a narrower terminal,
     * clipping mid-word). A word longer than the width is emitted on its
     * own line and clips by character — the notice staying on-screen
     * matters more than the notice staying whole.
     */
    public static function tooSmallNotice(string $key, int $minCols, int $minRows, int $cols, int $rows): string
    {
        $notice = Lang::t($key, [
            'size' => $minCols . 'x' . $minRows,
            'actual' => $cols . 'x' . $rows,
        ]) . "\n";

        return self::wrap($notice, max(1, $cols));
    }

    /**
     * Greedy word wrap of plain text honouring existing newlines. A word
     * wider than the whole width is hard-split by codepoints (width-aware:
     * an east-asian cell consumes two) — below 20 columns the notice's
     * longest words outgrow the terminal, and a wrap that lets them
     * through re-creates the clip it exists to cure (candy-tetris-024).
     */
    public static function wrap(string $text, int $width): string
    {
        $out = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/ +/', $paragraph) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                while (Width::of($word) > $width) {
                    [$hard, $word] = self::splitAtWidth($word, $width);
                    if ($line !== '') {
                        $out[] = $line;
                        $line = '';
                    }
                    $out[] = $hard;
                }
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (Width::of($candidate) > $width && $line !== '') {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }
            $out[] = $line;
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Cut $word at the last codepoint that still fits, returning
     * [head, tail]. Guarded by the caller's width door; a single
     * codepoint wider than $width (a fullwidth cell in width 1) yields
     * head = one cell — the least-overflow line possible.
     *
     * @return array{0:string,1:string}
     */
    private static function splitAtWidth(string $word, int $width): array
    {
        $chars = mb_str_split($word);
        $head = '';
        $used = 0;
        foreach ($chars as $i => $char) {
            $advance = mb_strwidth($char, 'UTF-8');
            if ($used + $advance > $width && $i !== 0) {
                return [$head, implode('', array_slice($chars, $i))];
            }
            $head .= $char;
            $used += $advance;
        }

        return [$head, ''];
    }

    /**
     * Occupancy lookup for a piece cell list, keyed "x,y".
     *
     * @param list<array{int,int}> $cells
     *
     * @return array<string,true>
     */
    public static function cellMap(array $cells): array
    {
        $out = [];
        foreach ($cells as [$x, $y]) {
            $out["$x,$y"] = true;
        }

        return $out;
    }
}
