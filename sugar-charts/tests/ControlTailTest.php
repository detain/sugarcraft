<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\Support\UntrustedText;

/**
 * Post-control-byte text survival (sugar-charts-105).
 *
 * QA probes (lib-reviews era) saw renders drop everything AFTER a stray
 * control byte even though the guard kept it — the contradiction this
 * suite retires: the guard drops the control bytes themselves but the
 * tail must always reach the screen. Pre-CH-01 behaviour is dead and
 * these pins keep it dead: every named surface (bar labels, line-chart
 * tick labels, axis titles, legend entries, dataset names) renders the
 * full survivor text.
 */
final class ControlTailTest extends TestCase
{
    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function controlSurvivorProvider(): array
    {
        return [
            'bare ESC' => ["A\e B", 'A B'],
            'BEL' => ["A\x07B", 'AB'],
            'NUL' => ["A\x00B", 'AB'],
            'fake SGR' => ["A\e[31mB", 'AB'],
            'OSC title write' => ["A\e]0;t\x07B", 'AB'],
            'backspace' => ["A\x08B", 'AB'],
            'vertical/tab' => ["A\x0b\x0cB", 'AB'],
            'C1 NEL' => ["A\xc2\x85B", 'AB'],
        ];
    }

    /**
     * @dataProvider controlSurvivorProvider
     */
    public function testGuardKeepsTheTail(string $input, string $survivor): void
    {
        self::assertSame($survivor, UntrustedText::guard($input));
    }

    public function testBarLabelsRenderTheFullTail(): void
    {
        $view = BarChart::new([["A\e B", 5.0], ['c', 3.0]], width: 20, height: 3)->view();
        self::assertStringContainsString('A B', $view, 'the bar-label surface dropped post-control text');
    }

    public function testLineChartTickLabelsRenderTheFullTail(): void
    {
        $view = LineChart::new([1.0, 2.0], 20, 4)
            ->withAxes(true)
            ->withXLabels(["A\x07B", 'q'])
            ->view();
        self::assertStringContainsString('AB', $view, 'the tick-label surface dropped post-control text');
    }

    public function testAxisTitlesRenderTheFullTail(): void
    {
        $view = LineChart::new([1.0, 2.0], 20, 4)
            ->withXLabel("A\e[31mB")
            ->withYLabel("A\x08B")
            ->view();
        self::assertStringContainsString('AB', $view, 'the axis-title surface dropped post-control text');
    }

    public function testBareLegendRendersTheFullTail(): void
    {
        $view = Legend::new()->withItems([['label' => "A\e B", 'color' => 'red']])->view();
        self::assertStringContainsString('A B', $view, 'the legend surface dropped post-control text');
    }

    public function testDatasetNamesRenderTheFullTail(): void
    {
        $view = LineChart::new([1.0, 2.0], 30, 4)
            ->withDataset("D\x07X", [3.0, 4.0])
            ->withLegend(true)
            ->view();
        self::assertStringContainsString('DX', $view, 'the dataset-name legend entry dropped post-control text');
    }

    public function testSgrInjectionStaysNeutralisedAcrossSurfaces(): void
    {
        // Companion to the ESC-count parity guard (sugar-charts-105 is
        // about survival, not injection — but the pair must not regress
        // each other): a smuggled red must not emit SGR bytes.
        $view = BarChart::new([["r\e[31med", 5.0], ['c', 3.0]], width: 20, height: 3)->view();
        self::assertStringNotContainsString("\x1b[31m", $view, 'the smuggled red SGR leaked through sanitisation');
        self::assertStringContainsString('red', $view, 'the label text itself must survive');
    }
}
