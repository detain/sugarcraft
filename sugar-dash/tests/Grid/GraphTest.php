<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Graph;
use SugarCraft\Dash\Grid\GraphType;

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
        $this->assertInstanceOf(\SugarCraft\Dash\Grid\Sizer::class, $result);
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
        $size = $graph->getInnerSize();
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
}
