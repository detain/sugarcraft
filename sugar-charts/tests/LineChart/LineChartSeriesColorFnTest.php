<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;

/**
 * candy-top L1: per-point color resolver on LineChart, layered over the
 * Audit-F11 per-series legend colors.
 */
final class LineChartSeriesColorFnTest extends TestCase
{
    private const RED  = "\x1b[38;2;255;0;0m";
    private const BLUE = "\x1b[38;2;0;0;255m";

    private static function heat(): \Closure
    {
        return static fn(string $dataset, int $x, float $value): ?Color
            => $value > 50 ? Color::rgb(255, 0, 0) : Color::rgb(0, 0, 255);
    }

    public function testCellModeColorsEachPointByItsOwnValue(): void
    {
        $out = LineChart::new([10, 90, 10, 90], 4, 3)->withSeriesColorFn(self::heat())->view();

        $this->assertSame(
            ' ' . self::RED . "*\x1b[0m " . self::RED . "*\x1b[0m\n"
            . "\n"
            . self::BLUE . "*\x1b[0m " . self::BLUE . "*\x1b[0m",
            $out,
        );
    }

    public function testCellModeConnectorAndFillCarryTheStartingPointColor(): void
    {
        // One series, low → high: the connector leaving the low point is
        // blue, the fill under the high point is red.
        $out = LineChart::new([10, 90], 8, 4)
            ->withSeriesColorFn(self::heat())
            ->withFill()
            ->view();

        $this->assertStringContainsString(self::BLUE . "/\x1b[0m", $out);
        $this->assertStringContainsString(self::RED . "*\x1b[0m", $out);
        $this->assertStringContainsString(self::BLUE . "*\x1b[0m", $out);
    }

    public function testBrailleModeSgrRunsDifferWithinOneSeries(): void
    {
        $out = LineChart::new([], 20, 6)
            ->withDataset('cpu', [10, 15, 20, 80, 90, 95])
            ->withCanvas(BrailleCanvas::new(80, 24))
            ->withSeriesColorFn(self::heat())
            ->view();

        $this->assertSame(1, preg_match('/[\x{2800}-\x{28FF}]/u', $out));
        $this->assertStringContainsString(self::RED, $out, 'high samples paint red');
        $this->assertStringContainsString(self::BLUE, $out, 'low samples paint blue');
        // The series' own legend color (red cycle slot → #cd0000) is fully
        // overridden because the resolver never returns null.
        $this->assertStringNotContainsString("\x1b[38;2;205;0;0m", $out);
    }

    public function testBrailleModeNullFallsThroughToSeriesLegendColor(): void
    {
        $base = LineChart::new([], 20, 6)
            ->withDataset('cpu', [10, 15, 20, 80, 90, 95])
            ->withCanvas(BrailleCanvas::new(80, 24));
        $highOnly = static fn(string $d, int $x, float $v): ?Color => $v > 50 ? Color::rgb(255, 0, 0) : null;

        $out = $base->withSeriesColorFn($highOnly)->view();

        $this->assertStringContainsString(self::RED, $out);
        $this->assertStringContainsString("\x1b[38;2;205;0;0m", $out, 'null keeps the legend color');
    }

    public function testAlwaysNullResolverIsByteIdenticalToDefault(): void
    {
        $nil = static fn(string $d, int $x, float $v): ?Color => null;

        $cell = LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6)->withDataset('b', [7, 3, 6, 1])->withFill();
        $this->assertSame($cell->view(), $cell->withSeriesColorFn($nil)->view());

        $braille = $cell->withCanvas(BrailleCanvas::new(80, 24));
        $this->assertSame($braille->view(), $braille->withSeriesColorFn($nil)->view());
    }

    public function testNullResetRestoresDefaultRender(): void
    {
        $base = LineChart::new([1, 4, 2, 8, 6, 3, 7], 30, 6);
        $reset = $base->withSeriesColorFn(self::heat())->withSeriesColorFn(null);

        $this->assertNull($reset->seriesColorFn);
        $this->assertSame($base->view(), $reset->view());
    }

    public function testResolverReceivesSeriesNameAndFullSeriesIndex(): void
    {
        $calls = [];
        $spy = static function (string $d, int $x, float $v) use (&$calls): ?Color {
            $calls[] = [$d, $x, $v];
            return null;
        };

        // 10 samples into a 4-column plot: the tail slice keeps x = 6..9.
        LineChart::new([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], 4, 3)
            ->withDataset('net', [5.0, 6.0])
            ->withSeriesColorFn($spy)
            ->view();

        $this->assertSame([
            [LineChart::PRIMARY_SERIES, 6, 6.0],
            [LineChart::PRIMARY_SERIES, 7, 7.0],
            [LineChart::PRIMARY_SERIES, 8, 8.0],
            [LineChart::PRIMARY_SERIES, 9, 9.0],
            ['net', 0, 5.0],
            ['net', 1, 6.0],
        ], $calls);
    }

    public function testBrailleModeResolverReceivesFullSeriesIndex(): void
    {
        $calls = [];
        $spy = static function (string $d, int $x, float $v) use (&$calls): ?Color {
            $calls[] = [$d, $x, $v];
            return null;
        };

        // A 4-cell plot is 8 dot columns wide; 12 samples (> 2 × plotW)
        // tail-slice to the last 8, so x must run 4..11 — the full-series
        // index, not the 0..7 dot-window index.
        LineChart::new(range(0, 11), 4, 3)
            ->withCanvas(BrailleCanvas::new(80, 24))
            ->withSeriesColorFn($spy)
            ->view();

        $expected = [];
        for ($x = 4; $x <= 11; $x++) {
            $expected[] = [LineChart::PRIMARY_SERIES, $x, (float) $x];
        }
        $this->assertSame($expected, $calls);
    }

    public function testNonColorReturnIsRejectedAtRender(): void
    {
        $bad = static fn(string $d, int $x, float $v): mixed => 'red';
        $chart = LineChart::new([1, 2, 3], 10, 4)->withSeriesColorFn($bad);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('string given');
        $chart->view();
    }

    public function testWithSeriesColorFnIsImmutableAndPreservesOtherState(): void
    {
        $base = LineChart::new([1, 2, 3], 10, 4)->withAxes()->withDataset('x', [3, 2, 1]);
        $fn = self::heat();
        $next = $base->withSeriesColorFn($fn);

        $this->assertNotSame($base, $next);
        $this->assertNull($base->seriesColorFn);
        $this->assertSame($fn, $next->seriesColorFn);
        $this->assertTrue($next->showAxes);
        $this->assertSame($base->datasets, $next->datasets);
        // Survives an unrelated later with*().
        $this->assertSame($fn, $next->withFill()->seriesColorFn);
    }

    public function testShortAliasMatchesLongForm(): void
    {
        $base = LineChart::new([10, 90, 10, 90], 4, 3);
        $this->assertSame(
            $base->withSeriesColorFn(self::heat())->view(),
            $base->seriesColorFn(self::heat())->view(),
        );
    }
}
