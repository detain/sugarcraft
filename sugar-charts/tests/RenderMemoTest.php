<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\OHLC\Bar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Picture\Protocol;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;

/**
 * CH-34 (sugar-charts-112): per-instance render memoization.
 *
 * Immutability makes the memo safe without explicit reset: every with*
 * path constructs a new object with a fresh (null) memo. The canvas
 * bridge on Chart/LineChart is the one side-effect render surface and
 * must bypass the memo so attached grids get repainted on every call.
 */
final class RenderMemoTest extends TestCase
{
    private const WIDE = 120;
    private const TALL = 28;

    public function testEverySurfaceDelegatesToTheSharedMemo(): void
    {
        $files = [
            'Chart/Chart', 'BarChart/BarChart', 'Heatmap/Heatmap', 'Scatter/Scatter',
            'OHLC/OHLCChart', 'Sparkline/Sparkline', 'Picture/Picture',
            'LineChart/TimeSeries',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents(\dirname(__DIR__) . '/src/' . $rel . '.php');
            $this->assertStringContainsString('memoizedRender(', $src, "{$rel} must route view() through the memo");
        }
    }

    public function testLineChartSecondViewIsAMemoHit(): void
    {
        $this->assertMemoised(LineChart::new(range(1, 20_000), self::WIDE, self::TALL)->withYRange(0.0, 20_000.0));
    }

    public function testBarChartSecondViewIsAMemoHit(): void
    {
        $bars = [];
        for ($i = 0; $i < 800; $i++) {
            $bars[] = ['bar' . $i, (float) sin($i / 7.0) * 40 + 50];
        }
        $this->assertMemoised(BarChart::new($bars, self::WIDE, self::TALL)->withAxes(true));
    }

    public function testHeatmapSecondViewIsAMemoHit(): void
    {
        $grid = [];
        for ($r = 0; $r < 200; $r++) {
            $grid[] = array_map(static fn(int $c): float => ($r * 31 + $c * 17) % 100 / 10.0, range(0, 199));
        }
        $this->assertMemoised(Heatmap::new($grid)->withSize(120, 100));
    }

    public function testScatterSecondViewIsAMemoHit(): void
    {
        $points = array_map(
            static fn(int $i): array => [sin($i / 11.0) * 100.0, cos($i / 13.0) * 90.0],
            range(0, 8000),
        );
        $this->assertMemoised(Scatter::new($points, self::WIDE, self::TALL));
    }

    public function testOhlcSecondViewIsAMemoHit(): void
    {
        $bars = [];
        for ($i = 0; $i < 2000; $i++) {
            $open = 100.0 + sin($i / 9.0) * 20;
            $bars[] = new Bar(open: $open, high: $open + 12.0, low: $open - 9.0, close: $open + sin($i) * 8.0);
        }
        $this->assertMemoised(OHLCChart::new($bars, self::WIDE, self::TALL));
    }

    public function testSparklineSecondViewIsAMemoHit(): void
    {
        $this->assertMemoised(Sparkline::new(range(1, 60_000), 4000));
    }

    public function testPictureSecondViewIsAMemoHit(): void
    {
        $grid = array_map(
            static fn(int $r): array => array_map(
                static fn(int $c): Color => Color::rgb(($r * 13 + $c * 7) % 256, $r % 256, $c % 256),
                range(0, 191),
            ),
            range(0, 191),
        );
        $this->assertMemoised(Picture::fromGrid($grid)->withProtocol(Protocol::Sixel));
    }

    public function testTimeSeriesSecondViewIsAMemoHit(): void
    {
        $base = new \DateTimeImmutable('2026-01-01 00:00:00');
        $points = array_map(
            static fn(int $i): array => [$base->modify("+{$i} second"), (float) sin($i / 41.0) * 50],
            range(0, 6000),
        );
        $this->assertMemoised(TimeSeries::new($points, self::WIDE, self::TALL));
    }

    public function testMemoIsPerObjectAndMutationInvalidatesBytes(): void
    {
        $a = LineChart::new([1.0, 5.0, 3.0, 9.0], 20, 6)->withYRange(0.0, 10.0);
        $first = $a->view();
        $this->assertSame($first, $a->view(), 'the memo must not change bytes');

        $b = $a->withYRange(0.0, 100.0);
        $this->assertNotSame($first, $b->view(), 'a mutation between views must change the output');
        $this->assertSame($first, $a->view(), 'the original object keeps its own render');

        // A re-run of the same mutation lands on identical bytes (memo is
        // an optimization, never a different code path).
        $this->assertSame($b->view(), $a->withYRange(0.0, 100.0)->view());
    }

    public function testCanvasBridgeRenderRepaintsOnEveryCall(): void
    {
        // CH-07 side-effect surface: view() PAINTS the caller's grid. A
        // naive memo would skip the second paint after the caller cleared
        // the grid — the bridge must bypass the memo.
        $grid = new Canvas(20, 6);
        $chart = LineChart::new([1.0, 5.0, 3.0, 9.0], 20, 6)
            ->withYRange(0.0, 10.0)
            ->withCanvas($grid);

        $first = $chart->view();
        $this->assertNotSame('', $first);
        $paintedAfterFirst = $grid->view();
        $this->assertNotSame((new Canvas(20, 6))->view(), $paintedAfterFirst, 'the first view must paint the bridge');

        $grid->clear();
        $second = $chart->view();
        $this->assertSame($first, $second, 'bypassed render must be byte-identical');
        $this->assertSame($paintedAfterFirst, $grid->view(), 'the cleared grid must be repainted on the second call');
    }

    /**
     * Proves the memo actually short-circuits: second view of the SAME
     * object (a memo hit, ~0 cost) must land under 25% of a fresh
     * mutation-derived instance's FIRST view (full compose), while both
     * produce identical bytes. The fresh instance sidesteps the memo
     * itself, so this is a measurement of the compose, never of a hit.
     */
    private function assertMemoised(object $chart): void
    {
        $composeOnce = (string) $chart->view();

        $bestFreshMs = PHP_FLOAT_MAX;
        $hitMs = PHP_FLOAT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $fresh = $this->freshVariant($chart);
            $s = hrtime(true);
            $out = (string) $fresh->view();
            $bestFreshMs = min($bestFreshMs, (hrtime(true) - $s) / 1e6);
            $this->assertSame($composeOnce, $out, 'fresh-variant chrome must not change bytes');

            $s = hrtime(true);
            $out = (string) $chart->view();
            $hitMs = min($hitMs, (hrtime(true) - $s) / 1e6);
            $this->assertSame($composeOnce, $out, 'memo hit must not change bytes');
        }

        $this->assertGreaterThan(
            $hitMs * 4,
            $bestFreshMs,
            sprintf('compose %.2fms vs memo hit %.3fms — no memo speed-up observed', $bestFreshMs, $hitMs),
        );
    }

    /**
     * A byte-identical, memo-clean clone: the null-clearing aliases
     * (withTitle(null)/withLegend(false)/withStyle(null)) rebuild via
     * `new static(...)`/`new self(...)` without changing chrome when
     * the knob was already unset.
     */
    private function freshVariant(object $chart): object
    {
        return match (true) {
            $chart instanceof Chart,
            $chart instanceof BarChart,
            $chart instanceof Scatter,
            $chart instanceof OHLCChart,
            $chart instanceof TimeSeries => $chart->withTitle(null),
            $chart instanceof Heatmap => $chart->withLegend(false),
            $chart instanceof Sparkline => $chart->withStyle(null),
            $chart instanceof Picture => $chart->withProtocol($chart->protocol),
            default => $this->fail('unhandled surface'),
        };
    }
}
