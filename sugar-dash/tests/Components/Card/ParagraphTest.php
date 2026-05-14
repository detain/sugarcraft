<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Paragraph;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Layout\VAlign;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class ParagraphTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testParagraphImplementsSizer(): void
    {
        $p = Paragraph::new('Hello');
        $this->assertInstanceOf(Sizer::class, $p);
    }

    public function testParagraphImplementsItem(): void
    {
        $p = Paragraph::new('Hello');
        $this->assertInstanceOf(Item::class, $p);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $p = Paragraph::new('Hello World');
        $this->assertNotSame('', $p->render());
    }

    public function testRenderReturnsText(): void
    {
        $p = Paragraph::new('Hello World');
        $rendered = $p->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderWithMaxWidth(): void
    {
        $p = Paragraph::new('Hello World')->withMaxWidth(5);
        $rendered = $p->render();

        // Should contain wrapped content
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testWrapsLongText(): void
    {
        $p = Paragraph::new('one two three four five')->withMaxWidth(10);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // Each line should be <= 10 characters wide
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->assertLessThanOrEqual(10, Width::string($line));
            }
        }
    }

    public function testNoWrapWhenWidthNotSet(): void
    {
        $p = Paragraph::new('short text');
        $rendered = $p->render();

        // No newlines when not wrapped
        $this->assertSame('short text', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Leading (line spacing)
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultLeadingIsOne(): void
    {
        $p = Paragraph::new('Hello');
        $this->assertSame(1.0, $this->invokePrivate('getLeading', $p));
    }

    public function testWithLeadingReturnsNewInstance(): void
    {
        $original = Paragraph::new('text');
        $updated = $original->withLeading(1.5);

        $this->assertNotSame($original, $updated);
        $this->assertSame(1.5, $this->invokePrivate('getLeading', $updated));
    }

    public function testLeadingAffectsRender(): void
    {
        $p1 = Paragraph::new('one two three four five six')->withMaxWidth(10)->withLeading(1.0);
        $p2 = Paragraph::new('one two three four five six')->withMaxWidth(10)->withLeading(2.0);

        $rendered1 = $p1->render();
        $rendered2 = $p2->render();

        // Double spacing should produce more lines
        $this->assertGreaterThan(
            substr_count($rendered1, "\n"),
            substr_count($rendered2, "\n")
        );
    }

    public function testLeadingClampedToMinimumOne(): void
    {
        $p = Paragraph::new('text')->withLeading(0.5);
        $this->assertSame(1.0, $this->invokePrivate('getLeading', $p));
    }

    // ═══════════════════════════════════════════════════════════════
    // Margins
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultMarginsAreZero(): void
    {
        $p = Paragraph::new('text');
        $this->assertSame(0, $this->invokePrivate('getMarginTop', $p));
        $this->assertSame(0, $this->invokePrivate('getMarginBottom', $p));
    }

    public function testWithMarginTopReturnsNewInstance(): void
    {
        $original = Paragraph::new('text');
        $updated = $original->withMarginTop(2);

        $this->assertNotSame($original, $updated);
        $this->assertSame(2, $this->invokePrivate('getMarginTop', $updated));
    }

    public function testWithMarginBottomReturnsNewInstance(): void
    {
        $original = Paragraph::new('text');
        $updated = $original->withMarginBottom(3);

        $this->assertNotSame($original, $updated);
        $this->assertSame(3, $this->invokePrivate('getMarginBottom', $updated));
    }

    public function testMarginTopAddsBlankLinesBefore(): void
    {
        $p = Paragraph::new('content')->withMarginTop(2);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // First 2 lines should be empty (margin)
        $this->assertSame('', $lines[0]);
        $this->assertSame('', $lines[1]);
        $this->assertSame('content', $lines[2]);
    }

    public function testMarginBottomAddsBlankLinesAfter(): void
    {
        $p = Paragraph::new('content')->withMarginBottom(2);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        $lastIndex = count($lines) - 1;
        $this->assertSame('content', $lines[0]);
        $this->assertSame('', $lines[$lastIndex - 1]);
        $this->assertSame('', $lines[$lastIndex]);
    }

    public function testNegativeMarginClampedToZero(): void
    {
        $p = Paragraph::new('text')->withMarginTop(-5);
        $this->assertSame(0, $this->invokePrivate('getMarginTop', $p));
    }

    // ═══════════════════════════════════════════════════════════════
    // Vertical alignment
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultVerticalAlignIsTop(): void
    {
        $p = Paragraph::new('text');
        $this->assertSame(VAlign::Top, $this->invokePrivate('getVerticalAlign', $p));
    }

    public function testWithVerticalAlignReturnsNewInstance(): void
    {
        $original = Paragraph::new('text');
        $updated = $original->withVerticalAlign(VAlign::Middle);

        $this->assertNotSame($original, $updated);
        $this->assertSame(VAlign::Middle, $this->invokePrivate('getVerticalAlign', $updated));
    }

    public function testVerticalAlignTopPlacesContentAtTop(): void
    {
        $p = Paragraph::new('content')->withVerticalAlign(VAlign::Top)->setSize(10, 5);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // Content should be at the top
        $this->assertSame('content', $lines[0]);
    }

    public function testVerticalAlignBottomPlacesContentAtBottom(): void
    {
        $p = Paragraph::new('content')->withVerticalAlign(VAlign::Bottom)->setSize(10, 5);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // Content should be at the bottom
        $this->assertSame('', $lines[0]);
        $this->assertSame('', $lines[1]);
        $this->assertSame('', $lines[2]);
        $this->assertSame('', $lines[3]);
        $this->assertSame('content', $lines[4]);
    }

    public function testVerticalAlignMiddleCentersContent(): void
    {
        $p = Paragraph::new('content')->withVerticalAlign(VAlign::Middle)->setSize(10, 5);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // Content should be in the middle with padding above and below
        $this->assertSame('', $lines[0]);
        $this->assertSame('', $lines[1]);
        $this->assertSame('content', $lines[2]);
        $this->assertSame('', $lines[3]);
        $this->assertSame('', $lines[4]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Horizontal alignment
    // ═══════════════════════════════════════════════════════════════

    public function testHorizontalAlignLeft(): void
    {
        $p = Paragraph::new('text')->withHorizontalAlign(HAlign::Left)->withMaxWidth(10);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // Text should be left-aligned (no leading spaces)
        $this->assertSame('text', trim($lines[0]));
    }

    public function testHorizontalAlignRight(): void
    {
        $p = Paragraph::new('text')->withHorizontalAlign(HAlign::Right)->withMaxWidth(10);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // Text should be right-aligned (trailing spaces)
        $this->assertSame('text', trim($lines[0]));
        $this->assertGreaterThan(0, strlen($lines[0]) - 4); // Has leading spaces
    }

    public function testHorizontalAlignCenter(): void
    {
        $p = Paragraph::new('x')->withHorizontalAlign(HAlign::Center)->withMaxWidth(10);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // 'x' should be centered within 10 chars
        $this->assertSame(10, mb_strlen($lines[0], 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $p = Paragraph::new('Hello World')->withMaxWidth(40);
        [$w, $h] = $p->getInnerSize();

        $this->assertSame(40, $w);
        $this->assertGreaterThanOrEqual(1, $h);
    }

    public function testGetInnerSizeIncludesLeading(): void
    {
        $p = Paragraph::new('one two three four five six')->withMaxWidth(10)->withLeading(2.0);
        [$w, $h] = $p->getInnerSize();

        // With double spacing and multiple lines, height should increase
        $this->assertGreaterThan(2, $h);
    }

    public function testGetInnerSizeIncludesMargins(): void
    {
        $p = Paragraph::new('text')->withMarginTop(2)->withMarginBottom(1);
        [$w, $h] = $p->getInnerSize();

        // Height should include margin lines
        $this->assertSame(1 + 2 + 1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Trim behavior
    // ═══════════════════════════════════════════════════════════════

    public function testTrimEnabledByDefault(): void
    {
        $p = Paragraph::new('  spaced  ');
        $rendered = $p->render();

        $this->assertSame('spaced', $rendered);
    }

    public function testWithTrimFalsePreservesWhitespace(): void
    {
        $p = Paragraph::new('  spaced  ')->withTrim(false);
        $rendered = $p->render();

        $this->assertSame('  spaced  ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTextReturnsNewInstance(): void
    {
        $original = Paragraph::new('original');
        $updated = $original->withText('updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('updated', $updated->render());
        $this->assertStringContainsString('original', $original->render());
    }

    public function testWithMaxWidthReturnsNewInstance(): void
    {
        $original = Paragraph::new('text')->withMaxWidth(20);
        $updated = $original->withMaxWidth(40);

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Paragraph::new('text');
        $resized = $original->setSize(10, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testAllWithersImmutable(): void
    {
        $original = Paragraph::new('text');

        $p1 = $original->withLeading(1.5);
        $p2 = $p1->withMarginTop(2);
        $p3 = $p2->withMarginBottom(1);
        $p4 = $p3->withVerticalAlign(VAlign::Middle);

        // Each should be a new instance
        $this->assertNotSame($original, $p1);
        $this->assertNotSame($p1, $p2);
        $this->assertNotSame($p2, $p3);
        $this->assertNotSame($p3, $p4);

        // Original should be unchanged
        $this->assertSame(1.0, $this->invokePrivate('getLeading', $original));
        $this->assertSame(0, $this->invokePrivate('getMarginTop', $original));
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyText(): void
    {
        $p = Paragraph::new('');
        $this->assertSame('', $p->render());
    }

    public function testSingleWordNoWrap(): void
    {
        $p = Paragraph::new('word')->withMaxWidth(10);
        $rendered = $p->render();

        $this->assertSame('word', $rendered);
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $p = Paragraph::new('text')->withMaxWidth(0);
        $this->assertSame('', $p->render());
    }

    public function testLeadingWithSingleLineNoEffect(): void
    {
        $p = Paragraph::new('single line')->withLeading(2.0);
        [$w, $h] = $p->getInnerSize();

        // Single line should still be height 1 (leading doesn't add space to single line)
        $this->assertSame(1, $h);
    }

    public function testMarginsWithLeading(): void
    {
        $p = Paragraph::new('one two three four five six')->withMaxWidth(10)->withLeading(1.5)->withMarginTop(1)->withMarginBottom(1);
        [$w, $h] = $p->getInnerSize();

        // Should account for leading between multiple lines PLUS margins
        $this->assertGreaterThan(4, $h);
    }

    public function testMultilineWithVerticalAlignAndMargins(): void
    {
        $p = Paragraph::new("line1\nline2")->withMarginTop(1)->withMarginBottom(1)->setSize(10, 6);
        $rendered = $p->render();
        $lines = explode("\n", $rendered);

        // Should have: margin top, line1, line2, margin bottom + possible valign padding
        $this->assertGreaterThanOrEqual(4, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Invoke a private property for testing via its getter name.
     */
    private function invokePrivate(string $getter, Paragraph $p): mixed
    {
        $reflection = new \ReflectionClass($p);
        // Convert getter name like 'getLeading' to property name 'leading'
        $propName = lcfirst(ltrim($getter, 'get'));
        $prop = $reflection->getProperty($propName);
        $prop->setAccessible(true);
        return $prop->getValue($p);
    }
}
