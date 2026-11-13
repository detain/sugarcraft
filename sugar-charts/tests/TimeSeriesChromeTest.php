<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Style\Theme;

/**
 * CH-05 (sugar-charts-006): TimeSeries forwards chrome onto the wrapped
 * LineChart instead of dropping it on the floor. Each forwarded knob must
 * be observable in view(), survive state rebuilds (push/withPoints), and
 * leave the chrome-free path byte-identical to the old pipeline.
 */
final class TimeSeriesChromeTest extends TestCase
{
    /** @return list<array{0:DateTimeImmutable,1:float}> */
    private static function points(): array
    {
        $base = new DateTimeImmutable('2026-01-01 12:00:00');
        $out  = [];
        for ($i = 0; $i < 5; $i++) {
            $out[] = [$base->modify("+$i hours"), (float) ($i % 3)];
        }
        return $out;
    }

    public function testTitledTimePlotRendersRealTitleBytes(): void
    {
        $out = TimeSeries::new(self::points(), 30, 8)
            ->withTitle('Uptime')
            ->view();
        $this->assertStringContainsString('Uptime', $out);
    }

    public function testChromeSurvivesStateRebuilds(): void
    {
        $ts = TimeSeries::new(self::points(), 30, 8)->withTitle('Uptime');
        // push() rebuilds the tuple — the forwarded chrome must ride along.
        $after = $ts->push(new DateTimeImmutable('2026-01-01 20:00:00'), 2.0);
        $this->assertStringContainsString('Uptime', $after->view());
        $swapped = $ts->withPoints([[new DateTimeImmutable('2026-02-01 00:00:00'), 1.0]]);
        $this->assertStringContainsString('Uptime', $swapped->view());
    }

    public function testTimeSeriesStaysImmutableUnderForwarding(): void
    {
        $ts  = TimeSeries::new(self::points(), 30, 8);
        $ts2 = $ts->withTitle('Uptime');
        $this->assertStringNotContainsString('Uptime', $ts->view());
        $this->assertStringContainsString('Uptime', $ts2->view());
    }

    public function testYRangeForwardingClampsThePlot(): void
    {
        $ts = TimeSeries::new(self::points(), 30, 8);
        $plain = $ts->view();
        $clamped = $ts->withYRange(0.0, 50.0)->view();
        $this->assertNotSame($plain, $clamped, 'withYRange must change the rendered frame');
        $this->assertSame([0.0, 50.0], [
            $ts->withYRange(0.0, 50.0)->innerChart()->min,
            $ts->withYRange(0.0, 50.0)->innerChart()->max,
        ]);
    }

    public function testMinMaxForwardingMirrorsYRange(): void
    {
        $ts     = TimeSeries::new(self::points(), 30, 8);
        $viaRange = $ts->withYRange(0.0, 50.0)->view();
        $viaEnds  = $ts->withMin(0.0)->withMax(50.0)->view();
        $this->assertSame($viaRange, $viaEnds);
    }

    public function testThemeLegendAndItemsForward(): void
    {
        $out = TimeSeries::new(self::points(), 30, 8)
            ->withTheme(Theme::ansi())
            ->withLegendItems([['label' => 'S1', 'color' => '#ff0000']])
            ->withLegend(true)
            ->view();
        $this->assertStringContainsString('S1', $out);
        // Colors survive into the frame (no bare-Glyph unstyled fallback).
        $this->assertMatchesRegularExpression('/\e\[[0-9;]*m/', $out);
    }

    public function testLegendPositionAndSizeAndLabelsForward(): void
    {
        $ts = TimeSeries::new(self::points(), 30, 8);
        $left = $ts->withLegendItems([['label' => 'S1', 'color' => '#ff0000']])
            ->withLegend(true)
            ->withLegendPosition(Position::Left)
            ->view();
        $this->assertNotSame($ts->view(), $left);

        $grown = $ts->withSize(60, 12)->view();
        $this->assertGreaterThan(substr_count($ts->view(), "\n"), substr_count($grown, "\n"));

        $labelled = $ts->withYLabels(['aaa', 'bbb', 'ccc'])->view();
        $this->assertStringContainsString('aaa', $labelled);
    }

    public function testPointRuneAndLineStyleAndColorFnForward(): void
    {
        $ts = TimeSeries::new(self::points(), 30, 8);
        $rune = $ts->withPoint('●')->view();
        $this->assertStringContainsString('●', $rune);
        $this->assertStringNotContainsString('●', $ts->view());

        $styled = $ts->withLineStyle(\SugarCraft\Charts\Canvas\Graph::LINE_ROUNDED)->view();
        $this->assertNotSame($ts->view(), $styled);

        $colored = $ts->withSeriesColorFn(
            static fn(string $name, int $i, float $v): ?\SugarCraft\Core\Util\Color => \SugarCraft\Core\Util\Color::rgb(255, 0, 0)
        )->view();
        $this->assertMatchesRegularExpression('/\e\[38;2;255;0;0m/', $colored);
    }

    public function testAxesToggleForwardsWithDefaultOn(): void
    {
        $ts = TimeSeries::new(self::points(), 30, 8);
        $this->assertStringContainsString('└', $ts->view(), 'default seed keeps axes on');
        $this->assertStringNotContainsString('└', $ts->withAxes(false)->view());
    }

    public function testChromeFreeRenderIsBytePinned(): void
    {
        // Recorded 2026-11-13 at CH-06 (temporal x mapping). This replaces
        // the CH-05 "identical to legacy ordinal pipeline" equivalence pin:
        // mapping points at TIME positions is exactly what sugar-charts-009
        // demanded, so the ordinal bytes are the defect, not the contract.
        // Dense resampling of this evenly-spaced, piecewise-linear series
        // traces the same line — the pin now guards any further drift.
        $expected = "  │          ***\n" .
            "  │       ***   *\n" .
            "  │     **       **          *\n" .
            "  │  ***           *      ***\n" .
            "  │**               **  **\n" .
            "  │                   **\n" .
            "  └───────────────────────────\n" .
            "  12:00        14:00     16:00";
        $this->assertSame(
            $expected,
            TimeSeries::new(self::points(), 30, 8)->withXLabelCount(3)->withTimeFormat('H:i')->view(),
        );
    }

    public function testInnerChartExposesImmutableSnapshot(): void
    {
        $ts    = TimeSeries::new(self::points(), 30, 8)->withTitle('Uptime');
        $inner = $ts->innerChart();
        $this->assertInstanceOf(LineChart::class, $inner);
        $this->assertSame('Uptime', $inner->title);
        // Mutating the snapshot outside the wrapper does not corrupt it.
        $this->assertStringContainsString('Uptime', $ts->view());
    }
}
