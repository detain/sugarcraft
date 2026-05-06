<?php

declare(strict_types=1);

namespace CandyCore\Charts\Canvas;

use CandyCore\Sprinkles\Style;

/**
 * Fixed-size 2D grid of {@see Cell}s. Charts draw onto the canvas and
 * call {@see view()} to produce a ready-to-print frame. Coordinates are
 * 0-based: $x is column, $y is row, with (0, 0) at the top-left.
 *
 * The canvas is mutable in place — cheaper for hot rendering paths than
 * building a new grid per draw call.
 */
final class Canvas
{
    /** @var array<int, array<int, Cell>> [row][col] */
    private array $cells;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('canvas width/height must be >= 0');
        }
        $this->clear();
    }

    public function setCell(int $x, int $y, string $rune, ?Style $style = null): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $this->cells[$y][$x] = new Cell($rune, $style);
    }

    public function getCell(int $x, int $y): Cell
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return new Cell();
        }
        return $this->cells[$y][$x] ?? new Cell();
    }

    /**
     * Replace a cell's style without changing its rune. Mirrors
     * ntcharts' `SetCellStyle`. Out-of-range coordinates are no-ops.
     */
    public function setCellStyle(int $x, int $y, ?Style $style): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }
        $existing = $this->cells[$y][$x] ?? new Cell();
        $this->cells[$y][$x] = new Cell($existing->rune, $style);
    }

    /**
     * Read a cell's style (or null when unset). Mirrors ntcharts'
     * `GetCellStyle`.
     */
    public function getCellStyle(int $x, int $y): ?Style
    {
        return $this->getCell($x, $y)->style;
    }

    /**
     * Place a horizontal run of `$cells` cells starting at `($x, $y)`,
     * each carrying `$rune` (and optional shared `$style`). Mirrors
     * ntcharts' `SetRunes` when used per-row. Cells that fall outside
     * the canvas bounds are silently dropped.
     *
     * @param iterable<string> $runes
     */
    public function setRunes(int $x, int $y, iterable $runes, ?Style $style = null): void
    {
        $i = 0;
        foreach ($runes as $r) {
            $this->setCell($x + $i, $y, $r, $style);
            $i++;
        }
    }

    /**
     * Place a string of glyphs along row `$y` starting at column `$x`.
     * Multibyte-safe via `mb_str_split`. Mirrors ntcharts' `SetString`.
     */
    public function setString(int $x, int $y, string $s, ?Style $style = null): void
    {
        $clusters = function_exists('grapheme_str_split')
            ? (grapheme_str_split($s) ?: mb_str_split($s, 1, 'UTF-8'))
            : mb_str_split($s, 1, 'UTF-8');
        $this->setRunes($x, $y, $clusters, $style);
    }

    /**
     * Place pre-styled lines at `($x, $y)` — each line goes on its own
     * row. Mirrors ntcharts' `SetLines`. Each line is split through
     * {@see setString()} so the same multibyte handling applies.
     *
     * @param iterable<string> $lines
     */
    public function setLines(int $x, int $y, iterable $lines, ?Style $style = null): void
    {
        $row = 0;
        foreach ($lines as $line) {
            $this->setString($x, $y + $row, $line, $style);
            $row++;
        }
    }

    /**
     * Fill every cell in the rectangle `($x0..$x1, $y0..$y1)` (inclusive)
     * with `$rune` (and optional `$style`). Out-of-range coordinates
     * are clamped. Mirrors ntcharts' `Fill`.
     */
    public function fill(int $x0, int $y0, int $x1, int $y1, string $rune, ?Style $style = null): void
    {
        if ($x0 > $x1) { [$x0, $x1] = [$x1, $x0]; }
        if ($y0 > $y1) { [$y0, $y1] = [$y1, $y0]; }
        $x0 = max(0, $x0);
        $y0 = max(0, $y0);
        $x1 = min($this->width  - 1, $x1);
        $y1 = min($this->height - 1, $y1);
        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $this->cells[$y][$x] = new Cell($rune, $style);
            }
        }
    }

    /**
     * Fill an entire row with `$rune`. Mirrors ntcharts' `FillLine`.
     * Out-of-range rows are silently ignored.
     */
    public function fillLine(int $y, string $rune, ?Style $style = null): void
    {
        if ($y < 0 || $y >= $this->height) {
            return;
        }
        $this->fill(0, $y, $this->width - 1, $y, $rune, $style);
    }

    /**
     * Shift every cell down by `$n` rows. Cells that fall off the bottom
     * are dropped; new rows at the top fill with empty cells. Mirrors
     * ntcharts' `ShiftDown`.
     */
    public function shiftDown(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = $this->height - 1; $y >= 0; $y--) {
            $this->cells[$y] = $y - $n >= 0 ? ($this->cells[$y - $n] ?? []) : [];
        }
    }

    /**
     * Shift every cell up by `$n` rows. Mirrors ntcharts' `ShiftUp`.
     * The bottom rows fill with empty cells.
     */
    public function shiftUp(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = 0; $y < $this->height; $y++) {
            $this->cells[$y] = $y + $n < $this->height ? ($this->cells[$y + $n] ?? []) : [];
        }
    }

    /**
     * Shift every row left by `$n` columns. Cells that fall off the
     * left are dropped; the right edge fills with empty cells.
     * Mirrors ntcharts' `ShiftLeft`.
     */
    public function shiftLeft(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = 0; $y < $this->height; $y++) {
            $newRow = [];
            foreach ($this->cells[$y] ?? [] as $x => $cell) {
                $nx = $x - $n;
                if ($nx >= 0 && $nx < $this->width) {
                    $newRow[$nx] = $cell;
                }
            }
            $this->cells[$y] = $newRow;
        }
    }

    /**
     * Shift every row right by `$n` columns. Cells that fall off the
     * right are dropped; the left edge fills with empty cells.
     * Mirrors ntcharts' `ShiftRight`.
     */
    public function shiftRight(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }
        for ($y = 0; $y < $this->height; $y++) {
            $newRow = [];
            foreach ($this->cells[$y] ?? [] as $x => $cell) {
                $nx = $x + $n;
                if ($nx >= 0 && $nx < $this->width) {
                    $newRow[$nx] = $cell;
                }
            }
            $this->cells[$y] = $newRow;
        }
    }

    public function clear(): void
    {
        $this->cells = [];
        for ($y = 0; $y < $this->height; $y++) {
            $this->cells[$y] = [];
        }
    }

    /** Render the canvas as a string. Empty cells render as spaces. */
    public function view(): string
    {
        $rows = [];
        for ($y = 0; $y < $this->height; $y++) {
            $row = '';
            for ($x = 0; $x < $this->width; $x++) {
                $cell = $this->cells[$y][$x] ?? null;
                if ($cell === null) {
                    $row .= ' ';
                    continue;
                }
                $rune = $cell->rune === '' ? ' ' : $cell->rune;
                $row .= $cell->style !== null
                    ? $cell->style->render($rune)
                    : $rune;
            }
            $rows[] = rtrim($row);
        }
        return implode("\n", $rows);
    }
}
