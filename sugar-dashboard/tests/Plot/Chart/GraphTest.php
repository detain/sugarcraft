<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Plot\Graph\Graph;
use SugarCraft\Dashboard\Plot\Graph\GraphType;

final class GraphTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $graph = Graph::new();
        $this->assertInstanceOf(Graph::class, $graph);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $graph = Graph::new();
        $result = $graph->setSize(60, 20);
        $this->assertInstanceOf(\SugarCraft\Dashboard\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $graph = Graph::new()->setSize(60, 20);
        $rendered = $graph->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $graph = Graph::new()->setSize(60, 20);
        $rendered = $graph->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithData(): void
    {
        $graph = Graph::new();
        $result = $graph->withData([10, 20, 30, 40, 50]);
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testWithSeries(): void
    {
        $graph = Graph::new();
        $result = $graph->withSeries('Series 1', [10, 20, 30]);
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testWithLabels(): void
    {
        $graph = Graph::new();
        $result = $graph->withLabels(['Jan', 'Feb', 'Mar', 'Apr']);
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testWithValueRange(): void
    {
        $graph = Graph::new();
        $result = $graph->withValueRange(0, 100);
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testWithShowGrid(): void
    {
        $graph = Graph::new();
        $result = $graph->withShowGrid(false);
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testWithShowLegend(): void
    {
        $graph = Graph::new();
        $result = $graph->withShowLegend(false);
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testWithGraphType(): void
    {
        $graph = Graph::new();
        $result = $graph->withGraphType(GraphType::Bar);
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testWithStyle(): void
    {
        $graph = Graph::new();
        $result = $graph->withStyle('bold');
        $this->assertInstanceOf(Graph::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $graph = Graph::new()->setSize(60, 20);
        $size = $graph->innerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(60, $size[0]);
        $this->assertEquals(20, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $graph = Graph::new()->setSize(5, 5);
        $rendered = $graph->render();
        $this->assertSame('', $rendered);
    }

    /**
     * D-06 (CRITICAL): the six style withers rebuilt the object through
     * `new self(ctor-only)`, silently dropping every accumulated state — data,
     * series, labels, size, min/max, grid/legend flags. A card built as
     * Graph::new()->withData(...)->withSeries(...)->withLineColor(...) rendered
     * an EMPTY chart. Each wither must now carry the whole object forward:
     * legend entry and axis labels survive every one of them.
     */
    public function testStyleWithersPreserveAccumulatedState(): void
    {
        $base = Graph::new()
            ->setSize(60, 20)
            ->withData([10.0, 40.0, 70.0, 25.0])
            ->withLabels(['jan', 'feb', 'mar', 'apr'])
            ->withSeries('cpu', [5.0, 50.0, 90.0, 30.0]);

        $variants = [
            'withGraphType' => $base->withGraphType(GraphType::Bar),
            'withLineColor' => $base->withLineColor(\SugarCraft\Core\Util\Color::hex('#F38BA8')),
            'withGridColor' => $base->withGridColor(\SugarCraft\Core\Util\Color::hex('#A6E3A1')),
            'withTextColor' => $base->withTextColor(\SugarCraft\Core\Util\Color::hex('#CDD6F4')),
            'withBackgroundColor' => $base->withBackgroundColor(\SugarCraft\Core\Util\Color::hex('#11111B')),
            'withStyle' => $base->withStyle('bold'),
        ];

        foreach ($variants as $name => $graph) {
            $rendered = $graph->render();
            $this->assertStringContainsString('◆ cpu', $rendered, "{$name} dropped the series");
            $this->assertStringContainsString('jan', $rendered, "{$name} dropped the labels");
            $this->assertStringContainsString('●', $rendered, "{$name} dropped the plot");
            $this->assertSame([60, 20], $graph->innerSize(), "{$name} dropped the size");
        }
    }

    /** D-06: color-only withers change NOTHING but the SGR — geometry stays byte-identical. */
    public function testColorWithersLeaveGeometryIdentical(): void
    {
        $base = Graph::new()
            ->setSize(60, 20)
            ->withData([10.0, 40.0, 70.0, 25.0])
            ->withSeries('cpu', [5.0, 50.0, 90.0, 30.0]);

        $plain = self::stripSgr($base->render());
        $colored = self::stripSgr(
            $base
                ->withLineColor(\SugarCraft\Core\Util\Color::hex('#F38BA8'))
                ->withGridColor(\SugarCraft\Core\Util\Color::hex('#A6E3A1'))
                ->withTextColor(\SugarCraft\Core\Util\Color::hex('#F9E2AF'))
                ->withBackgroundColor(\SugarCraft\Core\Util\Color::hex('#11111B'))
                ->render()
        );
        $this->assertSame($plain, $colored);
    }

    /** D-06: the ctor fields are plain privates — immutability must hold from the outside. */
    public function testStyleFieldsRemainExternallyWriteProtected(): void
    {
        $graph = Graph::new();
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line intentional external write */
        $graph->style = 'double';
    }

    private static function stripSgr(string $rendered): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
    }
}