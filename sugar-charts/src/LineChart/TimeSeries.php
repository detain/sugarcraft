<?php

declare(strict_types=1);

namespace SugarCraft\Charts\LineChart;

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
        public readonly ?\DateTimeImmutable $rangeStart = null,
        public readonly ?\DateTimeImmutable $rangeEnd = null,
    ) {}

    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    public static function new(array $points = [], int $width = 40, int $height = 8): self
    {
        return new self(array_values($points), $width, $height, 'H:i', 5);
    }

    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    public function withPoints(array $points): self
    {
        return new self(array_values($points), $this->width, $this->height, $this->timeFormat, $this->xLabelCount, $this->rangeStart, $this->rangeEnd);
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
            $this->rangeStart,
            $this->rangeEnd,
        );
    }

    /** PHP `date()` format string for X axis labels. Default `'H:i'`. */
    public function withTimeFormat(string $fmt): self
    {
        return new self($this->points, $this->width, $this->height, $fmt, $this->xLabelCount, $this->rangeStart, $this->rangeEnd);
    }

    /** Number of labels rendered along the X axis (default 5). */
    public function withXLabelCount(int $n): self
    {
        return new self($this->points, $this->width, $this->height, $this->timeFormat, max(2, $n), $this->rangeStart, $this->rangeEnd);
    }

    /**
     * Pin the rendered X-axis range to `[$start, $end]`. Points outside
     * the range are filtered out before rendering, and the X-axis
     * labels span the explicit range rather than the data extent.
     * Pass null endpoints to clear and revert to auto-range. Mirrors
     * ntcharts' `WithTimeRange`.
     */
    public function withTimeRange(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): self
    {
        return new self($this->points, $this->width, $this->height, $this->timeFormat, $this->xLabelCount, $start, $end);
    }

    // Short-form aliases.
    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    public function points(array $points): self   { return $this->withPoints($points); }
    /** Short-form alias for {@see withTimeFormat()}. */
    public function timeFormat(string $fmt): self { return $this->withTimeFormat($fmt); }
    /** Short-form alias for {@see withXLabelCount()}. */
    public function xLabelCount(int $n): self     { return $this->withXLabelCount($n); }
    /** Short-form alias for {@see withTimeRange()}. */
    public function timeRange(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): self {
        return $this->withTimeRange($start, $end);
    }

    public function getTimeRange(): array
    {
        return [$this->rangeStart, $this->rangeEnd];
    }

    public function view(): string
    {
        if ($this->points === []) {
            return (new \SugarCraft\Charts\Canvas\Canvas($this->width, $this->height))->view();
        }
        $points = $this->points;
        if ($this->rangeStart !== null || $this->rangeEnd !== null) {
            $start = $this->rangeStart;
            $end   = $this->rangeEnd;
            $points = array_values(array_filter($points, static function (array $p) use ($start, $end): bool {
                if ($start !== null && $p[0] < $start) { return false; }
                if ($end !== null && $p[0] > $end)     { return false; }
                return true;
            }));
            if ($points === []) {
                return (new \SugarCraft\Charts\Canvas\Canvas($this->width, $this->height))->view();
            }
        }

        $values  = array_map(static fn(array $p): float => (float) $p[1], $points);
        $stamps  = array_map(static fn(array $p): \DateTimeImmutable => $p[0], $points);
        $count   = count($stamps);

        $labels = [];
        $samples = min($this->xLabelCount, $count);
        // When a range is pinned, label the explicit range endpoints
        // rather than the data extent so axes don't collapse to the
        // available samples only.
        if (($this->rangeStart !== null || $this->rangeEnd !== null) && $samples >= 2) {
            $start = $this->rangeStart ?? $stamps[0];
            $end   = $this->rangeEnd ?? $stamps[$count - 1];
            // Preserve the start's timezone for output so labels match
            // the user-facing range, not the UTC equivalent.
            $tz      = $start->getTimezone();
            $startTs = $start->getTimestamp();
            $endTs   = $end->getTimestamp();
            $span    = max(1, $endTs - $startTs);
            for ($i = 0; $i < $samples; $i++) {
                $ts = $startTs + (int) round($i * $span / ($samples - 1));
                $labels[] = (new \DateTimeImmutable('@' . $ts))
                    ->setTimezone($tz)
                    ->format($this->timeFormat);
            }
        } else {
            for ($i = 0; $i < $samples; $i++) {
                $idx = $samples === 1
                    ? 0
                    : (int) round($i * ($count - 1) / ($samples - 1));
                $labels[] = $stamps[$idx]->format($this->timeFormat);
            }
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
