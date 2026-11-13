<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Chart\NiceScale;
use SugarCraft\Charts\LineChart\LineChart;

/**
 * Default-axis nice ticks (sugar-charts-026 / CH-22): formatter-less axes
 * label the Y scale through the NiceScale ladder — round 1/2/5 × 10^e rungs
 * placed at their true plot rows — while explicit formatter or manual label
 * lists keep their pre-ladder slot behavior.
 */
final class NiceTicksTest extends TestCase
{
    public function testLadderTicksCoverDomainAndDropOutsideRungs(): void
    {
        $this->assertSame([0.0, 2.0, 4.0, 6.0, 8.0], NiceScale::ticks(0.0, 8.0, 4));
        // niceMin grid point 0 sits below the domain 1..8 and is dropped.
        $this->assertSame([2.0, 4.0, 6.0, 8.0], NiceScale::ticks(1.0, 8.0, 4));
        // Inverted bounds normalize instead of throwing.
        $this->assertSame([0.0, 2.0, 4.0, 6.0, 8.0], NiceScale::ticks(8.0, 0.0, 4));
    }

    public function testLadderDegenerateRangeEmitsNothing(): void
    {
        $this->assertSame([], NiceScale::ticks(3.0, 3.0));
    }

    public function testFormatTicksUsesRungSpacingNotRawFloat(): void
    {
        // A 0.2-step ladder accumulates binary float error; labels must not.
        $labels = NiceScale::formatTicks(NiceScale::ticks(0.0, 1.0, 5));
        foreach ($labels as $label) {
            $this->assertDoesNotMatchRegularExpression('/0{4,}|e-[0-9]/', $label);
        }
        $this->assertContains('0.2', $labels);
        $this->assertContains('1', $labels);
        // Integer rungs stay bare integers — no trailing dot.
        $this->assertSame(['0', '2', '4'], NiceScale::formatTicks([0.0, 2.0, 4.0]));
    }

    public function testNegativeSymmetricRangeLadders(): void
    {
        $this->assertSame([-4.0, -2.0, 0.0, 2.0, 4.0], NiceScale::ticks(-4.0, 4.0, 4));
        $this->assertSame(['-4', '-2', '0', '2', '4'], NiceScale::formatTicks(NiceScale::ticks(-4.0, 4.0, 4)));
    }

    public function testDefaultAxesEmitNiceLabelsNotInterpolatedBounds(): void
    {
        $view = LineChart::new([0, 2, 4, 6, 8], 20, 8)->withAxes(true)->view();
        $this->assertStringContainsString('8', $view);
        $this->assertStringNotContainsString('5.6667', $view, 'raw interpolated bound leaked past the ladder');
        $this->assertStringNotContainsString('3.3333', $view);
        // Ladder top label sits on the top plot row, bottom label on the
        // row above the axis — the labels are value-anchored, not slot-fed.
        $lines = explode("\n", $view);
        $this->assertStringStartsWith('8 │', $lines[0]);
        $this->assertStringStartsWith('0 │', $lines[count($lines) - 2]);
    }

    public function testFormatterStillWinsOverLadder(): void
    {
        $view = LineChart::new([0, 2, 4, 6, 8], 20, 8)
            ->withAxes(true)
            ->withYLabelFormatter(static fn(float $v): string => 'x' . $v)
            ->view();
        $this->assertStringContainsString('x', $view);
        // The formatter's own top slot reads 'x…' — the ladder's bare
        // '8 │' top label must not appear anywhere.
        $this->assertStringNotContainsString("8 │\n", $view, 'ladder labels overwrote the explicit formatter');
    }

    public function testManualLabelsWinOverLadder(): void
    {
        $view = LineChart::new([0, 2, 4, 6, 8], 20, 8)
            ->withAxes(true)
            ->withYLabels(['aaa', 'bbb', 'ccc'])
            ->view();
        $this->assertStringContainsString('aaa', $view);
        $this->assertStringNotContainsString('8 │', $view);
    }

    public function testAxesOffKeepsBareGutterContract(): void
    {
        $view = LineChart::new([0, 2, 4, 6, 8], 20, 8)->view();
        $this->assertStringNotContainsString('8 │', $view, 'ladder labels appeared with axes off');
    }

    public function testLadderBudgetCoarsensRungs(): void
    {
        // Budget 2 asks the ladder for 3 boundary-inclusive rungs over 0..8:
        // target step 4 rounds up the 1/2/5 ladder to 5 → ticks 0/5 only.
        $this->assertSame([0.0, 5.0], NiceScale::ticks(0.0, 8.0, 2));
        // Budget 6 → target step 8/5 = 1.6 → rung 2 keeps the dense 0..8 set.
        $this->assertSame([0.0, 2.0, 4.0, 6.0, 8.0], NiceScale::ticks(0.0, 8.0, 6));
    }

    public function testLadderSurvivesPinnedRangeMismatchedData(): void
    {
        // Pinned 0..8 over data reaching only 5: labels still ride the 0..8
        // domain (the pins govern the plot), and every rung lands in-range.
        $view = LineChart::new([1, 5], 20, 8)
            ->withMin(0.0)
            ->withMax(8.0)
            ->withAxes(true)
            ->view();
        $this->assertStringContainsString('8 │', $view);
        $this->assertStringNotContainsString('5.6667', $view);
    }
}
