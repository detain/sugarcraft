<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Timeline;
use SugarCraft\Dash\Grid\TimelineNode;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class TimelineTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTimelineImplementsSizer(): void
    {
        $timeline = Timeline::new();
        $this->assertInstanceOf(Sizer::class, $timeline);
    }

    public function testTimelineImplementsItem(): void
    {
        $timeline = Timeline::new();
        $this->assertInstanceOf(Item::class, $timeline);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyTimelineRendersEmpty(): void
    {
        $timeline = Timeline::new();
        $rendered = $timeline->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderWithSingleNode(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1'),
        ]);
        $rendered = $timeline->render();

        $this->assertStringContainsString('Event 1', $rendered);
        $this->assertStringContainsString('●', $rendered);
    }

    public function testRenderWithMultipleNodes(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1'),
            TimelineNode::create('Event 2'),
            TimelineNode::create('Event 3'),
        ]);
        $rendered = $timeline->render();

        $this->assertStringContainsString('Event 1', $rendered);
        $this->assertStringContainsString('Event 2', $rendered);
        $this->assertStringContainsString('Event 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Node character
    // ═══════════════════════════════════════════════════════════════

    public function testCustomNodeChar(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Test'),
        ])->withNodeChar('◆');

        $rendered = $timeline->render();

        $this->assertStringContainsString('◆', $rendered);
    }

    public function testConnectorChar(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1'),
            TimelineNode::create('Event 2'),
        ])->withConnectorChar('│');

        $rendered = $timeline->render();

        $this->assertStringContainsString('│', $rendered);
    }

    public function testAlternativeConnectorChar(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1'),
            TimelineNode::create('Event 2'),
        ])->withConnectorChar(':');

        $rendered = $timeline->render();

        $this->assertStringContainsString(':', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Descriptions
    // ═══════════════════════════════════════════════════════════════

    public function testNodeWithDescription(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1', 'This is a description'),
        ]);
        $rendered = $timeline->render();

        $this->assertStringContainsString('Event 1', $rendered);
        $this->assertStringContainsString('This is a description', $rendered);
    }

    public function testNodeWithMultilineDescription(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1', "Line 1\nLine 2\nLine 3"),
        ]);
        $rendered = $timeline->render();

        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 2', $rendered);
        $this->assertStringContainsString('Line 3', $rendered);
    }

    public function testNodeWithNullDescription(): void
    {
        $timeline = Timeline::fromNodes([
            new TimelineNode('Event 1', null),
        ]);
        $rendered = $timeline->render();

        $this->assertStringContainsString('Event 1', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testNodeColorAddsAnsiCodes(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Test'),
        ])->withNodeColor(Color::ansi(9));

        $rendered = $timeline->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testConnectorColorAddsAnsiCodes(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1'),
            TimelineNode::create('Event 2'),
        ])->withConnectorColor(Color::ansi(9));

        $rendered = $timeline->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testContentColorAddsAnsiCodes(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Test'),
        ])->withContentColor(Color::ansi(9));

        $rendered = $timeline->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Test'),
        ])->withNodeColor(Color::ansi(9));

        $rendered = $timeline->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Timeline::new();
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Fluent API / withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithNodesReturnsNewInstance(): void
    {
        $original = Timeline::new();
        $updated = $original->withNodes([
            TimelineNode::create('Test'),
        ]);

        $this->assertNotSame($original, $updated);
    }

    public function testAddNodeReturnsNewInstance(): void
    {
        $original = Timeline::fromNodes([
            TimelineNode::create('Event 1'),
        ]);
        $updated = $original->addNode(TimelineNode::create('Event 2'));

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Event 1', $original->render());
        $this->assertStringContainsString('Event 2', $updated->render());
    }

    public function testWithNodeColorReturnsNewInstance(): void
    {
        $original = Timeline::new();
        $updated = $original->withNodeColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithConnectorColorReturnsNewInstance(): void
    {
        $original = Timeline::new();
        $updated = $original->withConnectorColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeEmptyTimeline(): void
    {
        $timeline = Timeline::new();
        [$w, $h] = $timeline->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeWithNodes(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1'),
            TimelineNode::create('Event 2'),
        ]);
        [$w, $h] = $timeline->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(2, $h);
    }

    public function testGetInnerSizeWithDescriptions(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('Event 1', "Line 1\nLine 2"),
        ]);
        [, $h] = $timeline->getInnerSize();

        // 1 line for node + 1 extra for second line of description
        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // TimelineNode tests
    // ═══════════════════════════════════════════════════════════════

    public function testTimelineNodeCreate(): void
    {
        $node = TimelineNode::create('Label', 'Description');

        $this->assertSame('Label', $node->label);
        $this->assertSame('Description', $node->description);
    }

    public function testTimelineNodeCreateWithNullDescription(): void
    {
        $node = TimelineNode::create('Label');

        $this->assertSame('Label', $node->label);
        $this->assertNull($node->description);
    }

    public function testTimelineNodeWithMultiLineDescription(): void
    {
        $node = TimelineNode::withMultiLineDescription('Label', ['Line 1', 'Line 2', 'Line 3']);

        $this->assertSame('Label', $node->label);
        $this->assertSame("Line 1\nLine 2\nLine 3", $node->description);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabel(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create(str_repeat('x', 100)),
        ]);
        $rendered = $timeline->render();

        $this->assertStringContainsString('x', $rendered);
    }

    public function testUnicodeLabel(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create('日本語'),
        ]);
        $rendered = $timeline->render();

        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testEmptyLabel(): void
    {
        $timeline = Timeline::fromNodes([
            TimelineNode::create(''),
        ]);
        $rendered = $timeline->render();

        $this->assertNotSame('', $rendered);
    }
}
