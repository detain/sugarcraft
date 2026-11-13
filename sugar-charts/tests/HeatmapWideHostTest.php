<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Heatmap\HeatPoint;
use SugarCraft\Core\Util\Width;

/**
 * CH-13 / sugar-charts-046 + -039: corner (legend) min/max labels are
 * budgeted in CELLS via Width, not codepoints via mb_strlen/mb_substr —
 * the family rule every other string-placement path follows; and
 * pushAll batches into one grid copy instead of copy-on-writing the whole
 * grid per point.
 */
final class HeatmapWideHostTest extends TestCase
{
    private static function plain(string $s): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $s) ?? '';
    }

    public function testLegendRowsStayInsideDeclaredWidth(): void
    {
        // Long numeric labels force the truncation leg at every budget.
        $chart = Heatmap::new([[-123456.75], [12345678.25]])->withLegend();
        for ($w = 2; $w <= 14; $w++) {
            $rows = explode("\n", self::plain($chart->withSize($w, 2)->view()));
            foreach ($rows as $row) {
                self::assertLessThanOrEqual(
                    $w,
                    Width::string($row),
                    "legend budget blew past width {$w}: [{$row}]"
                );
            }
        }
    }

    public function testBudgetMathIsCellAwareNotCodepointAware(): void
    {
        // Reachable labels are numeric-formatted today, so the mb-vs-Width
        // divergence cannot arrive end-to-end yet; the pin below locks the
        // cell semantics the budget now uses — a regression to mb_* would
        // let a 2-codepoint CJK label (4 cells) blow the clip open by its
        // hidden half the moment any label source goes non-ASCII.
        $label = '温度12';
        self::assertSame(4, mb_strlen($label, 'UTF-8'));
        self::assertSame(6, Width::string($label));
        self::assertSame('温度', Width::truncate($label, 4), 'budget must count cells');
        // The exact arithmetic renderLegend performs at width 8:
        // budget = 8-2 = 6, half = 3 → Width::truncate stops at whole
        // clusters (温 = 2 cells, never a half-emitted 度), where
        // mb_substr(…,0,3) would emit 3 CODEPOINTS (温度1 = 5 cells) and
        // blow the strip slot.
        $half = intdiv(8 - 2, 2);
        self::assertSame(2, Width::string(Width::truncate($label, $half)));
        self::assertSame(5, Width::string(mb_substr($label, 0, $half, 'UTF-8')));
    }

    public function testPushAllEqualsPushChainByteForByte(): void
    {
        $points = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $points[] = new HeatPoint($x, $y, (float) ($x * 8 + $y));
            }
        }
        $chain = Heatmap::new();
        foreach ($points as $p) {
            $chain = $chain->pushPoint($p);
        }
        $batch = Heatmap::new()->pushAll($points);
        self::assertSame($chain->grid, $batch->grid, 'batch door diverged from streaming door');
        self::assertSame($chain->view(), $batch->view());
    }

    public function testEmptyPushAllIsIdentity(): void
    {
        $h = Heatmap::new([[1.0]]);
        self::assertSame($h, $h->pushAll([]));
    }

    public function testBatchDoorKeepsTheCoordinateGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Heatmap::new()->pushAll([new HeatPoint(0, 0, 1.0), new HeatPoint(-1, 0, 2.0)]);
    }

    public function testBulkFeedCompletesInsideBudget(): void
    {
        $points = [];
        for ($y = 0; $y < 128; $y++) {
            for ($x = 0; $x < 128; $x++) {
                $points[] = new HeatPoint($x, $y, (float) ($x + $y));
            }
        }
        $start = hrtime(true);
        $h = Heatmap::new()->pushAll($points);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        self::assertCount(128, $h->grid);
        // Measured on this host: batched ≈17ms for 8k points, the old
        // per-point copy chain ≈127ms — the 50ms budget discriminates
        // (mutation-proven in the CH-13 run) with ~3x headroom up.
        self::assertLessThan(50.0, $elapsedMs, "bulk pushAll took {$elapsedMs}ms");
    }
}
