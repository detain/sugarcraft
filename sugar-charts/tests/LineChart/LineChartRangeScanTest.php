<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;

/**
 * CH-19 (sugar-charts-037): with BOTH Y ends pinned the per-sample
 * min/max scan cannot change the answer (Range::resolve discards the scan
 * pair under unconditional pins, and min>max is refused at the ctor
 * door), so the render path takes a short-circuit arm instead of the
 * O(total-samples) pass.
 */
final class LineChartRangeScanTest extends TestCase
{
    public function testPinnedPathBytesIdenticalToScannedPath(): void
    {
        $data = [3, 1, 4, 1, 5, 9, 2, 6];
        // The scan of this data yields exactly [1,9]; pinning the same
        // ends through the short-circuit arm must not move one byte.
        $scanned = LineChart::new($data, 40, 8)->view();
        $pinned  = LineChart::new($data, 40, 8)->withYRange(1.0, 9.0)->view();
        $this->assertSame($scanned, $pinned);
    }

    public function testPinnedEmptyDataStillRendersBlankCanvas(): void
    {
        $blank   = LineChart::new([], 40, 8)->view();
        $pinned  = LineChart::new([], 40, 8)->withYRange(0.0, 10.0)->view();
        $this->assertSame($blank, $pinned, 'the cheap-count emptiness leg must preserve the blank-canvas contract');

        $emptySeries = LineChart::new([], 40, 8)
            ->withYRange(0.0, 10.0)
            ->withDataset('ghost', [])
            ->view();
        $this->assertSame($blank, $emptySeries, 'an empty secondary must not defeat the emptiness probe');
    }

    public function testLonePinStillScansTheOtherEnd(): void
    {
        // Only the both-pinned arm short-circuits: a lone max pin keeps
        // the live auto-min scan (render proves it by plotting data).
        $chart = LineChart::new([2, 4, 8], 20, 6)->withMax(10.0);
        $this->assertStringContainsString('*', $chart->view());
    }

    public function testPinnedRangeSkipsSampleScanAtScale(): void
    {
        $big = range(1, 200_000);
        $pinned = LineChart::new($big, 40, 8)->withYRange(1.0, 200_000.0);
        $auto   = LineChart::new($big, 40, 8);
        // Pin the rendered output first: identical bytes keep this a pure
        // timing claim about the scan, not about different renders.
        $this->assertSame($auto->view(), $pinned->view());

        $best = static function (LineChart $c): float {
            $t = PHP_FLOAT_MAX;
            for ($i = 0; $i < 3; $i++) {
                $s = hrtime(true);
                $c->view();
                $t = min($t, (hrtime(true) - $s) / 1e6);
            }
            return $t;
        };
        $pinnedMs = $best($pinned);
        $autoMs   = $best($auto);
        // The 200k-sample float compare pass is strictly additive on the
        // auto side; the short-circuit arm must land well under it even
        // with shared-machine headroom (4x guard).
        $this->assertLessThan($autoMs * 0.25, $pinnedMs, sprintf(
            'pinned path %.2fms vs scanned %.2fms — the scan did not get skipped',
            $pinnedMs,
            $autoMs,
        ));
    }

    public function testShortCircuitArmIsStructurallyPresent(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/LineChart/LineChart.php');
        $this->assertMatchesRegularExpression(
            '/if \(\$this->min !== null && \$this->max !== null\) \{/',
            $src,
            'the both-pinned short-circuit arm must stay (the scanned else-arm keeps Range::resolve for lone pins)',
        );
    }
}
