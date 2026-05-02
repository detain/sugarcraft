<?php

declare(strict_types=1);

namespace CandyCore\Charts\LineChart;

use CandyCore\Charts\Canvas\Canvas;

/**
 * Waveline (XY-point) LineChart variant. Mirrors ntcharts'
 * `linechart/waveline` — accepts arbitrary `[x, y]` points (not just
 * a 1D series), maps them onto the configured X/Y range, and draws
 * connecting strokes between consecutive points.
 *
 * Useful for plotting parametric curves (Lissajous figures, phase
 * portraits, sampled audio) where each sample carries an explicit X
 * coordinate rather than an index.
 *
 * ```php
 * $points = [];
 * for ($t = 0; $t < 100; $t++) {
 *     $points[] = [$t * 0.1, sin($t * 0.1) * 5];
 * }
 * echo Waveline::new($points, 40, 8)->view();
 * ```
 */
final class Waveline
{
    /** @param list<array{0:int|float,1:int|float}> $points */
    private function __construct(
        public readonly array $points,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $xMin,
        public readonly ?float $xMax,
        public readonly ?float $yMin,
        public readonly ?float $yMax,
        public readonly string $point,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('waveline width/height must be >= 0');
        }
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public static function new(array $points = [], int $width = 40, int $height = 8): self
    {
        return new self(array_values($points), $width, $height, null, null, null, null, '*');
    }

    /** @param list<array{0:int|float,1:int|float}> $points */
    public function withPoints(array $points): self
    {
        return new self(array_values($points), $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    public function push(int|float $x, int|float $y): self
    {
        return new self([...$this->points, [$x, $y]], $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('waveline width/height must be >= 0');
        }
        return new self($this->points, $w, $h, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $this->point);
    }

    public function withXRange(?float $min, ?float $max): self
    {
        return new self($this->points, $this->width, $this->height, $min, $max, $this->yMin, $this->yMax, $this->point);
    }

    public function withYRange(?float $min, ?float $max): self
    {
        return new self($this->points, $this->width, $this->height, $this->xMin, $this->xMax, $min, $max, $this->point);
    }

    public function withPoint(string $r): self
    {
        return new self($this->points, $this->width, $this->height, $this->xMin, $this->xMax, $this->yMin, $this->yMax, $r);
    }

    public function view(): string
    {
        if ($this->width === 0 || $this->height === 0 || $this->points === []) {
            return (new Canvas($this->width, $this->height))->view();
        }
        $xs = array_map(static fn(array $p): float => (float) $p[0], $this->points);
        $ys = array_map(static fn(array $p): float => (float) $p[1], $this->points);

        $xMin = $this->xMin ?? min($xs);
        $xMax = $this->xMax ?? max($xs);
        $yMin = $this->yMin ?? min($ys);
        $yMax = $this->yMax ?? max($ys);
        if ($xMax == $xMin) { $xMax = $xMin + 1.0; }
        if ($yMax == $yMin) { $yMax = $yMin + 1.0; }

        $canvas = new Canvas($this->width, $this->height);

        $project = function (array $p) use ($xMin, $xMax, $yMin, $yMax): array {
            [$x, $y] = $p;
            $tx = ((float) $x - $xMin) / ($xMax - $xMin);
            $ty = ((float) $y - $yMin) / ($yMax - $yMin);
            $tx = max(0.0, min(1.0, $tx));
            $ty = max(0.0, min(1.0, $ty));
            return [
                (int) round($tx * ($this->width  - 1)),
                (int) round((1.0 - $ty) * ($this->height - 1)),
            ];
        };

        $coords = array_map($project, $this->points);
        $count  = count($coords);
        for ($i = 0; $i < $count; $i++) {
            [$x, $y] = $coords[$i];
            $canvas->setCell($x, $y, $this->point);
            if ($i + 1 < $count) {
                [$x2, $y2] = $coords[$i + 1];
                self::drawConnector($canvas, $x, $y, $x2, $y2);
            }
        }
        return $canvas->view();
    }

    public function __toString(): string { return $this->view(); }

    /** Bresenham-ish straight-line connector — picks slope-aware glyphs. */
    private static function drawConnector(Canvas $c, int $x1, int $y1, int $x2, int $y2): void
    {
        $dx = abs($x2 - $x1);
        $dy = -abs($y2 - $y1);
        $sx = $x1 < $x2 ? 1 : -1;
        $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx + $dy;
        $rune = match (true) {
            $y1 === $y2 => '─',
            $x1 === $x2 => '│',
            ($x2 > $x1 && $y2 < $y1) || ($x2 < $x1 && $y2 > $y1) => '╱',
            default => '╲',
        };
        while (true) {
            $c->setCell($x1, $y1, $rune);
            if ($x1 === $x2 && $y1 === $y2) {
                break;
            }
            $e2 = 2 * $err;
            if ($e2 >= $dy) { $err += $dy; $x1 += $sx; }
            if ($e2 <= $dx) { $err += $dx; $y1 += $sy; }
        }
    }
}
