<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Tree\OrgChart;
use SugarCraft\Dash\Components\Tree\OrgChartNode;
use SugarCraft\Dash\Components\Tree\OrgChartStyle;
use SugarCraft\Core\Util\Color;

final class OrgChartTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $orgChart = OrgChart::new();
        $this->assertInstanceOf(OrgChart::class, $orgChart);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $orgChart = OrgChart::new();
        $result = $orgChart->setSize(65, 12);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $orgChart = OrgChart::new()->setSize(65, 12);
        $rendered = $orgChart->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $orgChart = OrgChart::new()->setSize(65, 12);
        $rendered = $orgChart->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithRootByName(): void
    {
        $orgChart = OrgChart::new();
        $result = $orgChart->withRootByName('John Doe', 'CEO');
        $this->assertInstanceOf(OrgChart::class, $result);
    }

    public function testWithReport(): void
    {
        $orgChart = OrgChart::new();
        $result = $orgChart->withReport('Jane Smith', 'CTO', 'Engineering');
        $this->assertInstanceOf(OrgChart::class, $result);
    }

    public function testWithCollapsed(): void
    {
        $orgChart = OrgChart::new();
        $result = $orgChart->withCollapsed(true);
        $this->assertInstanceOf(OrgChart::class, $result);
    }

    public function testWithStyle(): void
    {
        $orgChart = OrgChart::new();
        $result = $orgChart->withStyle(OrgChartStyle::LeftRight);
        $this->assertInstanceOf(OrgChart::class, $result);
    }

    public function testWithBorderStyle(): void
    {
        $orgChart = OrgChart::new();
        $result = $orgChart->withBorderStyle('bold');
        $this->assertInstanceOf(OrgChart::class, $result);
    }

    public function testOrgChartNodeWithReport(): void
    {
        $ceo = new OrgChartNode('CEO');
        $cto = new OrgChartNode('CTO', 'Chief Technology Officer');
        $result = $ceo->withReport($cto);
        $this->assertInstanceOf(OrgChartNode::class, $result);
        $this->assertCount(1, $result->reports);
    }

    public function testOrgChartNodeFluentReportAddition(): void
    {
        $ceo = new OrgChartNode('CEO');
        $result = $ceo->withReportByName('CTO', 'Chief Technology Officer')
                      ->withReportByName('CFO', 'Chief Financial Officer');
        $this->assertCount(2, $result->reports);
    }

    public function testGetInnerSize(): void
    {
        $orgChart = OrgChart::new()->setSize(65, 12);
        $size = $orgChart->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(65, $size[0]);
        $this->assertEquals(12, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $orgChart = OrgChart::new()->setSize(5, 5);
        $rendered = $orgChart->render();
        $this->assertSame('', $rendered);
    }
}
