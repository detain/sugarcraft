<?php

declare(strict_types=1);

namespace CandyCore\Charts\Sparkline;

/**
 * Compact single-row chart drawn with the eight Unicode block-bar
 * glyphs `▁▂▃▄▅▆▇█`. Each data point becomes one cell whose height is
 * proportional to the value within `[min, max]`.
 *
 * ```php
 * echo Sparkline::new([1, 4, 2, 7, 5])->view(); // ▁▄▂█▅
 * ```
 *
 * If the configured {@see $width} exceeds `count($data)` the line is
 * left-padded with the empty glyph; if it's smaller, only the *last*
 * `width` points are shown — a natural fit for live time-series feeds.
 */
final class Sparkline
{
    /** Index 0 = empty, 1..8 = ▁..█. */
    private const GLYPHS = [' ', '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * @param list<int|float> $data
     */
    private function __construct(
        public readonly array $data,
        public readonly int $width,
        public readonly ?float $min,
        public readonly ?float $max,
    ) {
        if ($width < 0) {
            throw new \InvalidArgumentException('sparkline width must be >= 0');
        }
    }

    /**
     * Construct a sparkline. `$width = -1` (the default) auto-fits the
     * width to the number of data points; pass an explicit `0` to render
     * nothing.
     *
     * @param list<int|float> $data
     */
    public static function new(array $data = [], int $width = -1): self
    {
        if ($width < 0) {
            $width = count($data);
        }
        return new self(array_values($data), $width, null, null);
    }

    /** @param list<int|float> $data */
    public function withData(array $data): self
    {
        return new self(array_values($data), $this->width, $this->min, $this->max);
    }

    public function withWidth(int $w): self
    {
        if ($w < 0) {
            throw new \InvalidArgumentException('sparkline width must be >= 0');
        }
        return new self($this->data, $w, $this->min, $this->max);
    }

    public function withMin(?float $m): self { return new self($this->data, $this->width, $m, $this->max); }
    public function withMax(?float $m): self { return new self($this->data, $this->width, $this->min, $m); }

    public function view(): string
    {
        $w = $this->width;
        if ($w === 0) {
            return '';
        }
        $points = $this->data;
        // Slide window: keep the last $w points.
        if (count($points) > $w) {
            $points = array_slice($points, -$w);
        }
        if ($points === []) {
            return str_repeat(self::GLYPHS[0], $w);
        }

        $min = $this->min ?? min($points);
        $max = $this->max ?? max($points);
        $range = $max - $min;

        $out  = '';
        $missing = $w - count($points);
        if ($missing > 0) {
            $out .= str_repeat(self::GLYPHS[0], $missing);
        }
        foreach ($points as $v) {
            $out .= self::glyph((float) $v, (float) $min, $range);
        }
        return $out;
    }

    public function __toString(): string
    {
        return $this->view();
    }

    private static function glyph(float $v, float $min, float $range): string
    {
        if ($range <= 0.0) {
            // All points equal — render a steady mid-bar (▄) so the line
            // is visible without implying movement.
            return self::GLYPHS[4];
        }
        $norm = ($v - $min) / $range;
        $norm = max(0.0, min(1.0, $norm));
        // 8 levels above the empty cell: indices 1..8.
        $idx = (int) round($norm * 8.0);
        if ($idx < 1 && $v > $min) {
            $idx = 1;
        }
        return self::GLYPHS[$idx];
    }
}
