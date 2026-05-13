<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Partition;
use SugarCraft\Dash\Grid\PartitionSegment;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;

final class PartitionTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPartitionImplementsSizer(): void
    {
        $partition = Partition::new();
        $this->assertInstanceOf(Sizer::class, $partition);
    }

    // ═══════════════════════════════════════════════════════════════
    // PartitionSegment
    // ═══════════════════════════════════════════════════════════════

    public function testSegmentCreation(): void
    {
        $segment = new PartitionSegment('1', 'Segment', 100);

        $this->assertSame('1', $segment->id);
        $this->assertSame('Segment', $segment->label);
        $this->assertSame(100.0, $segment->value);
        $this->assertNull($segment->color);
        $this->assertCount(0, $segment->children);
    }

    public function testSegmentWithColor(): void
    {
        $color = Color::hex('#89B4FA');
        $segment = new PartitionSegment('1', 'Segment', 100, $color);

        $this->assertSame($color, $segment->color);
    }

    public function testSegmentWithChildren(): void
    {
        $child1 = new PartitionSegment('1a', 'Child 1', 50);
        $child2 = new PartitionSegment('1b', 'Child 2', 50);
        $segment = (new PartitionSegment('1', 'Segment', 100))->withChildren([$child1, $child2]);

        $this->assertCount(2, $segment->children);
    }

    public function testSegmentGetTotalValue(): void
    {
        $child1 = new PartitionSegment('1a', 'Child 1', 50);
        $child2 = new PartitionSegment('1b', 'Child 2', 50);
        $segment = (new PartitionSegment('1', 'Segment', 100))->withChildren([$child1, $child2]);

        $this->assertSame(200.0, $segment->getTotalValue());
    }

    public function testSegmentGetDepth(): void
    {
        $grandchild = new PartitionSegment('1aa', 'Grandchild', 25);
        $child = (new PartitionSegment('1a', 'Child', 50))->withChildren([$grandchild]);
        $segment = (new PartitionSegment('1', 'Segment', 100))->withChildren([$child]);

        $this->assertSame(3, $segment->getDepth());
    }

    public function testSegmentWithColorReturnsNewInstance(): void
    {
        $segment = new PartitionSegment('1', 'Segment', 100);
        $color = Color::hex('#89B4FA');
        $withColor = $segment->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($segment->color);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesDefaultInstance(): void
    {
        $partition = Partition::new();
        $this->assertInstanceOf(Partition::class, $partition);
    }

    public function testRenderReturnsEmptyWithNoRoot(): void
    {
        $partition = Partition::new();
        $this->assertSame('', $partition->render());
    }

    public function testRenderReturnsNonEmptyWithRoot(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertMatchesRegularExpression('/[╭╮╰╯│─]/', $rendered);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root)->setSize(15, 5);

        $this->assertSame('', $partition->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample creation
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesValidTree(): void
    {
        $partition = Partition::sample();

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowLabels(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root);

        $result = $partition->withShowLabels(false);
        $this->assertInstanceOf(Partition::class, $result);
    }

    public function testWithShowValues(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root);

        $result = $partition->withShowValues(true);
        $this->assertInstanceOf(Partition::class, $result);
    }

    public function testWithHorizontal(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root);

        $result = $partition->withHorizontal(false);
        $this->assertInstanceOf(Partition::class, $result);
    }

    public function testWithStyle(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root);

        $result = $partition->withStyle('bold');
        $this->assertInstanceOf(Partition::class, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testBorderStyles(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $styles = ['rounded', 'single', 'double', 'bold', 'empty'];

        foreach ($styles as $style) {
            $partition = Partition::new()
                ->withRoot($root)
                ->withStyle($style)
                ->setSize(60, 20);

            $rendered = $partition->render();
            $this->assertNotSame('', $rendered, "Style '$style' should render");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root);

        $result = $partition->setSize(60, 20);
        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSize(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        [$w, $h] = $partition->getInnerSize();
        $this->assertSame(60, $w);
        $this->assertSame(20, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithSegmentColor(): void
    {
        $partition = Partition::new();
        $result = $partition->withSegmentColor(Color::hex('#FF0000'));
        $this->assertInstanceOf(Partition::class, $result);
    }

    public function testWithBorderColor(): void
    {
        $partition = Partition::new();
        $result = $partition->withBorderColor(Color::hex('#00FF00'));
        $this->assertInstanceOf(Partition::class, $result);
    }

    public function testWithTextColor(): void
    {
        $partition = Partition::new();
        $result = $partition->withTextColor(Color::hex('#0000FF'));
        $this->assertInstanceOf(Partition::class, $result);
    }

    public function testWithersReturnNewInstance(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $original = Partition::new()->withRoot($root);
        $updated = $original->withSegmentColor(Color::hex('#FF0000'));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Hierarchy rendering
    // ═══════════════════════════════════════════════════════════════

    public function testSingleNode(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testTwoLevels(): void
    {
        $child = new PartitionSegment('1a', 'Child', 50);
        $root = (new PartitionSegment('1', 'Root', 100))->withChildren([$child]);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testThreeLevels(): void
    {
        $grandchild = new PartitionSegment('1aa', 'Grandchild', 25);
        $child = (new PartitionSegment('1a', 'Child', 50))->withChildren([$grandchild]);
        $root = (new PartitionSegment('1', 'Root', 100))->withChildren([$child]);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testMultipleChildren(): void
    {
        $child1 = new PartitionSegment('1a', 'Child A', 33);
        $child2 = new PartitionSegment('1b', 'Child B', 33);
        $child3 = new PartitionSegment('1c', 'Child C', 34);
        $root = (new PartitionSegment('1', 'Root', 100))->withChildren([$child1, $child2, $child3]);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testVerticalOrientation(): void
    {
        $child = new PartitionSegment('1a', 'Child', 50);
        $root = (new PartitionSegment('1', 'Root', 100))->withChildren([$child]);
        $partition = Partition::new()->withRoot($root)->withHorizontal(false)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()
            ->withRoot($root)
            ->withSegmentColor(Color::ansi(12));

        $rendered = $partition->render();
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSegmentWithSpecificColor(): void
    {
        $segment = new PartitionSegment('1', 'Root', 100, Color::hex('#FF0000'));
        $partition = Partition::new()->withRoot($segment)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testZeroValueSegment(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testVerySmallChildSegment(): void
    {
        $child = new PartitionSegment('1a', 'Child', 1);
        $root = (new PartitionSegment('1', 'Root', 100))->withChildren([$child]);
        $partition = Partition::new()->withRoot($root)->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }

    public function testShowValuesShowsValues(): void
    {
        $root = new PartitionSegment('1', 'Root', 100);
        $partition = Partition::new()
            ->withRoot($root)
            ->withShowValues(true)
            ->setSize(60, 20);

        $rendered = $partition->render();
        $this->assertNotSame('', $rendered);
    }
}
