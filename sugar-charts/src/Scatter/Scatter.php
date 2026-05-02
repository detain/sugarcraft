<?php

declare(strict_types=1);

namespace CandyCore\Charts\Scatter;

use CandyCore\Charts\Canvas\Canvas;

/**
 * Scatter plot — like {@see \CandyCore\Charts\LineChart\LineChart} but
 * each point is plotted independently with no connecting strokes. The
 * X and Y ranges are auto-detected from the data unless pinned via
 * {@see withXRange()} / {@see withYRange()}.
 *
 * ```php
 * echo Scatter::new([[1, 4], [2, 7], [3, 5], [4, 9]], width: 30, height: 8)->view();
 * ```
 */
final class Scatter
{
    /** @param list<array{0:int|float,1:int|float}> $points */
    private function __construct(
        public readonly array $points,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $minX,
        public readonly ?float $maxX,
        public readonly ?float $minY,
        public readonly ?float $maxY,
        public readonly string $rune,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('scatter width/height must be >= 0');
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
        return new self(array_values($points), $this->width, $this->height,
            $this->minX, $this->maxX, $this->minY, $this->maxY, $this->rune);
    }

    public function withSize(int $w, int $h): self
    {
        if ($w < 0 || $h < 0) {
            throw new \InvalidArgumentException('scatter width/height must be >= 0');
        }
        return new self($this->points, $w, $h, $this->minX, $this->maxX, $this->minY, $this->maxY, $this->rune);
    }

    public function withXRange(?float $min, ?float $max): self
    {
        return new self($this->points, $this->width, $this->height, $min, $max, $this->minY, $this->maxY, $this->rune);
    }

    public function withYRange(?float $min, ?float $max): self
    {
        return new self($this->points, $this->width, $this->height, $this->minX, $this->maxX, $min, $max, $this->rune);
    }

    public function withRune(string $rune): self
    {
        return new self($this->points, $this->width, $this->height, $this->minX, $this->maxX, $this->minY, $this->maxY, $rune);
    }

    public function view(): string
    {
        if ($this->points === [] || $this->width === 0 || $this->height === 0) {
            return (new Canvas($this->width, $this->height))->view();
        }

        $minX = $this->minX;
        $maxX = $this->maxX;
        $minY = $this->minY;
        $maxY = $this->maxY;
        if ($minX === null || $maxX === null || $minY === null || $maxY === null) {
            foreach ($this->points as $p) {
                $x = (float) $p[0];
                $y = (float) $p[1];
                $minX = $minX === null ? $x : min($minX, $x);
                $maxX = $maxX === null ? $x : max($maxX, $x);
                $minY = $minY === null ? $y : min($minY, $y);
                $maxY = $maxY === null ? $y : max($maxY, $y);
            }
        }
        if ($maxX == $minX) { $maxX = $minX + 1.0; }
        if ($maxY == $minY) { $maxY = $minY + 1.0; }

        $canvas = new Canvas($this->width, $this->height);
        foreach ($this->points as $p) {
            $x = (float) $p[0];
            $y = (float) $p[1];
            $col = (int) round((($x - $minX) / ($maxX - $minX)) * ($this->width - 1));
            // Y is inverted so larger values sit at the top.
            $row = (int) round((1.0 - (($y - $minY) / ($maxY - $minY))) * ($this->height - 1));
            $canvas->setCell($col, $row, $this->rune);
        }
        return $canvas->view();
    }

    public function __toString(): string
    {
        return $this->view();
    }
}
