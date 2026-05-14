<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Tag;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTagImplementsSizer(): void
    {
        $tag = Tag::new('Test');
        $this->assertInstanceOf(Sizer::class, $tag);
    }

    public function testTagImplementsItem(): void
    {
        $tag = Tag::new('Test');
        $this->assertInstanceOf(Item::class, $tag);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $tag = Tag::new('Test');
        $rendered = $tag->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $tag = Tag::new('Hello');
        $rendered = $tag->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderWithEmptyLabel(): void
    {
        $tag = Tag::new('');
        $rendered = $tag->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testSuccessFactory(): void
    {
        $tag = Tag::success('Done');
        $rendered = $tag->render();

        $this->assertStringContainsString('Done', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWarningFactory(): void
    {
        $tag = Tag::warning('Caution');
        $rendered = $tag->render();

        $this->assertStringContainsString('Caution', $rendered);
    }

    public function testDangerFactory(): void
    {
        $tag = Tag::danger('Error');
        $rendered = $tag->render();

        $this->assertStringContainsString('Error', $rendered);
    }

    public function testInfoFactory(): void
    {
        $tag = Tag::info('Info');
        $rendered = $tag->render();

        $this->assertStringContainsString('Info', $rendered);
    }

    public function testSoftFactory(): void
    {
        $tag = Tag::soft('Soft', Color::hex('#3B82F6'));
        $rendered = $tag->render();

        $this->assertStringContainsString('Soft', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Outlined style
    // ═══════════════════════════════════════════════════════════════

    public function testOutlinedStyleAddsBorderChars(): void
    {
        $tag = Tag::new('Test')->withStyle('outlined');
        $rendered = $tag->render();

        // Outlined style uses box-drawing characters
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('│', $rendered);
        $this->assertStringContainsString('└', $rendered);
    }

    public function testNonOutlinedStyleDoesNotHaveBorderChars(): void
    {
        $tag = Tag::new('Test');
        $rendered = $tag->render();

        // Should not have box-drawing characters
        $this->assertStringNotContainsString('┌', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testForegroundColorAddsAnsiCodes(): void
    {
        $tag = Tag::new('Test')
            ->withForegroundColor(Color::ansi(9));
        $rendered = $tag->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $tag = Tag::new('Test')
            ->withBackgroundColor(Color::ansi(9));
        $rendered = $tag->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $tag = Tag::new('Test')
            ->withForegroundColor(Color::ansi(9))
            ->withBackgroundColor(Color::ansi(1));
        $rendered = $tag->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Icon handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithIcon(): void
    {
        $tag = Tag::new('Tag')->withIcon('★');
        $rendered = $tag->render();

        $this->assertStringContainsString('★', $rendered);
        $this->assertStringContainsString('Tag', $rendered);
    }

    public function testIconWithSpace(): void
    {
        $tag = Tag::new('Tag')->withIcon('★');
        $rendered = $tag->render();

        // Icon should have space before label
        $this->assertStringContainsString('★ ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Tag::new('Test');
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $tag = Tag::new('Hi')->setSize(30, 1);
        $rendered = $tag->render();

        // Should pad to fill width (for solid style)
        $this->assertGreaterThanOrEqual(30, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = Tag::new('Original');
        $updated = $original->withLabel('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Updated', $original->render());
    }

    public function testWithForegroundColorReturnsNewInstance(): void
    {
        $original = Tag::new('Test');
        $updated = $original->withForegroundColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $original = Tag::new('Test');
        $updated = $original->withStyle('outlined');

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $tag = Tag::new('Test');
        [$w, $h] = $tag->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h); // Single-line for non-outlined
    }

    public function testGetInnerSizeOutlinedHasHeightThree(): void
    {
        $tag = Tag::new('Test')->withStyle('outlined');
        [, $h] = $tag->getInnerSize();

        $this->assertSame(3, $h); // Box with top, middle, bottom
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $tag = Tag::new('Hi')->setSize(50, 1);
        [$w, ] = $tag->getInnerSize();

        $this->assertGreaterThanOrEqual(50, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabel(): void
    {
        $tag = Tag::new(str_repeat('x', 100));
        $rendered = $tag->render();

        $this->assertStringContainsString('x', $rendered);
    }

    public function testUnicodeLabel(): void
    {
        $tag = Tag::new('日本語');
        $rendered = $tag->render();

        $this->assertStringContainsString('日本語', $rendered);
    }
}
