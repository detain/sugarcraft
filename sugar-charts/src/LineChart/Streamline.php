<?php

declare(strict_types=1);

namespace SugarCraft\Charts\LineChart;

use SugarCraft\Charts\Support\Finite;

/**
 * Streamline LineChart variant. Mirrors ntcharts'
 * `linechart/streamline` — a sliding window over an unbounded data
 * stream. New samples are appended on the right; older samples scroll
 * off the left as the window fills.
 *
 * Construct once, then call `push($value)` per sample. Samples are held
 * in a fixed-capacity ring buffer sized to `$width`, so `push()` writes
 * a single slot (advancing a head pointer and wrapping) instead of
 * re-slicing a growing array on every frame. `view()` reuses one
 * pre-configured {@see LineChart} template — only the data is swapped in
 * per render, rather than rebuilding the chart (and its range / glyph
 * setters) from scratch each frame.
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
    /**
     * Samples in insertion order (oldest first), capped to `$width`.
     * Materialised once per instance from the ring buffer so callers and
     * {@see view()} see a stable ordered window.
     *
     * @var list<int|float>
     */
    public readonly array $window;

    /**
     * @param list<int|float> $buffer ring storage; physically ordered only
     *                                until the window wraps
     * @param int             $head   index the next sample overwrites
     * @param int             $size   number of live samples (<= width)
     */
    private function __construct(
        private readonly array $buffer,
        private readonly int $head,
        private readonly int $size,
        public readonly int $width,
        public readonly int $height,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $point,
        private readonly LineChart $chart,
    ) {
        $this->window = self::order($buffer, $head, $size, $width);
    }

    public static function new(int $width = 40, int $height = 8): self
    {
        return new self([], 0, 0, $width, $height, null, null, '*', self::buildChart($width, $height, null, null, '*'));
    }

    /** Append a sample on the right, dropping the oldest if the window is full. */
    public function push(int|float $value): self
    {
        Finite::assert($value);

        // Degenerate width: preserve the historical unbounded-append
        // behaviour (the modulo below would divide by zero at width 0).
        if ($this->width <= 0) {
            $buffer = $this->buffer;
            $buffer[] = $value;

            return $this->withBuffer($buffer, count($buffer), count($buffer));
        }

        $buffer = $this->buffer;
        $buffer[$this->head] = $value;               // append while filling, overwrite oldest once full
        $head = ($this->head + 1) % $this->width;
        $size = min($this->size + 1, $this->width);

        return $this->withBuffer($buffer, $head, $size);
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

    /** Reset the sliding window to empty while preserving sizing / range / glyph. */
    public function clear(): self
    {
        return $this->withBuffer([], 0, 0);
    }

    /** True when the window holds zero samples. */
    public function isEmpty(): bool
    {
        return $this->size === 0;
    }

    public function count(): int
    {
        return $this->size;
    }

    public function withSize(int $w, int $h): self
    {
        return self::fromOrdered($this->window, $w, $h, $this->min, $this->max, $this->point);
    }

    public function withMin(?float $m): self
    {
        return $this->withRange($m, $this->max, $this->point);
    }

    public function withMax(?float $m): self
    {
        return $this->withRange($this->min, $m, $this->point);
    }

    public function withYRange(?float $min, ?float $max): self
    {
        return $this->withRange($min, $max, $this->point);
    }

    public function withPoint(string $r): self
    {
        return $this->withRange($this->min, $this->max, $r);
    }

    public function view(): string
    {
        // Reuse the configured chart; only the data changes per frame.
        return $this->chart->withData($this->window)->view();
    }

    public function __toString(): string
    {
        return $this->view();
    }

    /**
     * Copy with a new ring-buffer state, threading the configured chart
     * template through unchanged (data-only mutation).
     *
     * @param list<int|float> $buffer
     */
    private function withBuffer(array $buffer, int $head, int $size): self
    {
        return new self($buffer, $head, $size, $this->width, $this->height, $this->min, $this->max, $this->point, $this->chart);
    }

    /**
     * Copy with a new range / glyph, rebuilding the chart template (the
     * only mutations that invalidate it).
     */
    private function withRange(?float $min, ?float $max, string $point): self
    {
        return new self(
            $this->buffer,
            $this->head,
            $this->size,
            $this->width,
            $this->height,
            $min,
            $max,
            $point,
            self::buildChart($this->width, $this->height, $min, $max, $point),
        );
    }

    /**
     * Load an ordered sample list into a fresh ring buffer capped to
     * `$width`, rebuilding the chart template for the new sizing.
     *
     * @param list<int|float> $ordered
     */
    private static function fromOrdered(array $ordered, int $width, int $height, ?float $min, ?float $max, string $point): self
    {
        $ordered = array_values($ordered);
        if ($width > 0 && count($ordered) > $width) {
            $ordered = array_slice($ordered, -$width);
        }
        $size = count($ordered);
        $head = $width > 0 ? ($size % $width) : $size;

        return new self($ordered, $head, $size, $width, $height, $min, $max, $point, self::buildChart($width, $height, $min, $max, $point));
    }

    /**
     * Rehydrate the insertion-ordered window (oldest first) from the ring
     * buffer's physical layout.
     *
     * @param list<int|float> $buffer
     *
     * @return list<int|float>
     */
    private static function order(array $buffer, int $head, int $size, int $width): array
    {
        if ($width <= 0) {
            return array_values($buffer);
        }
        if ($size < $width) {
            // Still filling: physical order is insertion order.
            return array_slice($buffer, 0, $size);
        }
        // Wrapped: oldest sample sits at $head.
        return array_merge(array_slice($buffer, $head), array_slice($buffer, 0, $head));
    }

    /** Build the data-less chart template reused by {@see view()}. */
    private static function buildChart(int $width, int $height, ?float $min, ?float $max, string $point): LineChart
    {
        return LineChart::new([], $width, $height)
            ->withMin($min)
            ->withMax($max)
            ->withPoint($point);
    }
}
