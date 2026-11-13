<?php

declare(strict_types=1);

namespace SugarCraft\Charts\LineChart;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\Support\FrameSize;
use SugarCraft\Style\Theme;

/**
 * Time-series LineChart variant. Wraps {@see LineChart} but accepts
 * `[\DateTimeImmutable, value]` tuples and renders X-axis labels
 * formatted from the timestamps. Useful for live metrics dashboards.
 * Points that share a timestamp map to the same axis slot — their
 * markers overlap; pre-aggregate (see Aggregation) to collapse them.
 *
 * Chrome is forwarded onto the wrapped chart (sugar-charts-006): every
 * `withTitle` / `withTheme` / `withYRange` … on this class configures the
 * inner LineChart through its own immutable copy plumbing; the time-label
 * path is re-armed at `view()` time, so labels always stay glued to the
 * timestamps no matter which chrome was set.
 *
 * Mirrors ntcharts' `linechart/timeseries`.
 */
final class TimeSeries
{
    /**
     * Point tuples as validated by the constructor door
     * (sugar-charts-064): `[DateTimeImmutable, numeric]` pairs only, so a
     * mis-typed element throws at ingestion instead of exploding as a deep
     * TypeError inside `view()`.
     *
     * @var list<array{0:\DateTimeImmutable,1:int|float}>
     */
    public readonly array $points;

    /**
     * The wrapped LineChart carrying forwarded chrome (sugar-charts-006).
     * Held seeded with axes on; `view()` injects the time-derived values
     * and labels through the inner chart's own copy plumbing.
     */
    private readonly LineChart $inner;

    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    private function __construct(
        array $points,
        public readonly int $width,
        public readonly int $height,
        public readonly string $timeFormat,
        public readonly int $xLabelCount,
        public readonly ?\DateTimeImmutable $rangeStart = null,
        public readonly ?\DateTimeImmutable $rangeEnd = null,
        ?LineChart $inner = null,
    ) {
        foreach ($points as $p) {
            if (
                !is_array($p) || count($p) < 2
                || !$p[0] instanceof \DateTimeImmutable
                || !is_numeric($p[1])
            ) {
                throw new \InvalidArgumentException(Lang::t('timeseries.point_shape', [
                    'given' => get_debug_type($p),
                ]));
            }
        }
        $this->points = array_values($points);
        // The default seed must match new()'s width/height defaults shape;
        // callers that override dims go through withSize(), which rebuilds
        // the seed — the null default here only serves defensive rebuilds.
        $this->inner = $inner ?? LineChart::new([], $width, $height)->withAxes();
    }

    /** Rebuild with the same state but a different inner chrome. */
    private function withInner(LineChart $inner): self
    {
        return new self(
            $this->points,
            $this->width,
            $this->height,
            $this->timeFormat,
            $this->xLabelCount,
            $this->rangeStart,
            $this->rangeEnd,
            $inner,
        );
    }

    /**
     * The wrapped LineChart (immutable snapshot) — chrome knobs on the
     * returned chart do NOT reach the time plot; use the forwarding
     * setters on this class instead.
     */
    public function innerChart(): LineChart
    {
        return $this->inner;
    }

    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    /**
     * Honest rendered-frame size [w, h] of view() (sugar-charts-010).
     *
     * @return array{0: int, 1: int}
     */
    public function intrinsicSize(): array
    {
        return FrameSize::of($this->view());
    }

    public static function new(array $points = [], int $width = 40, int $height = 8): self
    {
        return new self(array_values($points), $width, $height, 'H:i', 5);
    }

