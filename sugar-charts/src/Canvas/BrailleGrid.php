<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Canvas;

use SugarCraft\Charts\Lang;
use SugarCraft\Style\Style;

/**
 * Sub-cell Braille buffer.
 *
 * One terminal cell maps to a 2-column × 4-row grid of Braille dots
 * (so a `$cols × $rows` cell area holds `$cols*2 × $rows*4` dots).
 * Use {@see set()} / {@see unset()} / {@see toggle()} to build a
 * pattern, then {@see paint()} to copy the result onto a {@see Canvas}.
 *
 * Mirrors the surface of ntcharts' `canvas.BrailleGrid` value: a
 * write-once-then-paint scratch buffer that gives line / scatter
 * primitives sub-cell precision without forcing every caller to
 * compose Braille runes by hand.
 */
final class BrailleGrid
{
    /** @var array<int, array<int, int>> [cellX][cellY] => bitmask */
    private array $cells = [];

    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
    ) {
        if ($cols <= 0 || $rows <= 0) {
            throw new \InvalidArgumentException(Lang::t('braillegrid.dim_positive'));
        }
    }

    /** @return array{0:int,1:int} dot dimensions: [width, height] */
    public function dotSize(): array
    {
        return [$this->cols * 2, $this->rows * 4];
    }

    /** Light a single dot at sub-cell position `(dx, dy)`. */
    public function set(int $dx, int $dy): self
    {
        if (!$this->inBounds($dx, $dy)) {
            return $this;
        }
        $cellX = intdiv($dx, 2);
        $cellY = intdiv($dy, 4);
        $col   = $dx % 2;
        $row   = $dy % 4;
        $this->cells[$cellX][$cellY] = ($this->cells[$cellX][$cellY] ?? 0) | self::bit($col, $row);
        return $this;
    }

    /** Clear a single dot. */
    public function unset(int $dx, int $dy): self
    {
        if (!$this->inBounds($dx, $dy)) {
            return $this;
        }
        $cellX = intdiv($dx, 2);
        $cellY = intdiv($dy, 4);
        $col   = $dx % 2;
        $row   = $dy % 4;
        $cur = $this->cells[$cellX][$cellY] ?? 0;
        $this->cells[$cellX][$cellY] = $cur & ~self::bit($col, $row);
        return $this;
    }

    /** Flip a single dot. */
    public function toggle(int $dx, int $dy): self
    {
        if (!$this->inBounds($dx, $dy)) {
            return $this;
        }
        $cellX = intdiv($dx, 2);
        $cellY = intdiv($dy, 4);
        $col   = $dx % 2;
        $row   = $dy % 4;
        $cur = $this->cells[$cellX][$cellY] ?? 0;
        $this->cells[$cellX][$cellY] = $cur ^ self::bit($col, $row);
        return $this;
    }

    /** True when the dot at `(dx, dy)` is currently lit. */
    public function isSet(int $dx, int $dy): bool
    {
        if (!$this->inBounds($dx, $dy)) {
            return false;
        }
        $cellX = intdiv($dx, 2);
        $cellY = intdiv($dy, 4);
        $col   = $dx % 2;
        $row   = $dy % 4;
        return (($this->cells[$cellX][$cellY] ?? 0) & self::bit($col, $row)) !== 0;
    }

    /**
     * Returns the Braille rune for a single cell (`$cellX`, `$cellY`)
     * or U+2800 (blank) when no dots are lit. Useful when you want to
     * compose your own renderer instead of calling {@see paint()}.
     */
    public function rune(int $cellX, int $cellY): string
    {
        $code = 0x2800 | ($this->cells[$cellX][$cellY] ?? 0);
        return mb_chr($code, 'UTF-8');
    }

    /**
     * Paint the buffer onto `$canvas` at offset `($x0, $y0)`. Cells
     * with no lit dots are skipped (the existing canvas content is
     * preserved). Cells whose lit dots fall outside the canvas are
     * silently ignored.
     */
    public function paint(Canvas $canvas, int $x0 = 0, int $y0 = 0, ?Style $style = null): void
    {
        for ($cy = 0; $cy < $this->rows; $cy++) {
            for ($cx = 0; $cx < $this->cols; $cx++) {
                $bits = $this->cells[$cx][$cy] ?? 0;
                if ($bits === 0) {
                    continue;
                }
                $canvas->setCell($x0 + $cx, $y0 + $cy, mb_chr(0x2800 | $bits, 'UTF-8'), $style);
            }
        }
    }

    /** Reset every dot back to zero. */
    public function clear(): self
    {
        $this->cells = [];
        return $this;
    }

    /** Bit position for a given column (0 or 1) and row (0..3). */
    private static function bit(int $col, int $row): int
    {
        // U+2800 layout:
        //   col 0:  row0=0x01  row1=0x02  row2=0x04  row3=0x40
        //   col 1:  row0=0x08  row1=0x10  row2=0x20  row3=0x80
        return $col === 0
            ? ([0x01, 0x02, 0x04, 0x40][$row])
            : ([0x08, 0x10, 0x20, 0x80][$row]);
    }

    private function inBounds(int $dx, int $dy): bool
    {
        return $dx >= 0 && $dx < $this->cols * 2
            && $dy >= 0 && $dy < $this->rows * 4;
    }
}
