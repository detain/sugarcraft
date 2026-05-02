<?php

declare(strict_types=1);

namespace CandyCore\Charts\LineChart;

/**
 * Time-series LineChart variant. Wraps {@see LineChart} but accepts
 * `[\DateTimeImmutable, value]` tuples and renders X-axis labels
 * formatted from the timestamps. Useful for live metrics dashboards.
 *
 * Mirrors ntcharts' `linechart/timeseries`.
 */
final class TimeSeries
{
    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    private function __construct(
        public readonly array $points,
        public readonly int $width,
        public readonly int $height,
        public readonly string $timeFormat,
        public readonly int $xLabelCount,
    ) {}

    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    public static function new(array $points = [], int $width = 40, int $height = 8): self
    {
        return new self(array_values($points), $width, $height, 'H:i', 5);
    }

    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    public function withPoints(array $points): self
    {
        return new self(array_values($points), $this->width, $this->height, $this->timeFormat, $this->xLabelCount);
    }

    /** Push a single sample on the right; convenience over withPoints. */
    public function push(\DateTimeImmutable $when, int|float $value): self
    {
        return new self(
            [...$this->points, [$when, $value]],
            $this->width,
            $this->height,
            $this->timeFormat,
            $this->xLabelCount,
        );
    }

    /** PHP `date()` format string for X axis labels. Default `'H:i'`. */
    public function withTimeFormat(string $fmt): self
    {
        return new self($this->points, $this->width, $this->height, $fmt, $this->xLabelCount);
    }

    /** Number of labels rendered along the X axis (default 5). */
    public function withXLabelCount(int $n): self
    {
        return new self($this->points, $this->width, $this->height, $this->timeFormat, max(2, $n));
    }

    public function view(): string
    {
        if ($this->points === []) {
            return (new \CandyCore\Charts\Canvas\Canvas($this->width, $this->height))->view();
        }
        $values  = array_map(static fn(array $p): float => (float) $p[1], $this->points);
        $stamps  = array_map(static fn(array $p): \DateTimeImmutable => $p[0], $this->points);
        $count   = count($stamps);

        $labels = [];
        $samples = min($this->xLabelCount, $count);
        for ($i = 0; $i < $samples; $i++) {
            $idx = $samples === 1
                ? 0
                : (int) round($i * ($count - 1) / ($samples - 1));
            $labels[] = $stamps[$idx]->format($this->timeFormat);
        }

        return LineChart::new($values, $this->width, $this->height)
            ->withAxes()
            ->withXLabels($labels)
            ->view();
    }

    public function __toString(): string
    {
        return $this->view();
    }
}
