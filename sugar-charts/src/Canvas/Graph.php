<?php

declare(strict_types=1);

namespace CandyCore\Charts\Canvas;

use CandyCore\Sprinkles\Style;

/**
 * Drawing primitives for {@see Canvas}. Mirrors ntcharts' `canvas/graph`
 * package — every higher-level chart (LineChart axes, BarChart axes,
 * Heatmap legend, etc.) leans on these.
 *
 * All methods take a `Canvas` and modify it in place. Coordinates are
 * 0-based with (0, 0) at the top-left, matching the underlying canvas
 * convention. Bounds are clamped — drawing past the edges silently
 * truncates instead of resizing.
 */
final class Graph
{
    /** Default light-line glyphs (matches the `runes.LineStyle` "thin" preset). */
    public const LINE_THIN = [
        'h'  => '─', 'v' => '│',
        'tl' => '┌', 'tr' => '┐', 'bl' => '└', 'br' => '┘',
        'cross' => '┼', 'tee_up' => '┴', 'tee_down' => '┬',
        'tee_left' => '┤', 'tee_right' => '├',
    ];

    /** Heavier "thick" line preset. */
    public const LINE_THICK = [
        'h'  => '━', 'v' => '┃',
        'tl' => '┏', 'tr' => '┓', 'bl' => '┗', 'br' => '┛',
        'cross' => '╋', 'tee_up' => '┻', 'tee_down' => '┳',
        'tee_left' => '┫', 'tee_right' => '┣',
    ];

    /** Double-line preset. */
    public const LINE_DOUBLE = [
        'h'  => '═', 'v' => '║',
        'tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝',
        'cross' => '╬', 'tee_up' => '╩', 'tee_down' => '╦',
        'tee_left' => '╣', 'tee_right' => '╠',
    ];

    /**
     * Draw a horizontal line at row `$y` from column `$x0` to `$x1`
     * (inclusive). `$rune` defaults to the thin horizontal `─`.
     */
    public static function drawHLine(Canvas $c, int $y, int $x0, int $x1, ?Style $style = null, string $rune = '─'): void
    {
        if ($x0 > $x1) { [$x0, $x1] = [$x1, $x0]; }
        for ($x = $x0; $x <= $x1; $x++) {
            $c->setCell($x, $y, $rune, $style);
        }
    }

    /** Draw a vertical line at column `$x` from row `$y0` to `$y1`. */
    public static function drawVLine(Canvas $c, int $x, int $y0, int $y1, ?Style $style = null, string $rune = '│'): void
    {
        if ($y0 > $y1) { [$y0, $y1] = [$y1, $y0]; }
        for ($y = $y0; $y <= $y1; $y++) {
            $c->setCell($x, $y, $rune, $style);
        }
    }

    /**
     * Draw an X/Y axis frame anchored at the bottom-left corner
     * (`$xOrigin, $yOrigin`). The X axis runs `$xLen` cells to the
     * right; the Y axis runs `$yLen` cells up. The intersection cell
     * uses the bottom-left corner glyph (`└`).
     *
     * @param array<string,string> $runes  one of LINE_THIN / THICK / DOUBLE
     */
    public static function drawXYAxis(
        Canvas $c,
        int $xOrigin,
        int $yOrigin,
        int $xLen,
        int $yLen,
        ?Style $style = null,
        array $runes = self::LINE_THIN,
    ): void {
        // X axis (horizontal at the bottom).
        self::drawHLine($c, $yOrigin, $xOrigin, $xOrigin + $xLen, $style, $runes['h']);
        // Y axis (vertical on the left).
        self::drawVLine($c, $xOrigin, $yOrigin - $yLen, $yOrigin, $style, $runes['v']);
        // Corner.
        $c->setCell($xOrigin, $yOrigin, $runes['bl'], $style);
    }

