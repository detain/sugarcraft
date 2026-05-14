<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTextImplementsSizer(): void
    {
        $text = Text::new('test');
        $this->assertInstanceOf(Sizer::class, $text);
    }

    public function testTextImplementsItem(): void
    {
        $text = Text::new('test');
        $this->assertInstanceOf(Item::class, $text);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsTextWhenNoWidth(): void
    {
        $text = Text::new('hello world');
        $this->assertSame('hello world', $text->render());
    }

    public function testRenderWithWidthConstrainsText(): void
    {
        $text = Text::new('hello world');
        $text = $text->setSize(5, 10);

        $rendered = $text->render();
        $this->assertNotSame('', $rendered);

        // Each line should be at most 5 characters wide
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(5, Width::string($line));
        }
    }

    public function testRenderWithMaxWidthWithoutSetSize(): void
    {
        $text = Text::new('hello world')->withMaxWidth(5);

        $rendered = $text->render();
        $this->assertNotSame('', $rendered);

        // Each line should be at most 5 characters wide
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(5, Width::string($line));
        }
    }

    public function testRenderWithZeroSizeReturnsRawText(): void
    {
        $text = Text::new('still raw');
        $text = $text->setSize(0, 5);
        $this->assertSame('still raw', $text->render());
    }

    public function testRenderWithZeroWidthReturnsRawText(): void
    {
        $text = Text::new('still raw');
        $text = $text->setSize(0, 5);
        $this->assertSame('still raw', $text->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testWordWrapBasic(): void
    {
        $text = Text::new('hello world')->setSize(5, 10);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // "hello" (5 chars) and "world" (5 chars) should be on separate lines
        $this->assertCount(2, $lines);
        $this->assertSame('hello', $lines[0]);
        $this->assertSame('world', $lines[1]);
    }

    public function testWordWrapMultipleWords(): void
    {
        $text = Text::new('the quick brown fox')->setSize(6, 10);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // Lines should fit within 6 characters
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(6, Width::string($line));
        }
    }

    public function testWordWrapPreservesParagraphs(): void
    {
        // Input has two paragraphs separated by double newline
        // After word-wrapping at width 20, paragraphs should remain on separate lines
        $text = Text::new("hello world\n\nfoo bar")->setSize(20, 10);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // Should have at least 2 distinct content blocks
        // (paragraph break may result in consecutive newlines or actual empty line)
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    public function testLongWordBreaksAcrossLines(): void
    {
        $text = Text::new('supercalifragilisticexpialidocious')->setSize(8, 10);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // Long word should be broken across multiple lines
        $this->assertGreaterThan(1, count($lines));

        // Each line should be at most 8 characters
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(8, Width::string($line));
        }
    }

    public function testEmptyTextRendersEmpty(): void
    {
        $text = Text::new('')->setSize(10, 5);

        $rendered = $text->render();
        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Whitespace handling
    // ═══════════════════════════════════════════════════════════════

    public function testTrimRemovesLeadingTrailingWhitespace(): void
    {
        $text = Text::new('  hello world  ')->withTrim(true)->setSize(20, 5);

        $rendered = $text->render();
        // Leading whitespace should be removed - first char should be 'h'
        $this->assertStringStartsWith('hello', trim($rendered));
    }

    public function testTrimCollapsesInternalWhitespace(): void
    {
        $text = Text::new("hello    world")->withTrim(true)->setSize(20, 5);

        $rendered = $text->render();
        // Multiple spaces should be collapsed to single space
        $this->assertStringNotContainsString('    ', trim($rendered));
        $this->assertStringContainsString('hello world', trim($rendered));
    }

    public function testWithoutTrimPreservesWhitespace(): void
    {
        $text = Text::new("hello world")->withTrim(false)->setSize(20, 5);

        $rendered = $text->render();
        // Without trim, leading/trailing spaces in content are preserved
        // Note: internal whitespace is normalized during word-wrapping
        $this->assertStringContainsString('hello world', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Horizontal alignment
    // ═══════════════════════════════════════════════════════════════

    public function testHAlignLeft(): void
    {
        $text = Text::new('hello')->withHorizontalAlign(HAlign::Left)->setSize(10, 3);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // First line should start with 'hello' and have trailing spaces
        $this->assertStringStartsWith('hello', $lines[0]);
    }

    public function testHAlignRight(): void
    {
        $text = Text::new('hello')->withHorizontalAlign(HAlign::Right)->setSize(10, 3);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // First line should end with 'hello' and have leading spaces
        $this->assertStringEndsWith('hello', $lines[0]);
    }

    public function testHAlignCenter(): void
    {
        $text = Text::new('hello')->withHorizontalAlign(HAlign::Center)->setSize(10, 3);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // The line should have spaces on both sides
        $trimmed = trim($lines[0]);
        $this->assertSame('hello', $trimmed);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeWithNoWidthReturnsLongestLine(): void
    {
        $text = Text::new("short\nmedium length\nvery long line");

        [$w, $h] = $text->getInnerSize();

        // Width should be the longest line
        $this->assertSame(14, $w); // "very long line" = 14 chars
        // Height should be number of lines
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeWithWidthReturnsWidthAndWrappedHeight(): void
    {
        $text = Text::new('hello world super long text')->setSize(5, 100);

        [$w, $h] = $text->getInnerSize();

        // Width should be 5 (the set width)
        $this->assertSame(5, $w);
        // Height should be number of wrapped lines
        $this->assertGreaterThanOrEqual(3, $h);
    }

    public function testGetInnerSizeBeforeSetSizeUsesMaxWidth(): void
    {
        $text = Text::new('hello world')->withMaxWidth(5);

        [$w, $h] = $text->getInnerSize();

        $this->assertSame(5, $w);
        $this->assertSame(2, $h); // "hello" and "world"
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithMaxWidth(): void
    {
        $text = Text::new('hello world')->withMaxWidth(5);

        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        $this->assertCount(2, $lines);
    }

    public function testWithTrimTrue(): void
    {
        $text = Text::new('  hello  ')->withTrim(true)->setSize(10, 3);

        $rendered = $text->render();
        $this->assertSame('hello', trim($rendered));
    }

    public function testWithTrimFalse(): void
    {
        $text = Text::new('hello world')->withTrim(false)->setSize(10, 3);

        $rendered = $text->render();
        // Without trim, the content should still render correctly
        // Note: word-wrapping normalizes whitespace internally
        $this->assertStringContainsString('hello', $rendered);
        $this->assertStringContainsString('world', $rendered);
    }

    public function testWithHorizontalAlignLeft(): void
    {
        $text = Text::new('hello')->withHorizontalAlign(HAlign::Left)->setSize(10, 3);

        $rendered = $text->render();
        $this->assertStringStartsWith('hello', $rendered);
    }

    public function testWithHorizontalAlignRight(): void
    {
        $text = Text::new('hello')->withHorizontalAlign(HAlign::Right)->setSize(10, 3);

        $rendered = $text->render();
        $this->assertStringEndsWith('hello', $rendered);
    }

    public function testWithHorizontalAlignCenter(): void
    {
        $text = Text::new('hello')->withHorizontalAlign(HAlign::Center)->setSize(10, 3);

        $rendered = $text->render();
        // Center alignment of "hello" (5 chars) in width 10
        // gives 5 padding spaces: 2 left, 3 right (extra space goes to right)
        $this->assertSame('  hello   ', $rendered);
    }

    public function testWithText(): void
    {
        $original = Text::new('original');
        $updated = $original->withText('updated');

        $this->assertSame('original', $original->render());
        $this->assertSame('updated', $updated->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryNarrowWidth(): void
    {
        $text = Text::new('hello world')->setSize(1, 20);

        $rendered = $text->render();
        $this->assertNotSame('', $rendered);

        // Each line should be at most 1 character
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(1, Width::string($line));
        }
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Text::new('test');
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
        // Original unchanged
        $original->render(); // no crash
    }

    public function testChainedWithers(): void
    {
        $text = Text::new('hello world')
            ->withMaxWidth(5)
            ->withTrim(true)
            ->withHorizontalAlign(HAlign::Center);

        $this->assertNotSame('', $text->render());
    }

    public function testNaturalHeightWithWrappedText(): void
    {
        $text = Text::new('the quick brown fox jumps over the lazy dog');

        // With width of 6, text should wrap to multiple lines
        $text = $text->setSize(6, 100);
        $rendered = $text->render();
        $lines = explode("\n", $rendered);

        // The number of lines should be greater than 1
        $this->assertGreaterThan(1, count($lines));
    }
}
