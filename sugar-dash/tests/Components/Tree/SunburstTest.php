<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Tree\Sunburst;
use SugarCraft\Dash\Components\Tree\SunburstSegment;

final class SunburstTest extends TestCase
{
    public function testNewCreatesDefaultInstance(): void
    {
        $sunburst = Sunburst::new();
        $this->assertInstanceOf(Sunburst::class, $sunburst);
    }

    public function testSetSizeReturnsSizerInterface(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->setSize(50, 25);
        $this->assertInstanceOf(\SugarCraft\Dash\Foundation\Sizer::class, $result);
    }

    public function testRenderReturnsNonEmptyString(): void
    {
        $sunburst = Sunburst::new()->setSize(50, 25);
        $rendered = $sunburst->render();
        $this->assertNotEmpty($rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $sunburst = Sunburst::new()->setSize(50, 25);
        $rendered = $sunburst->render();
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
        $this->assertStringContainsString('╰', $rendered);
        $this->assertStringContainsString('╯', $rendered);
    }

    public function testWithSegment(): void
    {
        $sunburst = Sunburst::new();
        $segment = new SunburstSegment('s1', 'Segment 1', 100);
        $result = $sunburst->withSegment($segment);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testAddSegment(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->addSegment('s1', 'Segment 1', 100);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithSegments(): void
    {
        $sunburst = Sunburst::new();
        $segments = [
            new SunburstSegment('s1', 'Segment 1', 100),
            new SunburstSegment('s2', 'Segment 2', 200),
        ];
        $result = $sunburst->withSegments($segments);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithCenterLabel(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withCenterLabel('Total');
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithShowLabels(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withShowLabels(false);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithShowValues(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withShowValues(true);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithMaxDepth(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withMaxDepth(5);
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithStyle(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withStyle('bold');
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testGetInnerSize(): void
    {
        $sunburst = Sunburst::new()->setSize(50, 25);
        $size = $sunburst->getInnerSize();
        $this->assertIsArray($size);
        $this->assertCount(2, $size);
        $this->assertEquals(50, $size[0]);
        $this->assertEquals(25, $size[1]);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $sunburst = Sunburst::new()->setSize(10, 5);
        $rendered = $sunburst->render();
        $this->assertSame('', $rendered);
    }

    public function testWithSegmentColor(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withSegmentColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithTextColor(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withTextColor(\SugarCraft\Core\Util\Color::hex('#00FF00'));
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testWithCenterColor(): void
    {
        $sunburst = Sunburst::new();
        $result = $sunburst->withCenterColor(\SugarCraft\Core\Util\Color::hex('#0000FF'));
        $this->assertInstanceOf(Sunburst::class, $result);
    }

    public function testSunburstSegmentWithChildren(): void
    {
        $parent = new SunburstSegment('p', 'Parent', 100);
        $child = new SunburstSegment('c', 'Child', 50);
        $parentWithChildren = $parent->withChildren([$child]);

        $this->assertCount(1, $parentWithChildren->children);
        $this->assertEquals('Child', $parentWithChildren->children[0]->label);
    }

    public function testSunburstSegmentWithColor(): void
    {
        $segment = new SunburstSegment('s1', 'Segment', 100);
        $colored = $segment->withColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));

        $this->assertNotNull($colored->color);
    }

    public function testSunburstSegmentGetTotalValue(): void
    {
        $parent = new SunburstSegment('p', 'Parent', 100);
        $child = new SunburstSegment('c', 'Child', 50);

        $parentWithChildren = $parent->withChildren([$child]);

        $this->assertEquals(150.0, $parentWithChildren->getTotalValue());
    }

    public function testSunburstSegmentGetTotalValueSingle(): void
    {
        $segment = new SunburstSegment('s1', 'Single', 75);
        $this->assertEquals(75.0, $segment->getTotalValue());
    }

    public function testMultipleSegmentsRender(): void
    {
        $sunburst = Sunburst::new()
            ->addSegment('s1', 'Alpha', 100)
            ->addSegment('s2', 'Beta', 200)
            ->addSegment('s3', 'Gamma', 150)
            ->setSize(50, 25);

        $rendered = $sunburst->render();
        $this->assertNotEmpty($rendered);
        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
        $this->assertStringContainsString('Gamma', $rendered);
    }

    public function testNestedSegmentsRender(): void
    {
        $child = new SunburstSegment('c1', 'Child 1', 50);
        $child2 = new SunburstSegment('c2', 'Child 2', 30);
        $parent = (new SunburstSegment('p1', 'Parent', 100))->withChildren([$child, $child2]);

        $sunburst = Sunburst::new()
            ->withSegment($parent)
            ->setSize(50, 25);

        $rendered = $sunburst->render();
        $this->assertNotEmpty($rendered);
    }
}
