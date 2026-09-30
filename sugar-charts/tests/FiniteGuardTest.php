<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use SugarCraft\Charts\Aggregation\BucketByTime;
use SugarCraft\Charts\Aggregation\MovingAverage;
use SugarCraft\Charts\Aggregation\Resample;
use SugarCraft\Charts\BarChart\Bar;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Canvas\Graph;
use SugarCraft\Charts\Chart\NiceScale;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Heatmap\HeatPoint;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\Streamline;
use SugarCraft\Charts\LineChart\Waveline;
use SugarCraft\Charts\OHLC\Bar as OhlcBar;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use PHPUnit\Framework\TestCase;

/**
 * Every numeric ingestion point rejects NaN / ±INF (SugarCraft
 * "no silent failures" convention). Removing the guard at any point
 * turns the matching case here red.
 */
final class FiniteGuardTest extends TestCase
{
    /**
     * @dataProvider ingestionPoints
     */
    public function testIngestionPointRejectsNonFinite(\Closure $ingest, float $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $ingest($bad);
    }

    /**
     * Each closure feeds one non-finite value through a distinct
     * ingestion point named in the audit.
     *
     * @return iterable<string, array{\Closure, float}>
     */
    public static function ingestionPoints(): iterable
    {
        // BarChart:325 — value enters via the Bar value object (coerceBars).
        yield 'Bar ctor NaN'          => [static fn(float $v) => new Bar('x', $v), NAN];
        yield 'Bar ctor INF'          => [static fn(float $v) => new Bar('x', $v), INF];
        yield 'BarChart::new NaN'     => [static fn(float $v) => BarChart::new([['a', $v]]), NAN];
        yield 'BarChart::push INF'    => [static fn(float $v) => BarChart::new()->push(['a', $v]), INF];

        // LineChart:409 — data enters via new/withData/push/withDataset.
        yield 'LineChart::new NaN'    => [static fn(float $v) => LineChart::new([1, $v]), NAN];
        yield 'LineChart::withData INF' => [static fn(float $v) => LineChart::new()->withData([$v]), INF];
        yield 'LineChart::push NaN'   => [static fn(float $v) => LineChart::new()->push($v), NAN];
        yield 'LineChart::withDataset INF' => [static fn(float $v) => LineChart::new()->withDataset('s', [$v]), INF];

        // Scatter:204 — points enter via new/withPoints.
        yield 'Scatter::new x NaN'    => [static fn(float $v) => Scatter::new([[$v, 1]]), NAN];
        yield 'Scatter::new y INF'    => [static fn(float $v) => Scatter::new([[1, $v]]), INF];
        yield 'Scatter::withPoints NaN' => [static fn(float $v) => Scatter::new()->withPoints([[1, $v]]), NAN];

        // Heatmap:266 — grid cells enter via new(); samples via HeatPoint.
        yield 'Heatmap::new NaN'      => [static fn(float $v) => Heatmap::new([[0.1, $v]]), NAN];
        yield 'HeatPoint ctor INF'    => [static fn(float $v) => new HeatPoint(0, 0, $v), INF];

        // NiceScale:35 — axis maximum.
        yield 'NiceScale::ceiling NaN' => [static fn(float $v) => NiceScale::ceiling($v), NAN];
        yield 'NiceScale::ceiling INF' => [static fn(float $v) => NiceScale::ceiling($v), INF];

        // Streamline feeds LineChart — guard at the streaming ingestion point.
        yield 'Streamline::push NaN'  => [static fn(float $v) => Streamline::new(5, 3)->push($v), NAN];
        yield 'Streamline::push INF'  => [static fn(float $v) => Streamline::new(5, 3)->push($v), INF];

        // Audit F7: Sparkline / Waveline data doors were unguarded.
        yield 'Sparkline::new NaN'    => [static fn(float $v) => Sparkline::new([1, $v]), NAN];
        yield 'Sparkline::push INF'   => [static fn(float $v) => Sparkline::new([1])->push($v), INF];
        yield 'Waveline::new NaN'     => [static fn(float $v) => Waveline::new([[1.0, $v]]), NAN];
        yield 'Waveline::push INF'    => [static fn(float $v) => Waveline::new()->push(1.0, $v), INF];

        // Audit F8: OHLC bar candles had no ingestion door at all.
        yield 'OHLC\Bar ctor NaN'     => [static fn(float $v) => new OhlcBar(1.0, $v, 0.0, 1.0), NAN];
        yield 'OHLC\Bar ctor INF'     => [static fn(float $v) => new OhlcBar(1.0, 2.0, $v, 1.0), INF];

        // Audit F7: the Aggregation trio stored anything it was handed.
        yield 'MovingAverage::add NaN' => [static fn(float $v) => MovingAverage::new(3)->add($v), NAN];
        yield 'MovingAverage::ema INF' => [static fn(float $v) => MovingAverage::ema(2, [1.0, $v]), INF];
        yield 'BucketByTime::add NaN'  => [static fn(float $v) => BucketByTime::new(10)->add(0, $v), NAN];
        yield 'Resample::add NaN'      => [static fn(float $v) => Resample::new(10)->add(0, $v), NAN];
        yield 'Resample::last INF'     => [static fn(float $v) => Resample::last(10, [['ts' => 0, 'value' => $v]]), INF];

        // Audit F3: pinned ranges go through Range::pin on every chart.
        yield 'LineChart::withYRange NaN' => [static fn(float $v) => LineChart::new([1])->withYRange($v, 5.0), NAN];
        yield 'BarChart::withMin INF'     => [static fn(float $v) => BarChart::new([['a', 1.0]])->withMin($v), INF];
        yield 'Scatter::withXRange NaN'   => [static fn(float $v) => Scatter::new([[1, 1]])->withXRange($v, 5.0), NAN];
        yield 'Sparkline::withMax INF'    => [static fn(float $v) => Sparkline::new([1])->withMax($v), INF];

        // Audit F1/F22: niceNumbers ran uncatchable allocation loops on
        // ±1e308 inputs; non-finite ends must now throw at the door.
        yield 'Graph::niceNumbers min NaN' => [static fn(float $v) => Graph::niceNumbers($v, 1.0, 5), NAN];
        yield 'Graph::niceNumbers max INF' => [static fn(float $v) => Graph::niceNumbers(0.0, $v, 5), INF];
    }

    public function testFiniteDataStillRenders(): void
    {
        // Sanity: the guards are transparent to well-formed input.
        self::assertNotSame('', BarChart::new([['a', 1.0], ['b', 2.0]], 10, 4)->view());
        self::assertNotSame('', LineChart::new([1, 2, 3], 10, 4)->view());
        self::assertSame(5000.0, NiceScale::ceiling(4500.0));
    }
}
