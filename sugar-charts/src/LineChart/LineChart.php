<?php

declare(strict_types=1);

namespace CandyCore\Charts\LineChart;

use CandyCore\Charts\Canvas\Canvas;

/**
 * Single-series line plot drawn onto a {@see Canvas}. Each data point
 * becomes one column; the series is scaled into the canvas height and
 * a `*` (configurable) is plotted at the rounded row. Adjacent points
 * are connected with `|`, `/`, or `\` strokes when their rows differ,
 * giving an at-a-glance trend without requiring sub-cell rasterisation.
 *
 * ```php
 * echo LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6)->view();
 * ```
 */
final class LineChart
{
    /** @param list<int|float> $data */
    private function __construct(
        public readonly array $data,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $point,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('line chart width/height must be >= 0');
        }
    }

    /** @param list<int|float> $data */
    public static function new(array $data = [], int $width = 40, int $height = 8): self
    {
        return new self(array_values($data), $width, $height, null, null, '*');
    }

    /** @param list<int|float> $data */
    public function withData(array $data): self     { return new self(array_values($data), $this->width, $this->height, $this->min, $this->max, $this->point); }
    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('line chart width/height must be >= 0');
        }
        return new self($this->data, $w, $h, $this->min, $this->max, $this->point);
    }
    public function withMin(?float $m): self        { return new self($this->data, $this->width, $this->height, $m, $this->max, $this->point); }
    public function withMax(?float $m): self        { return new self($this->data, $this->width, $this->height, $this->min, $m, $this->point); }
    public function withPoint(string $rune): self   { return new self($this->data, $this->width, $this->height, $this->min, $this->max, $rune); }

    public function view(): string
    {
        if ($this->width === 0 || $this->height === 0 || $this->data === []) {
            return (new Canvas($this->width, $this->height))->view();
        }

        $canvas = new Canvas($this->width, $this->height);

        // Map the (possibly oversized) series into [0, width-1] columns.
        $count   = count($this->data);
        $points  = $count > $this->width ? array_slice($this->data, -$this->width) : $this->data;
        $count   = count($points);

        $min = $this->min ?? min($points);
        $max = $this->max ?? max($points);
        if ($max == $min) {
            $max = $min + 1.0;
        }

        // Compute one (col, row) per data point.
        $coords = [];
        foreach ($points as $i => $v) {
            $col = $count <= 1
                ? 0
                : (int) round($i * ($this->width - 1) / ($count - 1));
            $norm = ((float) $v - $min) / ($max - $min);
            $norm = max(0.0, min(1.0, $norm));
            // Higher value = smaller row index (top-aligned y).
            $row = (int) round((1.0 - $norm) * ($this->height - 1));
            $coords[] = [$col, $row];
        }

        // Draw points + connecting strokes.
        for ($i = 0; $i < $count; $i++) {
            [$x, $y] = $coords[$i];
            $canvas->setCell($x, $y, $this->point);

            if ($i + 1 < $count) {
                [$x2, $y2] = $coords[$i + 1];
                self::drawConnector($canvas, $x, $y, $x2, $y2, $this->point);
            }
        }
        return $canvas->view();
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /** Draw a coarse connector between two points using line-art glyphs. */
    private static function drawConnector(Canvas $c, int $x1, int $y1, int $x2, int $y2, string $point): void
    {
        if ($x2 <= $x1) {
            return;
        }
        // Vertical column between two adjacent samples (same x): step
        // through the rows.
        if ($x2 === $x1) {
            $step = $y2 > $y1 ? 1 : -1;
            for ($y = $y1 + $step; $y !== $y2; $y += $step) {
                $c->setCell($x1, $y, '|');
            }
            return;
        }
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        if ($dy === 0) {
            for ($x = $x1 + 1; $x < $x2; $x++) {
                $c->setCell($x, $y1, '-');
            }
            return;
        }
        // Sample one row per intermediate column.
        for ($x = $x1 + 1; $x < $x2; $x++) {
            $t   = ($x - $x1) / $dx;
            $row = (int) round($y1 + $t * $dy);
            $rune = $dy > 0 ? '\\' : '/';
            $c->setCell($x, $row, $rune);
        }
    }
}
