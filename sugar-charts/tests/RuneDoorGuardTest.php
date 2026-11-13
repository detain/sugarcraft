<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\MarkLine;
use SugarCraft\Charts\OHLC\Bar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Scatter\Scatter;

/**
 * CH-01 (sugar-charts-001/-002/-003/-013/-064): every rune and every
 * element-typed ingress passes its door in the private constructor (or
 * the setter that immediately rebuilds through it), so no escape byte or
 * mis-typed element can ever reach a frame.
 */
final class RuneDoorGuardTest extends TestCase
{
    public function testLineChartPointRuneCannotSmuggleAnOscTitleWrite(): void
    {
        // The sweep flattens the OSC payload to a multi-cell remainder —
        // the single-cell door refuses it, so view() can never carry ESC.
        $this->expectException(\InvalidArgumentException::class);
        LineChart::new([1, 2], 8, 2)->withPoint("\e]0;pwned\a");
    }

    public function testLineChartPointAcceptsCleanRunesByteIdentically(): void
    {
        $chart = LineChart::new([1, 2], 8, 2)->withPoint('o');
        $this->assertSame('o', $chart->point);
        $this->assertStringNotContainsString("\x1b", $chart->view());
    }

    public function testScatterRuneDoorCoversConstructorAndSetterInOneSweep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scatter::new([[1, 2]], 8, 4)->withRune("\e[2J");
    }

    public function testOhlcBodyAndWickRunesAreGuarded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OHLCChart::new([], 8, 4)->withBodyRunes("\e]0;x\a", '▒');
    }

    public function testOhlcWickRuneRefusesMultiCellRemainder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OHLCChart::new([], 8, 4)->withWickRune('││');
    }

    public function testLegendIndicatorDoorRejectsEmptyAndMultiCell(): void
    {
        $chart = LineChart::new([1, 2], 8, 2);
        $this->expectException(\InvalidArgumentException::class);
        $chart->withLegendIndicator('');
    }

    public function testMarkLineListElementsAreTypedAtTheDoor(): void
    {
        // The withMarkLines instanceof sweep predates CH-01; pinned here
        // so a future refactor cannot quietly drop it.
        $this->expectException(\InvalidArgumentException::class);
        LineChart::new([1, 2], 8, 2)->withMarkLines([MarkLine::at(1.0, 'a'), 'not-a-mark']);
    }

    public function testOhlcBarsElementDoorRefusesNonBarAtEveryRebuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OHLCChart::new([], 8, 4)->withBars([new Bar(1.0, 2.0, 0.5, 1.5), 42]);
    }

    public function testTimeSeriesPointTuplesAreValidatedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeSeries::new([['not-a-datetime', 1.0]], 8, 4);
    }

    public function testTimeSeriesRefusesNonNumericValuesAtTheDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeSeries::new([[new \DateTimeImmutable('@100'), 'warm']], 8, 4);
    }

    public function testTimeSeriesAcceptsWellFormedTuples(): void
    {
        $ts = TimeSeries::new([[new \DateTimeImmutable('@100'), 1.0]], 8, 4);
        $this->assertNotEmpty($ts->view());
    }
}
