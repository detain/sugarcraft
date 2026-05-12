<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Hint;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class HintTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHintImplementsSizer(): void
    {
        $hint = Hint::new('Test hint');
        $this->assertInstanceOf(Sizer::class, $hint);
    }

    public function testHintImplementsItem(): void
    {
        $hint = Hint::new('Test hint');
        $this->assertInstanceOf(Item::class, $hint);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $hint = Hint::new('Test hint');
        $rendered = $hint->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsText(): void
    {
        $hint = Hint::new('Hello World');
        $rendered = $hint->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderWithEmptyText(): void
    {
        $hint = Hint::new('');
        $rendered = $hint->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testMutedFactory(): void
    {
        $hint = Hint::muted('Secondary text');
        $rendered = $hint->render();

        $this->assertStringContainsString('Secondary text', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testItalicFactory(): void
    {
        $hint = Hint::italic('Italic text');
        $rendered = $hint->render();

        $this->assertStringContainsString('Italic text', $rendered);
    }

    public function testInfoFactory(): void
    {
        $hint = Hint::info('Information');
        $rendered = $hint->render();

        $this->assertStringContainsString('Information', $rendered);
        $this->assertStringContainsString('ℹ', $rendered);
    }

    public function testSuccessFactory(): void
    {
        $hint = Hint::success('Success message');
        $rendered = $hint->render();

        $this->assertStringContainsString('Success message', $rendered);
        $this->assertStringContainsString('✓', $rendered);
    }

    public function testWarningFactory(): void
    {
        $hint = Hint::warning('Warning message');
        $rendered = $hint->render();

        $this->assertStringContainsString('Warning message', $rendered);
        $this->assertStringContainsString('⚠', $rendered);
    }

    public function testDangerFactory(): void
    {
        $hint = Hint::danger('Danger message');
        $rendered = $hint->render();

        $this->assertStringContainsString('Danger message', $rendered);
        $this->assertStringContainsString('✖', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Icon handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithIcon(): void
    {
        $hint = Hint::new('Text')->withIcon('★');
        $rendered = $hint->render();

        $this->assertStringContainsString('★', $rendered);
        $this->assertStringContainsString('Text', $rendered);
    }

    public function testIconWithSpace(): void
    {
        $hint = Hint::new('Text')->withIcon('★');
        $rendered = $hint->render();

        // Icon should have space before text
        $this->assertStringContainsString('★ ', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $hint = Hint::new('Test')
            ->withColor(Color::ansi(12));
        $rendered = $hint->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $hint = Hint::new('Test')
            ->withColor(Color::ansi(9));
        $rendered = $hint->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleNormal(): void
    {
        $hint = Hint::new('Text')->withStyle('normal');
        $rendered = $hint->render();

        $this->assertStringContainsString('Text', $rendered);
    }

    public function testStyleItalic(): void
    {
        $hint = Hint::new('Text')->withStyle('italic');
        $rendered = $hint->render();

        // Should contain italic ANSI codes
        $this->assertStringContainsString('Text', $rendered);
    }

    public function testStyleBold(): void
    {
        $hint = Hint::new('Text')->withStyle('bold');
        $rendered = $hint->render();

        $this->assertStringContainsString('Text', $rendered);
    }

    public function testStyleUnderline(): void
    {
        $hint = Hint::new('Text')->withStyle('underline');
        $rendered = $hint->render();

        $this->assertStringContainsString('Text', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Hint::new('Test');
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $hint = Hint::new('This is a longer hint text that may need to wrap')->setSize(20, 10);
        $rendered = $hint->render();

        // Should wrap to multiple lines
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTextReturnsNewInstance(): void
    {
        $original = Hint::new('Original');
        $updated = $original->withText('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Hint::new('Test');
        $updated = $original->withColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithText(): void
    {
        $original = Hint::new('Original');
        $original->withText('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $hint = Hint::new('Test');
        [$w, $h] = $hint->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithWrapping(): void
    {
        $hint = Hint::new('This is a longer hint that should wrap')->setSize(10, 5);
        [$w, $h] = $hint->getInnerSize();

        $this->assertGreaterThan(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongText(): void
    {
        $hint = Hint::new(str_repeat('x ', 100));
        $rendered = $hint->render();

        $this->assertStringContainsString('x', $rendered);
    }

    public function testUnicodeText(): void
    {
        $hint = Hint::new('日本語のヒント');
        $rendered = $hint->render();

        $this->assertStringContainsString('日本語のヒント', $rendered);
    }

    public function testSpecialCharsText(): void
    {
        $hint = Hint::new('Test <tag> & "quotes"');
        $rendered = $hint->render();

        $this->assertStringContainsString('Test <tag> & "quotes"', $rendered);
    }
}
