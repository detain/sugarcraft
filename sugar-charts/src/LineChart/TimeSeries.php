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
 * Samples are plotted at their TIME position (uneven gaps widen on the
 * axis), and samples sharing a timestamp collapse to the same column —
 * the later value wins; pre-aggregate (see Aggregation) to collapse
 * them deliberately.
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

    /**
     * `date()` format for X-axis labels, or null for the honest default:
     * `'H:i'` within a day, widened to `'m-d H:i'` when the rendered span
     * exceeds 24 hours (sugar-charts-100).
     */
    public readonly ?string $timeFormat;

    /**
     * The wrapped LineChart carrying forwarded chrome (sugar-charts-006).
     * Held seeded with axes on; `view()` injects the time-derived values
     * and labels through the inner chart's own copy plumbing.
     */
    private function __construct(
        array $points,
        public readonly int $width,
        public readonly int $height,
        ?string $timeFormat = null,
        public readonly int $xLabelCount = 5,
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
        $this->points     = array_values($points);
        $this->timeFormat = $timeFormat;
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
        return new self(array_values($points), $width, $height, null, 5);
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

    /**
     * PHP `date()` format string for X axis labels. Pass null to return to
     * the honest auto default: `'H:i'` inside a day, widened to `'m-d H:i'`
     * when the rendered span exceeds 24 hours (sugar-charts-100). Formats
     * the runtime rejects (warnings/throws from `date()`) are refused at
     * this door (sugar-charts-107); beyond that probe the formatting is
     * best-effort `date()` semantics.
     */
    public function withTimeFormat(?string $fmt): self
    {
        if ($fmt !== null) {
            self::assertTimeFormat($fmt);
        }
        return new self($this->points, $this->width, $this->height, $fmt, $this->xLabelCount, $this->rangeStart, $this->rangeEnd, $this->inner);
    }

    /**
     * Door probe (sugar-charts-107): a format that makes PHP's `date()`
     * warn or throw would corrupt every label at render time — refuse it
     * once at ingestion instead. PHP 8.3 accepts literal noise like
     * `'not-a-format'` (echoed verbatim); that best-effort tail is
     * documented on {@see withTimeFormat()} rather than guessed at.
     */
    private static function assertTimeFormat(string $fmt): void
    {
        $warned = false;
        set_error_handler(static function (int $severity, string $message) use (&$warned): bool {
            $warned = true;
            return true;
        });
        try {
            date($fmt, 0);
        } catch (\Throwable) {
            $warned = true;
        } finally {
            restore_error_handler();
        }
        if ($warned) {
            throw new \InvalidArgumentException(Lang::t('timeseries.time_format', ['fmt' => $fmt]));
        }
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
    public function timeFormat(?string $fmt): self { return $this->withTimeFormat($fmt); }
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
        // sugar-charts-009: plot in TIME, not in arrival order. Samples are
        // sorted, mapped to columns round((t-start)/span*(cols-1)) against
        // the label range, and linearly re-sampled per column, so unevenly
        // spaced points render proportionally wider gaps. Samples sharing a
        // timestamp collapse to one column — the later sample wins.
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
                // sugar-charts-099: an empty window is a result, not silence.
                return $this->noticeFrame('[no points in range]');
            }
        }

        usort($points, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $stamps = array_map(static fn(array $p): \DateTimeImmutable => $p[0], $points);
        $values = array_map(static fn(array $p): float => (float) $p[1], $points);
        $count  = count($stamps);

        $tz      = ($this->rangeStart ?? $stamps[0])->getTimezone();
        $startTs = ($this->rangeStart ?? $stamps[0])->getTimestamp();
        $endTs   = ($this->rangeEnd   ?? $stamps[$count - 1])->getTimestamp();
        if ($endTs < $startTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }
        $span = $endTs - $startTs;
        $fmt  = $this->timeFormat ?? ($span > 86400 ? 'm-d H:i' : 'H:i'); // sugar-charts-100

        $cols = $span > 0 ? min(max($this->width, 2), $span + 1) : 0;
        if ($cols < 2 || $count < 2) {
            // Degenerate domain (single instant or single sample): ordinal
            // placement is all the data can honestly support.
            $plot   = $values;
            $labels = [date($fmt, $startTs)];
        } else {
            $plot = [];
            for ($k = 0; $k < $cols; $k++) {
                $t     = $startTs + (int) round($k * $span / ($cols - 1));
                $plot[] = self::sampleAt($points, $t);
            }
            $samples = min($this->xLabelCount, $cols);
            $labels  = [];
            for ($i = 0; $i < $samples; $i++) {
                $ts = $samples === 1 ? $startTs : $startTs + (int) round($i * $span / ($samples - 1));
                $labels[] = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($fmt);
            }
        }

        return $this->inner
            ->withData($plot)
            ->withXLabels($labels)
            ->view();
    }

    /**
     * A frame the declared size holding one notice row (sugar-charts-099):
     * Picture's always-visible placeholder posture.
     */
    private function noticeFrame(string $notice): string
    {
        $line  = \SugarCraft\Core\Util\Width::truncate($notice, $this->width);
        $lines = [$line];
        for ($i = 1, $h = max(1, $this->height); $i < $h; $i++) {
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    /**
     * Linear interpolation of the sorted points at instant $t; the series
     * extends flat outside the sample extent.
     *
     * @param list<array{0:\DateTimeImmutable,1:int|float}> $points
     */
    private static function sampleAt(array $points, int $t): float
    {
        $first = $points[0];
        if ($t <= $first[0]->getTimestamp()) {
            return (float) $first[1];
        }
        $last = $points[count($points) - 1];
        if ($t >= $last[0]->getTimestamp()) {
            return (float) $last[1];
        }
        $prev = $first;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $curTs = $points[$i][0]->getTimestamp();
            if ($curTs >= $t) {
                $prevTs = $prev[0]->getTimestamp();
                if ($curTs === $prevTs) {
                    return (float) $points[$i][1];
                }
                $frac = ($t - $prevTs) / ($curTs - $prevTs);
                return (float) $prev[1] + $frac * ((float) $points[$i][1] - (float) $prev[1]);
            }
            $prev = $points[$i];
        }
        return (float) $last[1];
    }

    public function __toString(): string
    {
        return $this->view();
    }
}