    /**
     * Render labels along the X and Y axes drawn by {@see drawXYAxis()}.
     *
     * @param list<string> $xLabels  drawn left-to-right under the X axis
     * @param list<string> $yLabels  drawn top-to-bottom to the left of the Y axis
     */
    public static function drawXYAxisLabel(
        Canvas $c,
        int $xOrigin,
        int $yOrigin,
        int $xLen,
        int $yLen,
        array $xLabels,
        array $yLabels,
        ?Style $style = null,
    ): void {
        // X labels: spaced evenly along the axis below the line. The
        // last label is right-anchored to the rightmost axis column
        // so '23:59'-style strings don't get clipped at the canvas edge.
        $count = count($xLabels);
        if ($count > 0) {
            for ($i = 0; $i < $count; $i++) {
                $col = $xOrigin + (int) round($i * ($xLen - 1) / max(1, $count - 1));
                $label = $xLabels[$i];
                $labelLen = mb_strlen($label, 'UTF-8');
                if ($i === $count - 1 && $count > 1) {
                    // Right-anchor.
                    $col = max($xOrigin, $xOrigin + $xLen - $labelLen + 1);
                }
                self::drawString($c, $col, $yOrigin + 1, $label, $style);
            }
        }
        // Y labels: spaced evenly along the axis to its left, with
        // labels[0] anchored at the TOP of the axis (matches the
        // largest-value-on-top convention readers expect).
        $count = count($yLabels);
        if ($count > 0) {
            $top = $yOrigin - $yLen;
            for ($i = 0; $i < $count; $i++) {
                $row = $top + (int) round($i * ($yLen - 1) / max(1, $count - 1));
                $label = $yLabels[$i];
                $startCol = max(0, $xOrigin - mb_strlen($label, 'UTF-8') - 1);
                self::drawString($c, $startCol, $row, $label, $style);
            }
        }
    }

    /**
     * Place the characters of `$s` starting at (`$x`, `$y`), advancing
     * one column per character. Multi-byte safe.
     */
    public static function drawString(Canvas $c, int $x, int $y, string $s, ?Style $style = null): void
    {
        $i = 0;
        $clusters = function_exists('grapheme_str_split')
            ? (grapheme_str_split($s) ?: mb_str_split($s, 1, 'UTF-8'))
            : mb_str_split($s, 1, 'UTF-8');
        foreach ($clusters as $cluster) {
            $c->setCell($x + $i, $y, $cluster, $style);
            $i++;
        }
    }

    /**
     * Draw a 1-cell-thick line between two arbitrary points using
     * Bresenham's algorithm. The line uses `$rune` for every cell —
     * for connector glyphs (corners + tees), call `drawLinePoints`
     * with a runeset.
     */
    public static function drawLine(
        Canvas $c, int $x0, int $y0, int $x1, int $y1,
        string $rune = '·', ?Style $style = null,
    ): void {
        $dx = abs($x1 - $x0);
        $dy = -abs($y1 - $y0);
        $sx = $x0 < $x1 ? 1 : -1;
        $sy = $y0 < $y1 ? 1 : -1;
        $err = $dx + $dy;
        while (true) {
            $c->setCell($x0, $y0, $rune, $style);
            if ($x0 === $x1 && $y0 === $y1) {
                break;
            }
            $e2 = 2 * $err;
            if ($e2 >= $dy) { $err += $dy; $x0 += $sx; }
            if ($e2 <= $dx) { $err += $dx; $y0 += $sy; }
        }
    }

    /**
     * Draw a sequence of `[x, y]` points connected with thin-line
     * runes — slopes pick the nearest of `─` / `│` / `╱` / `╲`. Used
     * by LineChart for its connector pass.
     *
     * @param list<array{0:int,1:int}> $points
     */
    public static function drawLinePoints(Canvas $c, array $points, ?Style $style = null): void
    {
        $count = count($points);
        if ($count === 0) {
            return;
        }
        // Mark the points themselves.
        foreach ($points as [$x, $y]) {
            $c->setCell($x, $y, '·', $style);
        }
        // Connect consecutive points.
        for ($i = 0; $i < $count - 1; $i++) {
            [$x0, $y0] = $points[$i];
            [$x1, $y1] = $points[$i + 1];
            if ($x0 === $x1 && $y0 === $y1) {
                continue;
            }
            $dx = $x1 - $x0;
            $dy = $y1 - $y0;
            // Pick a connector glyph by slope.
            $rune = match (true) {
                $dy === 0 => '─',
                $dx === 0 => '│',
                ($dx > 0 && $dy < 0) || ($dx < 0 && $dy > 0) => '╱',
                default => '╲',
            };
            self::drawLine($c, $x0, $y0, $x1, $y1, $rune, $style);
        }
    }

