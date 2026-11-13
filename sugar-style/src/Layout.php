<?php

declare(strict_types=1);

namespace SugarCraft\Style;

use SugarCraft\Style\Lang;
use SugarCraft\Core\Util\Width as WidthUtil;

/**
 * Compositional layout primitives.
 *
 * Mirrors lipgloss's package-level layout functions:
 *  - {@see joinHorizontal()} / {@see joinVertical()} stitch blocks together
 *  - {@see place()}, {@see placeHorizontal()}, {@see placeVertical()}
 *    position content within a fixed outer rectangle
 *  - {@see width()}, {@see height()}, {@see size()} measure rendered
 *    blocks (cell width / line count) so callers can centre, align,
 *    or pad based on actual rendered dimensions.
 *
 * All functions assume the input strings are already-rendered blocks
 * (multi-line OK, ANSI sequences passthrough). Width is measured in
 * cell columns via {@see \SugarCraft\Core\Util\Width::string()}.
 */
final class Layout
{
    /**
     * Cell width of `$block`. For multi-line blocks it's the widest line.
     */
    public static function width(string $block): int
    {
        if ($block === '') {
            return 0;
        }
        $max = 0;
        foreach (explode("\n", $block) as $line) {
            $w = WidthUtil::string($line);
            if ($w > $max) {
                $max = $w;
            }
        }
        return $max;
    }

    /** Number of lines in `$block`. */
    public static function height(string $block): int
    {
        if ($block === '') {
            return 1;
        }
        return substr_count($block, "\n") + 1;
    }

    /**
     * Width / height pair. Useful for checking that a child fits inside
     * a fixed-size container before placing.
     *
     * @return array{0:int,1:int}
     */
    public static function size(string $block): array
    {
        return [self::width($block), self::height($block)];
    }

    /**
     * Stitch blocks side by side. `$pos` is a vertical anchor (Top/
     * Center/Bottom or any 0.0-1.0 float): shorter blocks are padded
     * vertically with blank lines anchored per `$pos`. Per-line widths
     * are equalised with trailing spaces so the output is rectangular.
     *
     * When `$spacing` is set, that many blank columns are inserted
     * between each pair of adjacent blocks (but not before the first
     * or after the last).
     *
     * Mirrors `lipgloss.JoinHorizontal(pos, blocks...)`.
     */
    public static function joinHorizontal(float $pos, string ...$blocks): string
    {
        return self::joinHorizontalWithSpacing($pos, null, ...$blocks);
    }

    /**
     * Shared fail-fast for the spacing legs of both join methods
     * (finding 004): previously a negative gap raised a raw ValueError
     * from str_repeat() horizontally but silently meant "no gap"
     * vertically — the divergence itself was the defect. House pattern
     * is Style::tabWidth: reject programmer error loudly at the door.
     */
    private static function checkSpacing(?int $spacing): void
    {
        if ($spacing !== null && $spacing < 0) {
            throw new \InvalidArgumentException(Lang::t('style.spacing_nonneg'));
        }
    }

    /**
     * Stitch blocks side by side with explicit spacing.
     *
     * @param float  $pos     Vertical anchor (0.0-1.0)
     * @param int|null $spacing Horizontal gap between blocks (null = no gap)
     * @param string ...$blocks
     */
    public static function joinHorizontalWithSpacing(float $pos, ?int $spacing, string ...$blocks): string
    {
        self::checkSpacing($spacing);
        if ($blocks === []) {
            return '';
        }
        $pos = max(0.0, min(1.0, $pos));
        // Finding 031: measure every line exactly once up front. Before
        // this the equalise pass re-walked each line inside padRight()
        // after self::width() had already measured it.
        [$cols, $lineW, $widths] = self::scan($blocks);
        $maxRows = 0;
        foreach ($cols as $lines) {
            if (count($lines) > $maxRows) {
                $maxRows = count($lines);
            }
        }

        // Pad each column to maxRows, anchoring per $pos.
        foreach ($cols as $i => $lines) {
            $extra = $maxRows - count($lines);
            if ($extra > 0) {
                $top = (int) round($pos * $extra);
                $bottom = $extra - $top;
                $lines = array_merge(
                    array_fill(0, $top, ''),
                    $lines,
                    array_fill(0, $bottom, ''),
                );
                $lineWidths = array_merge(
                    array_fill(0, $top, 0),
                    $lineW[$i],
                    array_fill(0, $bottom, 0),
                );
            } else {
                $lineWidths = $lineW[$i];
            }
            // Pad each line to its column's width with spaces. Identical
            // to Width::padRight() (which pads `$s . str_repeat(' ',
            // $width - string($s))`) but reads the memoised width.
            $colWidth = $widths[$i];
            foreach ($lines as $j => $line) {
                $gap = $colWidth - $lineWidths[$j];
                if ($gap > 0) {
                    $lines[$j] = $line . str_repeat(' ', $gap);
                }
            }
            $cols[$i] = $lines;
        }

        $spacingStr = $spacing !== null ? str_repeat(' ', $spacing) : '';

        $rows = [];
        for ($r = 0; $r < $maxRows; $r++) {
            $row = '';
            foreach ($cols as $idx => $col) {
                $row .= $col[$r];
                if ($spacingStr !== '' && $idx < count($cols) - 1) {
                    $row .= $spacingStr;
                }
            }
            $rows[] = $row;
        }
        return implode("\n", $rows);
    }

