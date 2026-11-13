<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;

/**
 * CH-18 (sugar-charts-059/-060): animation first frames must show at
 * least one point once progress is positive (both the cell and the
 * braille render path), and auto x-ticks must span the true sample
 * domain across all series, not just the primary one.
 */
final class LineChartAnimTest extends TestCase
{
    public function testFirstCellFrameShowsAtLeastOnePoint(): void
    {
        $chart = LineChart::new(range(1, 100), 40, 8)
            ->withAnimationProgress(1 / 200); // floor() used to zero this frame
        $this->assertGreaterThanOrEqual(1, substr_count($chart->view(), '*'));
    }

    public function testTinyPositiveProgressStillPaints(): void
    {
        $chart = LineChart::new(range(1, 100), 40, 8)
            ->withAnimationProgress(0.001); // round() gives 0 here — max(1,·) carries the frame
        $this->assertGreaterThanOrEqual(1, substr_count($chart->view(), '*'));
    }

    public function testBrailleFirstFramePaints(): void
    {
        $base = LineChart::new(range(1, 100), 40, 8)->withBraille();
        $blank = $base->withAnimationProgress(0.0)->view();
        $first = $base->withAnimationProgress(1 / 200)->view();
        $this->assertNotSame($blank, $first, 'braille path carries the same first-frame rule as the cell path');
    }

    public function testProgressZeroStaysHonestEmpty(): void
    {
        $out = LineChart::new(range(1, 100), 40, 8)->withAnimationProgress(0.0)->view();
        $this->assertStringNotContainsString('*', $out, '0.0 must keep meaning nothing rendered');
    }

    public function testMidFrameUsesRoundSemantics(): void
    {
        // Disclosed shift (059 fix sketch pins round, not floor): 9 points
        // at progress 0.5 shows 5, where the legacy floor() showed 4.
        $chart = LineChart::new(range(1, 9), 40, 8)->withAnimationProgress(0.5);
        $this->assertSame(5, substr_count($chart->view(), '*'));
        $full = LineChart::new(range(1, 9), 40, 8)->withAnimationProgress(1.0);
        $this->assertSame(9, substr_count($full->view(), '*'));
    }

    public function testXTickDomainSpansLongerSecondarySeries(): void
    {
        $seen = [];
        $chart = LineChart::new([1, 2, 3], 40, 8)
            ->withDataset('long', range(1, 10))
            ->withAxes()
            ->xLabelFormatter(static function (float $v) use (&$seen): string {
                $seen[] = $v;
                return (string) $v;
            });
        $chart->view();
        // Default 5 ticks over the true domain 0..9 (primary-only floor
        // would have mis-derived 0..2 from the 3-point primary series).
        $this->assertSame(0.0, $seen[0]);
        $this->assertSame(9.0, end($seen), 'auto x-ticks must span the max series count');
    }

    public function testEmptyPrimaryStillTicksTrueDomain(): void
    {
        $seen = [];
        $chart = LineChart::new([], 40, 8)
            ->withDataset('only', range(1, 10))
            ->withAxes()
            ->xLabelFormatter(static function (float $v) use (&$seen): string {
                $seen[] = $v;
                return (string) $v;
            });
        $chart->view();
        $this->assertSame(9.0, end($seen), 'empty primary must not collapse the domain to 0..1');
    }

    public function testManualXLabelsBypassTheDomain(): void
    {
        $chart = LineChart::new([1, 2], 40, 8)
            ->withDataset('long', range(1, 10))
            ->withXLabels(['a', 'b'])
            ->withAxes()
            ->xLabelFormatter(static fn(float $v): string => 'FORGED' . $v);
        $out = $chart->view();
        $this->assertStringNotContainsString('FORGED', $out, 'explicit labels win over the auto sampler');
    }

    public function testEmptyPrimaryNoFormatterDoesNotThrow(): void
    {
        // The domain computation must survive the degenerate shapes it now
        // runs for on EVERY render: empty primary + no datasets is the
        // single-element max() crash path (caught by the suite mid-lane).
        $out = LineChart::new([], 40, 8)->withAxes()->view();
        $this->assertIsString($out);
    }
}
