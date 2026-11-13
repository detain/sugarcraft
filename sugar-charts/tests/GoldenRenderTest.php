<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use DateTimeImmutable;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\Charts\Buffer\BufferHelper;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\OHLC\Candle as OhlcBar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Picture\Protocol;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output.
 *
 * These tests capture the byte-exact output of render() methods
 * to detect unintended changes to terminal output.
 *
 * CH-49 expanded the corpus from Sparkline-only to EVERY renderer plus
 * the three Picture wire protocols and a bordered-legend cell-grid
 * placement pin (sugar-charts-022): all fixtures are fixed literals —
 * zero RNG, zero clock, zero env reads beyond the color ladder's own
 * gate semantics — so goldens bake the same bytes on every host.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testSparklineBasicRendersAnsi(): void
    {
        $s = Sparkline::new([1, 4, 2, 7, 5])->withWidth(5);
        $output = $s->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/sparkline-basic.golden',
            $output,
        );
    }

    public function testSparklineWithStyleRendersAnsi(): void
    {
        $s = Sparkline::new([1, 4, 2, 7, 5, 3])
            ->withWidth(6)
            ->withStyle(Style::new()->fg('196'));

        $output = $s->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/sparkline-styled.golden',
            $output,
        );
    }

    public function testSparklineWithExplicitMinMaxRendersAnsi(): void
    {
        $s = Sparkline::new([10, 20, 15, 25])
            ->withWidth(4)
            ->withMin(0)
            ->withMax(100);

        $output = $s->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/sparkline-minmax.golden',
            $output,
        );
    }

    // ---------------------------------------------------------------
    // CH-49a — grid-renderer goldens (every renderer, fixed fixtures)
    // ---------------------------------------------------------------

    public function testGoldenBar(): void
    {
        $chart = BarChart::new([['mon', 3], ['tue', 7], ['wed', 5], ['thu', 9]], 24, 6);
        $this->assertNotEmpty($chart->view());

        $bytes = $chart->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-bar.golden', $bytes);
    }

    public function testGoldenLine(): void
    {
        $chart = LineChart::new([1.0, 5.0, 3.0, 7.0, 2.0, 6.0], 30, 10)
            ->withAxes(true)
            ->withDataset('series-1', [3.0, 1.0, 4.0, 1.0, 5.0, 9.0]);

        $bytes = $chart->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-line.golden', $bytes);
    }

    public function testGoldenHeatmap(): void
    {
        $chart = Heatmap::new([[1, 2, 3, 4], [5, 6, 7, 8], [3, 1, 4, 2], [8, 6, 1, 5]]);

        $bytes = $chart->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-heatmap.golden', $bytes);
    }

    public function testGoldenScatter(): void
    {
        $chart = Scatter::new([[1, 2], [3, 7], [5, 4], [2, 5], [7, 6]], 24, 8);

        $bytes = $chart->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-scatter.golden', $bytes);
    }

    public function testGoldenOhlc(): void
    {
        $chart = OHLCChart::new(
            [new OhlcBar(1.0, 4.0, 0.5, 2.0), new OhlcBar(2.0, 5.0, 1.5, 3.0), new OhlcBar(3.0, 6.0, 2.5, 4.0)],
            24,
            8,
        );

        $bytes = $chart->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-ohlc.golden', $bytes);
    }

    public function testGoldenTimeSeries(): void
    {
        $chart = TimeSeries::new(
            [
                [new DateTimeImmutable('@0'), 1.0],
                [new DateTimeImmutable('@3600'), 4.0],
                [new DateTimeImmutable('@7200'), 2.0],
                [new DateTimeImmutable('@10800'), 5.0],
            ],
            30,
            10,
        );

        $bytes = $chart->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-timeseries.golden', $bytes);
    }

    // ---------------------------------------------------------------
    // CH-49b — protocol fixed-frame goldens (deterministic payloads)
    // ---------------------------------------------------------------

    /** @return list<list<Color>> index-arithmetic gradient, zero RNG */
    private static function protocolPixels(): array
    {
        $grid = [];
        for ($row = 0; $row < 8; $row++) {
            $line = [];
            for ($col = 0; $col < 12; $col++) {
                $line[] = Color::rgb(
                    (($col * 37 + $row * 11) * 13) % 256,
                    (($col * 91 + $row * 17) * 7) % 256,
                    (($col * 13 + $row * 79) * 11) % 256,
                );
            }
            $grid[] = $line;
        }
        return $grid;
    }

    /** Canonical 1x1 black PNG — the fixed byte frame the wire goldens encode. */
    private static function fixedPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    public function testGoldenProtocolSixel(): void
    {
        $picture = Picture::fromGrid(self::protocolPixels())->withProtocol(Protocol::Sixel);

        $bytes = $picture->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-protocol-sixel.golden', $bytes);
    }

    public function testGoldenProtocolKitty(): void
    {
        $picture = Picture::fromPng(self::fixedPng())->withProtocol(Protocol::Kitty);

        $bytes = $picture->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-protocol-kitty.golden', $bytes);
    }

    public function testGoldenProtocolITerm2(): void
    {
        $picture = Picture::fromPng(self::fixedPng())->withProtocol(Protocol::ITerm2);

        $bytes = $picture->view();
        $this->assertNotEmpty($bytes);

        Assertions::assertGoldenAnsi($this->fixturesDir . '/golden-protocol-iterm2.golden', $bytes);
    }

    // ---------------------------------------------------------------
    // CH-49c — bordered-legend cell-grid placement
    // ---------------------------------------------------------------

    public function testBorderedLegendCellGridPlacement(): void
    {
        $legend = Legend::new([
            ['label' => 'alpha', 'color' => 'blue'],
            ['label' => 'beta',  'color' => 'red'],
        ])->withShowBorder(true);

        $view  = $legend->view();
        $plain = preg_replace('/\e\[[0-9;]*m/', '', $view) ?? '';
        $lines = explode("\n", $plain);
        $width = 0;
        foreach ($lines as $line) {
            $width = max($width, Width::string($line));
        }

        // SGR carries zero geometry (CH-35 converged both frame legs onto
        // the content width), so the placement truth lives in the plain
        // bytes; the buffer is fed the stripped stream and the rune grid
        // below pins every cell of the standalone bordered legend:
        // content row(s) + the CH-35 bottom frame line, each exactly
        // $width cells wide.
        // Buffer::fromString byte-splits multi-byte runes on this fleet
        // (sugar-cellbuffer seat — report rider), so the buffer is built
        // cell-explicit from the cluster walk instead: identical geometry,
        // honest comparison substrate.
        $cells = [];
        foreach ($lines as $line) {
            $runes = BufferHelper::clusters($line);
            foreach ($runes as $rune) {
                $cells[] = new Cell($rune, null, null, Width::of($rune));
            }
            for ($pad = count($runes); $pad < $width; $pad++) {
                $cells[] = new Cell(' ', null, null, 1);
            }
        }
        $buffer = Buffer::fromGrid($width, count($lines), $cells);

        $grid = [];
        foreach ($lines as $i => $line) {
            $this->assertSame($width, Width::string($line), "row {$i} must fill the declared width");
            $grid[$i] = $line . str_repeat(' ', $width - Width::string($line));
        }
        Assertions::assertCellGrid($grid, $buffer);

        // Directional sanity: the standalone bordered legend is content +
        // bottom frame '└──┘' (the top ┌─┐ leg is the chart chrome's
        // renderTop, asserted in LegendFrameWidthTest). A frame-glyph swap
        // or an empty grid fails here.
        $last = $lines[count($lines) - 1];
        $this->assertSame('└', mb_substr($last, 0, 1), 'bottom-left corner');
        $this->assertSame('┘', mb_substr(rtrim($last), -1), 'bottom-right corner');
        $this->assertStringContainsString('alpha', $lines[0]);
    }
}