    /**
     * Stack blocks top-to-bottom. `$pos` is a horizontal anchor
     * (Left/Center/Right or any 0.0-1.0 float): narrower blocks get
     * padded horizontally with spaces anchored per `$pos`.
     *
     * When `$spacing` is set, that many blank lines are inserted
     * between each pair of adjacent blocks (but not before the first
     * or after the last).
     *
     * Mirrors `lipgloss.JoinVertical(pos, blocks...)`.
     */
    public static function joinVertical(float $pos, string ...$blocks): string
    {
        return self::joinVerticalWithSpacing($pos, null, ...$blocks);
    }

    /**
     * Stack blocks top-to-bottom with explicit spacing.
     *
     * @param float  $pos     Horizontal anchor (0.0-1.0)
     * @param int|null $spacing Vertical gap between blocks (null = no gap)
     * @param string ...$blocks
     */
    public static function joinVerticalWithSpacing(float $pos, ?int $spacing, string ...$blocks): string
    {
        self::checkSpacing($spacing);
        if ($blocks === []) {
            return '';
        }
        // Finding 031: one measurement sweep shared by the merge loop.
        [$blockLines, $blockLineW, $blockW] = self::scan($blocks);
        return self::joinVerticalMeasured($pos, $spacing, $blockLines, $blockLineW, max($blockW));
    }

    /**
     * Spacing rows are $width blanks; content lines are padded to the
     * shared widest-line width using the memoised per-line table.
     *
     * @param list<list<string>> $blockLines
     * @param list<list<int>>    $blockLineW
     */
    private static function joinVerticalMeasured(float $pos, ?int $spacing, array $blockLines, array $blockLineW, int $width): string
    {
        $pos = max(0.0, min(1.0, $pos));
        $rows = [];
        foreach ($blockLines as $blockIdx => $lines) {
            foreach ($lines as $j => $line) {
                $extra = $width - $blockLineW[$blockIdx][$j];
                if ($extra <= 0) {
                    $rows[] = $line;
                    continue;
                }
                $left = (int) round($pos * $extra);
                $right = $extra - $left;
                $rows[] = str_repeat(' ', $left) . $line . str_repeat(' ', $right);
            }
            // Insert spacing rows after each block except the last
            if ($spacing !== null && $blockIdx < count($blockLines) - 1) {
                for ($s = 0; $s < $spacing; $s++) {
                    $rows[] = str_repeat(' ', $width);
                }
            }
        }
        return implode("\n", $rows);
    }

    /**
     * Split every block into lines and measure each line exactly once
     * (finding 031). Returns [lines, per-line widths, per-block widths]
     * as parallel lists; the block width is the widest line, matching
     * {@see width()} — including the 0-width single empty line an
     * empty string yields.
     *
     * @param list<string> $blocks
     * @return array{list<list<string>>, list<list<int>>, list<int>}
     */
    private static function scan(array $blocks): array
    {
        $lines = [];
        $lineW = [];
        $blockW = [];
        foreach ($blocks as $b) {
            $bl = explode("\n", $b);
            $width = 0;
            $ws = [];
            foreach ($bl as $line) {
                $w = WidthUtil::string($line);
                $ws[] = $w;
                if ($w > $width) {
                    $width = $w;
                }
            }
            $lines[] = $bl;
            $lineW[] = $ws;
            $blockW[] = $width;
        }
        return [$lines, $lineW, $blockW];
    }

