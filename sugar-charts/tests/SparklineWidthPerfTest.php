<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Sparkline\Sparkline;

/**
 * CH-14 / sugar-charts-011 (H): renderBuffer used to loop withCellAt() on
 * the immutable Buffer — one whole-grid clone per cell, O(width²). QA's
 * probe: w=800 → 0.015s, w=6400 → 0.196s (~13×, not 8×), w=100000 never
 * completed in 120s (RC=124). The single-pass fromGrid assembly must keep
 * the doubling cost near-linear at the reference sizes.
 */
final class SparklineWidthPerfTest extends TestCase
{
    /** Best-of-N seconds for one render (absorbs scheduler jitter). */
    private static function timeRender(Sparkline $s, int $runs = 2): float
    {
        $best = INF;
        for ($i = 0; $i < $runs; $i++) {
            $t0 = hrtime(true);
            $s->view();
            $best = min($best, (hrtime(true) - $t0) / 1e9);
        }
        return $best;
    }

    public function testWidth800And6400FitTheLinearBudgets(): void
    {
        $data = [];
        for ($i = 0; $i < 6400; $i++) {
            $data[] = $i % 97;
        }
        $t800  = self::timeRender(Sparkline::new(array_slice($data, 0, 800), 800));
        $t6400 = self::timeRender(Sparkline::new($data, 6400));
        // Measured here: single-pass 1.9ms / 15.5ms; the old per-cell
        // clone chain 17.1ms / 209ms (QA reference: 0.015s → 0.196s).
        // Both budgets turn RED on the quadratic regime with ≥1.7x
        // margin and hold 5x headroom for loaded CI shards.
        self::assertLessThan(0.010, $t800, "w=800 render took {$t800}s");
        self::assertLessThan(0.030, $t6400, "w=6400 render took {$t6400}s");
    }

    public function testHundredThousandWidthCompletes(): void
    {
        $start = hrtime(true);
        $out   = Sparkline::new(range(0, 99999), 100000)->view();
        $secs  = (hrtime(true) - $start) / 1e9;
        self::assertNotSame('', $out);
        // The pre-fix probe never completed in 120s (killed, RC=124);
        // batched assembly measures ~0.24s here.
        self::assertLessThan(1.0, $secs, "w=100000 render took {$secs}s");
    }
}
