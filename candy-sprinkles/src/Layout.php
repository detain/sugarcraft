<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

use SugarCraft\Core\Util\Width as WidthUtil;

/**
 * Position constants for {@see Layout::place()}. Match lipgloss's
 * `Top` / `Bottom` / `Left` / `Right` / `Center` floats: 0.0 anchors
 * to the start, 1.0 anchors to the end, 0.5 centres.
 */
final class Position
{
    public const TOP    = 0.0;
    public const LEFT   = 0.0;
    public const CENTER = 0.5;
    public const RIGHT  = 1.0;
    public const BOTTOM = 1.0;
}

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
     * Stitch blocks side by side with explicit spacing.
     *
     * @param float  $pos     Vertical anchor (0.0-1.0)
     * @param int|null $spacing Horizontal gap between blocks (null = no gap)
     * @param string ...$blocks
     */
    public static function joinHorizontalWithSpacing(float $pos, ?int $spacing, string ...$blocks): string
    {
        if ($blocks === []) {
            return '';
        }
        $pos = max(0.0, min(1.0, $pos));
        $cols = [];
        $maxRows = 0;
        $widths = [];
        foreach ($blocks as $b) {
            $lines = explode("\n", $b);
            $widths[] = self::width($b);
            $cols[] = $lines;
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
            }
            // Pad each line to its column's width with spaces.
            foreach ($lines as $j => $line) {
                $lines[$j] = WidthUtil::padRight($line, $widths[$i]);
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
        if ($blocks === []) {
            return '';
        }
        $pos = max(0.0, min(1.0, $pos));
        $width = 0;
        foreach ($blocks as $b) {
            $w = self::width($b);
            if ($w > $width) {
                $width = $w;
            }
        }
        $rows = [];
        foreach ($blocks as $blockIdx => $b) {
            foreach (explode("\n", $b) as $line) {
                $w = WidthUtil::string($line);
                $extra = $width - $w;
                if ($extra <= 0) {
                    $rows[] = $line;
                    continue;
                }
                $left = (int) round($pos * $extra);
                $right = $extra - $left;
                $rows[] = str_repeat(' ', $left) . $line . str_repeat(' ', $right);
            }
            // Insert spacing rows after each block except the last
            if ($spacing !== null && $blockIdx < count($blocks) - 1) {
                for ($s = 0; $s < $spacing; $s++) {
                    $rows[] = str_repeat(' ', $width);
                }
            }
        }
        return implode("\n", $rows);
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