    /**
     * Stitch blocks side by side with a divider column between each
     * adjacent pair. Empty blocks (`''`, zero width) are dropped together
     * with their divider, so a collapsed pane leaves no orphan rule.
     * A single-line `$divider` is repeated on every row; a multi-line one
     * is laid out row by row (missing rows blank). An empty `$divider`
     * degrades to {@see joinHorizontal()}.
     *
     * The generalisation of a split pane's "left, rule, right" row
     * composition; the rational resize algebra that decides pane widths
     * stays with the caller.
     */
    public static function joinHorizontalWithDivider(float $pos, string $divider, string ...$blocks): string
    {
        $blocks = array_values(array_filter($blocks, static fn (string $b): bool => $b !== ''));
        if ($blocks === [] || count($blocks) === 1 || $divider === '') {
            return self::joinHorizontal($pos, ...$blocks);
        }
        $rows = 1;
        foreach ($blocks as $b) {
            $rows = max($rows, self::height($b));
        }
        $dividerBlock = self::height($divider) === 1
            ? implode("\n", array_fill(0, $rows, $divider))
            : $divider;
        $parts = [];
        foreach ($blocks as $i => $b) {
            if ($i > 0) {
                $parts[] = $dividerBlock;
            }
            $parts[] = $b;
        }
        return self::joinHorizontal($pos, ...$parts);
    }

    /**
     * Stack blocks top-to-bottom with a rule line between each adjacent
     * pair, the rule tiled (repeated, then cut on a cell boundary) to the
     * widest block. Empty blocks are dropped together with their rule.
     * An empty `$divider` degrades to {@see joinVertical()}.
     */
    public static function joinVerticalWithDivider(float $pos, string $divider, string ...$blocks): string
    {
        $blocks = array_values(array_filter($blocks, static fn (string $b): bool => $b !== ''));
        if ($blocks === [] || count($blocks) === 1 || $divider === '') {
            return self::joinVertical($pos, ...$blocks);
        }
        // Finding 031: the rule tile needs the widest block width, and the
        // join needs per-line widths — share one scan instead of measuring
        // every block twice (tile pass, then the delegated join re-walk).
        [$blockLines, $blockLineW, $blockW] = self::scan($blocks);
        $width = max($blockW);
        $rule = self::tile($divider, $width);
        $ruleLines = explode("\n", $rule);
        $ruleW = [];
        $ruleWidth = 0;
        foreach ($ruleLines as $rl) {
            $w = WidthUtil::string($rl);
            $ruleW[] = $w;
            if ($w > $ruleWidth) {
                $ruleWidth = $w;
            }
        }
        $partLines = [];
        $partLineW = [];
        foreach ($blockLines as $i => $lines) {
            if ($i > 0) {
                $partLines[] = $ruleLines;
                $partLineW[] = $ruleW;
            }
            $partLines[] = $lines;
            $partLineW[] = $blockLineW[$i];
        }
        return self::joinVerticalMeasured($pos, null, $partLines, $partLineW, max($width, $ruleWidth));
    }

    /** Repeat `$unit` until it covers `$width` cells, then cut to exactly that. */
    public static function tile(string $unit, int $width): string
    {
        $unitW = WidthUtil::string($unit);
        if ($width <= 0 || $unitW <= 0) {
            return '';
        }
        $out = str_repeat($unit, intdiv($width + $unitW - 1, $unitW));
        return WidthUtil::string($out) > $width ? WidthUtil::truncateAnsi($out, $width) : $out;
    }

    /** Keep at most `$rows` lines from the top of `$block`. */
    public static function clipHead(string $block, int $rows): string
    {
        $lines = explode("\n", $block);
        return count($lines) <= $rows ? $block : implode("\n", array_slice($lines, 0, max(0, $rows)));
    }

    /** Keep at most `$rows` lines from the bottom of `$block`. */
    public static function clipTail(string $block, int $rows): string
    {
        $lines = explode("\n", $block);
        if (count($lines) <= $rows) {
            return $block;
        }
        return $rows <= 0 ? '' : implode("\n", array_slice($lines, -$rows));
    }

