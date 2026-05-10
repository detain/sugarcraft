<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Chart;

use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\Chart\Position;
use PHPUnit\Framework\TestCase;

/**
 * Concrete Chart implementation for testing the base Chart class.
 */
final class TestChart extends Chart
{
    public static function new(int $width = 40, int $height = 8): self
    {
        return new self(
            width: $width,
            height: $height,
            showLegend: false,
            legendPosition: Position::Right,
            legendIndicatorChar: null,
            title: null,
            titlePosition: Position::Top,
            xLabel: null,
            yLabel: null,
            showDataLabels: false,
            dataLabelFormatter: null,
            legendItems: [],
        );
    }

    protected function renderChart(): string
    {
        $lines = [];
        for ($i = 0; $i < $this->height; $i++) {
            $lines[] = str_repeat('█', $this->width);
        }
        return implode("\n", $lines);
    }

    /**
     * Expose protected copy() for testing.
     */
    public function testCopy(
        ?int $width = null,
        ?int $height = null,
        ?bool $showLegend = null,
        ?Position $legendPosition = null,
        ?string $legendIndicatorChar = null,
        ?string $title = null,
        ?Position $titlePosition = null,
        ?string $xLabel = null,
        ?string $yLabel = null,
        ?bool $showDataLabels = null,
        ?\Closure $dataLabelFormatter = null,
        ?array $legendItems = null,
    ): self {
        return $this->copy(
            width: $width,
            height: $height,
            showLegend: $showLegend,
            legendPosition: $legendPosition,
            legendIndicatorChar: $legendIndicatorChar,
            title: $title,
            titlePosition: $titlePosition,
            xLabel: $xLabel,
            yLabel: $yLabel,
            showDataLabels: $showDataLabels,
            dataLabelFormatter: $dataLabelFormatter,
            legendItems: $legendItems,
        );
    }
}

final class ChartTest extends TestCase
{
    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestChart::new(-1, 5);
    }

    public function testEmptyChartRendersChartContent(): void
    {
        $chart = TestChart::new(10, 3);
        $out = $chart->view();
        $this->assertStringContainsString('█', $out);
    }

    public function testWithLegendEnablesLegend(): void
    {
        $chart = TestChart::new(10, 3)
            ->legendItems([['label' => 'Series A', 'color' => 'red']])
            ->withLegend(true);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testWithLegendFalseDisablesLegend(): void
    {
        $chart = TestChart::new(10, 3)
            ->legendItems([['label' => 'Should Not Appear', 'color' => 'red']])
            ->withLegend(false);
        $out = $chart->view();
        $this->assertStringNotContainsString('Should Not Appear', $out);
    }

    public function testWithLegendPositionChangesPosition(): void
    {
        $chart = TestChart::new(10, 3)
            ->legendItems([['label' => 'A', 'color' => 'red']])
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom);
        $out = $chart->view();
        $this->assertStringContainsString('A', $out);
    }

    public function testWithLegendStyleCustomizesIndicator(): void
    {
        $chart = TestChart::new(10, 3)
            ->legendItems([['label' => 'A', 'color' => 'red']])
            ->withLegend(true)
            ->withLegendStyle('●');
        $out = $chart->view();
        $this->assertStringContainsString('●', $out);
    }

    public function testWithTitleAddsTitle(): void
    {
        $chart = TestChart::new(10, 3)
            ->withTitle('My Chart Title');
        $out = $chart->view();
        $this->assertStringContainsString('My Chart Title', $out);
    }

    public function testWithTitleAndPositionBottom(): void
    {
        $chart = TestChart::new(10, 3)
            ->withTitle('Bottom Title', Position::Bottom);
        $out = $chart->view();
        $this->assertStringContainsString('Bottom Title', $out);
    }

    public function testWithXLabelAddsLabel(): void
    {
        $chart = TestChart::new(10, 3)
            ->withXLabel('X Axis Label');
        $out = $chart->view();
        // X label is appended at bottom of chart lines
        $this->assertStringContainsString('X Axis Label', $out);
    }

    public function testWithYLabelAddsLabel(): void
    {
        $chart = TestChart::new(10, 3)
            ->withYLabel('Y Axis Label');
        $out = $chart->view();
        // Y label is prepended to each line of the chart
        $this->assertStringContainsString('Y Axis Label', $out);
    }

    public function testWithDataLabelsEnablesDataLabels(): void
    {
        $chart = TestChart::new(10, 3)
            ->withDataLabels(true);
        $out = $chart->view();
        // Should still render chart content
        $this->assertStringContainsString('█', $out);
    }

    public function testWithDataLabelFormatterSetsFormatter(): void
    {
        $chart = TestChart::new(10, 3)
            ->withDataLabelFormatter(fn($v) => number_format($v, 2));
        $out = $chart->view();
        $this->assertStringContainsString('█', $out);
    }

    public function testFluentInterfaceChaining(): void
    {
        $chart = TestChart::new(10, 3)
            ->legendItems([['label' => 'Series A', 'color' => 'red']])
            ->withLegend(true)
            ->withLegendPosition(Position::Top)
            ->withTitle('Test Chart')
            ->withXLabel('X')
            ->withYLabel('Y')
            ->withDataLabels(true);

        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('Test Chart', $out);
        $this->assertStringContainsString('X', $out);
        $this->assertStringContainsString('Y', $out);
    }

    public function testShortFormAliases(): void
    {
        $chart = TestChart::new(10, 3)
            ->legendItems([['label' => 'A', 'color' => 'blue']])
            ->legend(true)
            ->legendPos(Position::Bottom)
            ->xLabel('X Axis')
            ->yLabel('Y Axis')
            ->dataLabels(true);

        $out = $chart->view();
        $this->assertStringContainsString('A', $out);
        $this->assertStringContainsString('X Axis', $out);
        $this->assertStringContainsString('Y Axis', $out);
    }

    public function testToStringMagicMethod(): void
    {
        $chart = TestChart::new(10, 3);
        $this->assertSame($chart->view(), (string) $chart);
    }

    public function testLegendWithMultipleItems(): void
    {
        $chart = TestChart::new(10, 3)
            ->legendItems([
                ['label' => 'Series A', 'color' => 'red'],
                ['label' => 'Series B', 'color' => 'green'],
                ['label' => 'Series C', 'color' => 'blue'],
            ])
            ->withLegend(true);

        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('Series B', $out);
        $this->assertStringContainsString('Series C', $out);
    }

    public function testCopyPreservesUnmodifiedValues(): void
    {
        $chart = TestChart::new(10, 3)
            ->withTitle('Original Title')
            ->withXLabel('X Label');

        $modified = $chart->testCopy(title: 'New Title');

        $this->assertSame('New Title', $modified->title);
        $this->assertSame('X Label', $modified->xLabel);
    }
}