    /**
     * Fill a rectangular region with `$rune`. Useful for backgrounds.
     */
    public static function fillRect(
        Canvas $c, int $x0, int $y0, int $x1, int $y1,
        string $rune = ' ', ?Style $style = null,
    ): void {
        if ($x0 > $x1) { [$x0, $x1] = [$x1, $x0]; }
        if ($y0 > $y1) { [$y0, $y1] = [$y1, $y0]; }
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $c->setCell($x, $y, $rune, $style);
            }
        }
    }

    /**
     * Draw a vertical column of `$rune` cells from `$y0` (top) to
     * `$y1` (bottom). Used by BarChart for solid bars.
     */
    public static function drawColumn(Canvas $c, int $x, int $y0, int $y1, string $rune = '█', ?Style $style = null): void
    {
        self::drawVLine($c, $x, $y0, $y1, $style, $rune);
    }

    /**
     * Sample N points along the unit circle and place them on the
     * canvas. Mirrors ntcharts' `getCirclePoints` helper.
     *
     * @return list<array{0:int,1:int}>
     */
    public static function getCirclePoints(int $cx, int $cy, int $radius, int $samples = 32): array
    {
        $samples = max(8, $samples);
        $pts = [];
        for ($i = 0; $i < $samples; $i++) {
            $theta = 2.0 * M_PI * $i / $samples;
            $pts[] = [
                $cx + (int) round($radius * cos($theta)),
                $cy + (int) round($radius * sin($theta)),
            ];
        }
        return $pts;
    }

    /**
     * Sample N points along the unit circle, capped to `$limit` points.
     * Mirrors ntcharts' `getCirclePointsWithLimit`.
     *
     * @return list<array{0:int,1:int}>
     */
    public static function getCirclePointsWithLimit(
        int $cx,
        int $cy,
        int $radius,
        int $limit,
        int $samples = 32,
    ): array {
        $pts = self::getCirclePoints($cx, $cy, $radius, $samples);
        if ($limit > 0 && count($pts) > $limit) {
            $pts = array_slice($pts, 0, $limit);
        }
        return $pts;
    }

    /**
     * Sample points along the line from `($x0, $y0)` to `($x1, $y1)`,
     * capped at `$limit` points. Mirrors ntcharts'
     * `getLinePointsWithLimit`.
     *
     * @return list<array{0:int,1:int}>
     */
    public static function getLinePointsWithLimit(int $x0, int $y0, int $x1, int $y1, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $pts = [];
        $dx = abs($x1 - $x0);
        $dy = -abs($y1 - $y0);
        $sx = $x0 < $x1 ? 1 : -1;
        $sy = $y0 < $y1 ? 1 : -1;
        $err = $dx + $dy;
        while (true) {
            $pts[] = [$x0, $y0];
            if (count($pts) >= $limit) {
                break;
            }
            if ($x0 === $x1 && $y0 === $y1) {
                break;
            }
            $e2 = 2 * $err;
            if ($e2 >= $dy) { $err += $dy; $x0 += $sx; }
            if ($e2 <= $dx) { $err += $dx; $y0 += $sy; }
        }
        return $pts;
    }

    /**
     * Draw a vertical line from bottom (`$yBottom`) up to `$yTop`. Mirrors
     * ntcharts' `DrawVerticalLineUp`.
     */
    public static function drawVerticalLineUp(
        Canvas $c,
        int $x,
        int $yTop,
        int $yBottom,
        ?Style $style = null,
        string $rune = '│',
    ): void {
        self::drawVLine($c, $x, min($yTop, $yBottom), max($yTop, $yBottom), $style, $rune);
    }

    /** Mirror of {@see drawVerticalLineUp()} for the down direction (semantically identical). */
    public static function drawVerticalLineDown(
        Canvas $c,
        int $x,
        int $yTop,
        int $yBottom,
        ?Style $style = null,
        string $rune = '│',
    ): void {
        self::drawVerticalLineUp($c, $x, $yTop, $yBottom, $style, $rune);
    }

    /** Mirror of {@see drawHLine()} with explicit left-to-right direction. */
    public static function drawHorizontalLineLeft(
        Canvas $c,
        int $y,
        int $xLeft,
        int $xRight,
        ?Style $style = null,
        string $rune = '─',
    ): void {
        self::drawHLine($c, $y, min($xLeft, $xRight), max($xLeft, $xRight), $style, $rune);
    }

    /** Mirror of {@see drawHorizontalLineLeft()} for the right direction. */
    public static function drawHorizontalLineRight(
        Canvas $c,
        int $y,
        int $xLeft,
        int $xRight,
        ?Style $style = null,
        string $rune = '─',
    ): void {
        self::drawHorizontalLineLeft($c, $y, $xLeft, $xRight, $style, $rune);
    }

    /**
     * Render a Braille glyph at `($x, $y)` from a 2x4 dot pattern.
     * Each `$dots` entry is a `[colDotX, rowDotY]` pair where colDotX
     * ∈ {0,1} and rowDotY ∈ {0,1,2,3}, matching the Braille Patterns
     * codepoint layout (U+2800).
     *
     * @param list<array{0:int,1:int}> $dots
     */
    public static function drawBrailleRune(
        Canvas $c,
        int $x,
        int $y,
        array $dots,
        ?Style $style = null,
    ): void {
        // U+2800 = 0x2800 = 10240. Each dot bit:
        //   col 0:  row0=0x01  row1=0x02  row2=0x04  row3=0x40
        //   col 1:  row0=0x08  row1=0x10  row2=0x20  row3=0x80
        $bitmap = [
            0 => [0x01, 0x02, 0x04, 0x40],
            1 => [0x08, 0x10, 0x20, 0x80],
        ];
        $code = 0x2800;
        foreach ($dots as [$col, $row]) {
            if ($col < 0 || $col > 1 || $row < 0 || $row > 3) {
                continue;
            }
            $code |= $bitmap[$col][$row];
        }
        $c->setCell($x, $y, mb_chr($code, 'UTF-8'), $style);
    }

    /**
     * Repeated form of {@see drawBrailleRune()} — places a sequence
     * of Braille glyphs left-to-right starting at `($x, $y)`. Each
     * `$patterns[i]` is a list of `[col, row]` dots for the i-th glyph.
     *
     * @param list<list<array{0:int,1:int}>> $patterns
     */
    public static function drawBraillePatterns(
        Canvas $c,
        int $x,
        int $y,
        array $patterns,
        ?Style $style = null,
    ): void {
        foreach ($patterns as $i => $dots) {
            self::drawBrailleRune($c, $x + $i, $y, $dots, $style);
        }
    }

    /**
     * Place `$columns` next to each other left-to-right starting at
     * `($x, $yBottom)`, each column drawn from `$yBottom` up to
     * `$yBottom - $h_i`. Mirrors ntcharts' `DrawColumns`.
     *
     * @param list<int> $heights
     */
    public static function drawColumns(
        Canvas $c,
        int $x,
        int $yBottom,
        array $heights,
        string $rune = '█',
        ?Style $style = null,
    ): void {
        foreach ($heights as $i => $h) {
            if ($h <= 0) {
                continue;
            }
            $top = $yBottom - $h + 1;
            self::drawVLine($c, $x + $i, $top, $yBottom, $style, $rune);
        }
    }

    /**
     * Draw rows of length `$widths` stacked top-down starting at
     * `($xLeft, $y)`. Mirrors ntcharts' `DrawRows`.
     *
     * @param list<int> $widths
     */
    public static function drawRows(
        Canvas $c,
        int $xLeft,
        int $y,
        array $widths,
        string $rune = '█',
        ?Style $style = null,
    ): void {
        foreach ($widths as $i => $w) {
            if ($w <= 0) {
                continue;
            }
            self::drawHLine($c, $y + $i, $xLeft, $xLeft + $w - 1, $style, $rune);
        }
    }

    /**
     * Draw a single OHLC candle stick at column `$x`. `$open` /
     * `$close` are filled with `$body`; the high-low wick is drawn
     * with `$wick`. Coordinates are canvas rows (top = 0).
     */
    public static function drawCandlestick(
        Canvas $c,
        int $x,
        int $high,
        int $open,
        int $close,
        int $low,
        ?Style $style = null,
        string $body = '│',
        string $wick = '│',
    ): void {
        // Wick: high → low.
        self::drawVLine($c, $x, min($high, $low), max($high, $low), $style, $wick);
        // Body: open ↔ close (overlay).
        self::drawVLine($c, $x, min($open, $close), max($open, $close), $style, $body);
    }

    private function __construct() {}
}