    /**
     * Keep at most `$cols` visible cells of every line. The cut is
     * ANSI-aware (escapes carry no width, a trailing reset survives) and
     * lands on a grapheme boundary. With `$ellipsis` set an overlong line
     * keeps `$cols - width(ellipsis)` cells and gains the ellipsis.
     */
    public static function clipWidth(string $block, int $cols, string $ellipsis = ''): string
    {
        if ($cols <= 0) {
            return '';
        }
        $ellW = WidthUtil::string($ellipsis);
        $lines = explode("\n", $block);
        foreach ($lines as $i => $line) {
            if (WidthUtil::string($line) > $cols) {
                $lines[$i] = $ellipsis === '' || $ellW >= $cols
                    ? WidthUtil::truncateAnsi($line, $cols)
                    : WidthUtil::truncateAnsi($line, $cols - $ellW) . $ellipsis;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Position `$block` within a `$width × $height` rectangle. `$hPos`
     * and `$vPos` are 0.0-1.0 anchors (use the {@see Position}
     * constants). The fill character defaults to a space; passing a
     * styled blank (e.g. `Style::new()->background(...)->render(' ')`)
     * lets the surrounding area carry a colour.
     *
     * Mirrors `lipgloss.Place(width, height, hPos, vPos, str, opts...)`.
     */
    public static function place(
        int $width,
        int $height,
        float $hPos,
        float $vPos,
        string $block,
        string $fill = ' ',
    ): string {
        $hPos = max(0.0, min(1.0, $hPos));
        $vPos = max(0.0, min(1.0, $vPos));
        $blockW = self::width($block);
        $blockH = self::height($block);

        $lines = $block === '' ? [''] : explode("\n", $block);

        // Horizontal placement first: pad each line to $width.
        $padded = [];
        foreach ($lines as $line) {
            $w = WidthUtil::string($line);
            $extra = $width - $w;
            if ($extra <= 0) {
                $padded[] = $line;
                continue;
            }
            $left = (int) round($hPos * $extra);
            $right = $extra - $left;
            $padded[] = str_repeat($fill, $left) . $line . str_repeat($fill, $right);
        }

        // Vertical placement: pad above/below.
        $vExtra = $height - $blockH;
        if ($vExtra > 0) {
            $top = (int) round($vPos * $vExtra);
            $bottom = $vExtra - $top;
            $blank = str_repeat($fill, $width);
            $padded = array_merge(
                array_fill(0, $top, $blank),
                $padded,
                array_fill(0, $bottom, $blank),
            );
        }
        return implode("\n", $padded);
    }

    /**
     * Position horizontally only — pads each line in `$block` to
     * `$width` with `$fill`, anchored per `$pos`. No vertical change.
     */
    public static function placeHorizontal(int $width, float $pos, string $block, string $fill = ' '): string
    {
        $pos = max(0.0, min(1.0, $pos));
        $lines = $block === '' ? [''] : explode("\n", $block);
        $out = [];
        foreach ($lines as $line) {
            $w = WidthUtil::string($line);
            $extra = $width - $w;
            if ($extra <= 0) {
                $out[] = $line;
                continue;
            }
            $left = (int) round($pos * $extra);
            $right = $extra - $left;
            $out[] = str_repeat($fill, $left) . $line . str_repeat($fill, $right);
        }
        return implode("\n", $out);
    }

    /**
     * Position vertically only — pads `$block` above/below to reach
     * `$height` rows, anchored per `$pos`. Each blank row is `width($block)`
     * cells wide.
     */
    public static function placeVertical(int $height, float $pos, string $block, string $fill = ' '): string
    {
        $pos = max(0.0, min(1.0, $pos));
        $blockH = self::height($block);
        $blockW = self::width($block);
        $extra = $height - $blockH;
        if ($extra <= 0) {
            return $block;
        }
        $top = (int) round($pos * $extra);
        $bottom = $extra - $top;
        $blank = str_repeat($fill, $blockW);
        $rows = array_merge(
            array_fill(0, $top, $blank),
            $block === '' ? [''] : explode("\n", $block),
            array_fill(0, $bottom, $blank),
        );
        return implode("\n", $rows);
    }
}
