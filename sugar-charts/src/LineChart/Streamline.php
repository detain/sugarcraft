<?php

declare(strict_types=1);

namespace CandyCore\Charts\LineChart;

/**
 * Streamline LineChart variant. Mirrors ntcharts'
 * `linechart/streamline` — a sliding window over an unbounded data
 * stream. New samples are appended on the right; older samples scroll
 * off the left as the window fills.
 *
 * Construct once, then call `push($value)` per sample. The stored
 * window is capped at `$width` so memory doesn't grow unbounded
 * even after millions of pushes.
 *
 * ```php
 * $chart = Streamline::new(40, 8);
 * foreach ($metrics as $sample) {
 *     $chart = $chart->push($sample);
 *     echo $chart->view();
 * }
 * ```
 */
final class Streamline
{
    /** @param list<int|float> $window */
    private function __construct(
        public readonly array $window,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $point,
    ) {}

    public static function new(int $width = 40, int $height = 8): self
    {
        return new self([], $width, $height, null, null, '*');
    }

    /** Append a sample on the right, dropping the oldest if the window is full. */
    public function push(int|float $value): self
    {
        $w = $this->window;
        $w[] = $value;
        if (count($w) > $this->width) {
            $w = array_slice($w, -$this->width);
        }
        return new self($w, $this->width, $this->height, $this->min, $this->max, $this->point);
    }

    /** Append several samples in one call. @param iterable<int|float> $values */
    public function pushAll(iterable $values): self
    {
        $next = $this;
        foreach ($values as $v) {
            $next = $next->push($v);
        }
        return $next;
    }

    public function withSize(int $w, int $h): self
    {
        $window = $this->window;
        if (count($window) > $w) {
            $window = array_slice($window, -$w);
        }
        return new self($window, $w, $h, $this->min, $this->max, $this->point);
    }

    public function withMin(?float $m): self    { return new self($this->window, $this->width, $this->height, $m, $this->max, $this->point); }
    public function withMax(?float $m): self    { return new self($this->window, $this->width, $this->height, $this->min, $m, $this->point); }
    public function withPoint(string $r): self  { return new self($this->window, $this->width, $this->height, $this->min, $this->max, $r); }

    public function view(): string
    {
        return LineChart::new($this->window, $this->width, $this->height)
            ->withMin($this->min)
            ->withMax($this->max)
            ->withPoint($this->point)
            ->view();
    }

    public function __toString(): string { return $this->view(); }
}
