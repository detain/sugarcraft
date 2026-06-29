<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shine\Render\BlockContext;
use SugarCraft\Shine\Render\BlockKind;
use SugarCraft\Shine\Render\BlockStack;
use SugarCraft\Sprinkles\Style;

final class BlockStackTest extends TestCase
{
    public function testPushPopSequence(): void
    {
        $stack = new BlockStack();
        $this->assertTrue($stack->isEmpty());
        $this->assertNull($stack->peek());
        $this->assertNull($stack->peekKind());

        $ctx = new BlockContext(BlockKind::Document, 0, 0, Style::new());
        $stack->push($ctx);

        $this->assertFalse($stack->isEmpty());
        $this->assertSame($ctx, $stack->peek());
        $this->assertSame(BlockKind::Document, $stack->peekKind());
        $this->assertSame(1, $stack->depth());

        $popped = $stack->pop();
        $this->assertSame($ctx, $popped);
        $this->assertTrue($stack->isEmpty());
    }

    public function testPopEmptyThrowsUnderflow(): void
    {
        $stack = new BlockStack();
        $this->expectException(\UnderflowException::class);
        $stack->pop();
    }

    public function testAccumulatedIndentSumsAllContexts(): void
    {
        $stack = new BlockStack();

        // Document at indent 0.
        $stack->push(new BlockContext(BlockKind::Document, 0, 0, Style::new()));
        $this->assertSame(0, $stack->accumulatedIndent());

        // BlockQuote adds 2.
        $stack->push(new BlockContext(BlockKind::BlockQuote, 1, 2, Style::new()));
        $this->assertSame(2, $stack->accumulatedIndent());

        // Nested BlockQuote adds another 2 = 4 total.
        $stack->push(new BlockContext(BlockKind::BlockQuote, 2, 2, Style::new()));
        $this->assertSame(4, $stack->accumulatedIndent());

        // Paragraph adds 0, so still 4.
        $stack->push(new BlockContext(BlockKind::Paragraph, 3, 0, Style::new()));
        $this->assertSame(4, $stack->accumulatedIndent());
    }

    public function testMarginCountCountsBlockQuotesAndListItems(): void
    {
        $stack = new BlockStack();

        $this->assertSame(0, $stack->marginCount());

        $stack->push(new BlockContext(BlockKind::Document, 0, 0, Style::new()));
        $this->assertSame(0, $stack->marginCount());

        // BlockQuote adds to margin count.
        $stack->push(new BlockContext(BlockKind::BlockQuote, 1, 2, Style::new()));
        $this->assertSame(1, $stack->marginCount());

        // ListItem also adds.
        $stack->push(new BlockContext(BlockKind::ListItem, 2, 0, Style::new()));
        $this->assertSame(2, $stack->marginCount());

        // Paragraph does not.
        $stack->push(new BlockContext(BlockKind::Paragraph, 3, 0, Style::new()));
        $this->assertSame(2, $stack->marginCount());
    }

    public function testAvailableWidthComputesCorrectly(): void
    {
        $stack = new BlockStack();

        // No blocks: available width = wordWrap - 0 - 0*2 = 80.
        $this->assertSame(80, $stack->availableWidth(80));

        // Add BlockQuote (adds 2 indent + 1 margin).
        $stack->push(new BlockContext(BlockKind::BlockQuote, 1, 2, Style::new()));
        // availableWidth = 80 - 2 - 1*2 = 76.
        $this->assertSame(76, $stack->availableWidth(80));

        // Add nested BlockQuote (adds 2 more indent, 1 more margin).
        $stack->push(new BlockContext(BlockKind::BlockQuote, 2, 2, Style::new()));
        // availableWidth = 80 - 4 - 2*2 = 72.
        $this->assertSame(72, $stack->availableWidth(80));

        // Add Paragraph (no indent, no margin).
        $stack->push(new BlockContext(BlockKind::Paragraph, 3, 0, Style::new()));
        // availableWidth = 80 - 4 - 2*2 = 72 (same as before).
        $this->assertSame(72, $stack->availableWidth(80));
    }

    public function testAvailableWidthNeverDropsBelowOne(): void
    {
        $stack = new BlockStack();

        // Force narrow width with deep nesting.
        $stack->push(new BlockContext(BlockKind::BlockQuote, 1, 2, Style::new()));
        $stack->push(new BlockContext(BlockKind::BlockQuote, 2, 2, Style::new()));
        $stack->push(new BlockContext(BlockKind::BlockQuote, 3, 2, Style::new()));
        $stack->push(new BlockContext(BlockKind::BlockQuote, 4, 2, Style::new()));

        // 10 - 8 - 4*2 = -6, but we clamp to 1.
        $this->assertSame(1, $stack->availableWidth(10));
    }

    public function testDepthTracksNonDocumentBlocks(): void
    {
        $stack = new BlockStack();

        $stack->push(new BlockContext(BlockKind::Document, 0, 0, Style::new()));
        $this->assertSame(1, $stack->depth());

        $stack->push(new BlockContext(BlockKind::Paragraph, 1, 0, Style::new()));
        $this->assertSame(2, $stack->depth());

        $stack->pop();
        $stack->pop();
        $this->assertSame(0, $stack->depth());
    }
}