    /** @param list<array{0:\DateTimeImmutable,1:int|float}> $points */
    public function withPoints(array $points): self
    {
        return new self(array_values($points), $this->width, $this->height, $this->timeFormat, $this->xLabelCount, $this->rangeStart, $this->rangeEnd, $this->inner);
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
            $this->inner,
        );
    }

    /** PHP `date()` format string for X axis labels. Default `'H:i'`. */
    public function withTimeFormat(string $fmt): self
    {
        return new self($this->points, $this->width, $this->height, $fmt, $this->xLabelCount, $this->rangeStart, $this->rangeEnd, $this->inner);
    }

    /** Number of labels rendered along the X axis (default 5). */
    public function withXLabelCount(int $n): self
    {
        return new self($this->points, $this->width, $this->height, $this->timeFormat, max(2, $n), $this->rangeStart, $this->rangeEnd, $this->inner);
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
        return new self($this->points, $this->width, $this->height, $this->timeFormat, $this->xLabelCount, $start, $end, $this->inner);
    }

    // ─── Forwarded LineChart chrome (sugar-charts-006) ───────────────────
    //
    // Each setter configures the wrapped chart through its own immutable
    // copy plumbing; the time-label path is re-armed at view() time.

    public function withSize(int $w, int $h): self
    {
        return new self($this->points, $w, $h, $this->timeFormat, $this->xLabelCount, $this->rangeStart, $this->rangeEnd, $this->inner->withSize($w, $h));
    }

    public function withTitle(?string $title = null, Position $position = Position::Top): self
    {
        return $this->withInner($this->inner->withTitle($title, $position));
    }

    public function withLegend(bool $show = true): self
    {
        return $this->withInner($this->inner->withLegend($show));
    }

    public function withLegendPosition(Position $position): self
    {
        return $this->withInner($this->inner->withLegendPosition($position));
    }

    /** @param array<array<string,mixed>> $items */
    public function withLegendItems(array $items): self
    {
        return $this->withInner($this->inner->withLegendItems($items));
    }

    public function withTheme(?Theme $theme = null): self
    {
        return $this->withInner($this->inner->withTheme($theme));
    }

    /** @param ?list<string> $runes */
    public function withLineStyle(?array $runes): self
    {
        return $this->withInner($this->inner->withLineStyle($runes));
    }

    public function withSeriesColorFn(?\Closure $fn): self
    {
        return $this->withInner($this->inner->withSeriesColorFn($fn));
    }

    /** @param list<string> $labels */
    public function withYLabels(array $labels): self
    {
        return $this->withInner($this->inner->withYLabels($labels));
    }

    public function withMin(?float $m): self
    {
        return $this->withInner($this->inner->withMin($m));
    }

    public function withMax(?float $m): self
    {
        return $this->withInner($this->inner->withMax($m));
    }

    public function withYRange(?float $min, ?float $max): self
    {
        return $this->withInner($this->inner->withYRange($min, $max));
    }

    public function withPoint(string $rune): self
    {
        return $this->withInner($this->inner->withPoint($rune));
    }

    public function withAxes(bool $on = true): self
    {
        return $this->withInner($this->inner->withAxes($on));
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
    public function size(int $w, int $h): self                    { return $this->withSize($w, $h); }
    public function title(string $t, Position $p = Position::Top): self { return $this->withTitle($t, $p); }
    public function legend(bool $on = true): self                 { return $this->withLegend($on); }
    public function legendPos(Position $pos): self                { return $this->withLegendPosition($pos); }
    /** @param array<array<string,mixed>> $items */
    public function legendItems(array $items): self               { return $this->withLegendItems($items); }
    public function theme(Theme $theme): self                     { return $this->withTheme($theme); }
    /** @param ?list<string> $runes */
    public function lineStyle(?array $runes): self                { return $this->withLineStyle($runes); }
    public function seriesColorFn(?\Closure $fn): self            { return $this->withSeriesColorFn($fn); }
    /** @param list<string> $labels */
    public function yLabels(array $labels): self                  { return $this->withYLabels($labels); }
    public function min(?float $m): self                          { return $this->withMin($m); }
    public function max(?float $m): self                          { return $this->withMax($m); }
    public function yRange(?float $min, ?float $max): self        { return $this->withYRange($min, $max); }
    public function point(string $rune): self                     { return $this->withPoint($rune); }
    public function axes(bool $on = true): self                   { return $this->withAxes($on); }

    /**
     * Read the pinned time range as [start, end] (either may be null).
     * The bare `timeRange()` name is taken by the setter alias
     * ({@see withTimeRange()}), so this accessor keeps the `get` prefix
     * as a documented exception to the house law.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
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

        return $this->inner
            ->withData($values)
            ->withXLabels($labels)
            ->view();
    }

    public function __toString(): string
    {
        return $this->view();
    }
}
