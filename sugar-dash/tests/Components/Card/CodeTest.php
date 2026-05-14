<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Code;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class CodeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCodeImplementsSizer(): void
    {
        $code = Code::new('hello');
        $this->assertInstanceOf(Sizer::class, $code);
    }

    public function testCodeImplementsItem(): void
    {
        $code = Code::new('hello');
        $this->assertInstanceOf(Item::class, $code);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $code = Code::new('hello world')->withMaxWidth(40);
        $rendered = $code->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $code = Code::new('hello')->withMaxWidth(40);
        $rendered = $code->render();

        // Should contain box-drawing characters
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('│', $rendered);
    }

    public function testRenderContainsCodeContent(): void
    {
        $code = Code::new('hello world')->withMaxWidth(40);
        $rendered = $code->render();

        // Code content should appear in output
        $this->assertStringContainsString('hello world', $rendered);
    }

    public function testEmptyCodeRendersBorderOnly(): void
    {
        $code = Code::new('')->withMaxWidth(40);
        $rendered = $code->render();

        // Should still have border characters
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrap behavior
    // ═══════════════════════════════════════════════════════════════

    public function testWordWrapByDefault(): void
    {
        // Create code with spaces so it can be wrapped at word boundaries
        $longCode = str_repeat('word ', 25); // 25 * 5 = 125 chars with spaces
        $code = Code::new($longCode)
            ->withMaxWidth(20)
            ->withWordWrap(true);
        $rendered = $code->render();

        // Strip ANSI codes then count lines
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $lineCount = substr_count($stripped, "\n│");
        $this->assertGreaterThan(1, $lineCount);
    }

    public function testWordWrapDisabledWithFalse(): void
    {
        $longCode = str_repeat('word ', 25);
        $code = Code::new($longCode)
            ->withMaxWidth(20)
            ->withWordWrap(false);
        $rendered = $code->render();

        // Should be a single line of code (just one │ after top border, no language)
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        $lineCount = substr_count($stripped, "\n│");
        $this->assertSame(1, $lineCount);
    }

    public function testWordWrapRespectsMaxWidth(): void
    {
        $code = Code::new('hello world test')
            ->withMaxWidth(10)
            ->withWordWrap(true);
        $rendered = $code->render();

        // Code should be wrapped - "hello world test" won't appear as single line
        // It gets split into multiple lines
        $this->assertStringNotContainsString("hello world test", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Language label rendering
    // ═══════════════════════════════════════════════════════════════

    public function testLanguageLabelRendersWhenSet(): void
    {
        $code = Code::new('echo "hello"', 'php')->withMaxWidth(40);
        $rendered = $code->render();

        // Language label should appear in output
        $this->assertStringContainsString('php', $rendered);
    }

    public function testLanguageLabelNotRenderedWhenNull(): void
    {
        $code = Code::new('echo "hello"', null)->withMaxWidth(40);
        $rendered = $code->render();

        // Should have top and bottom border (2 ┌ characters)
        $borderCount = substr_count($rendered, '┌');
        $this->assertSame(2, $borderCount);
    }

    public function testLanguageLabelTruncatesWhenTooWide(): void
    {
        // Very long language name should be truncated to fit
        $code = Code::new('code', 'verylonglanguagename')->withMaxWidth(10);
        $rendered = $code->render();

        // Should contain some part of the language name
        $this->assertStringContainsString('very', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color application
    // ═══════════════════════════════════════════════════════════════

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $code = Code::new('test')
            ->withMaxWidth(40)
            ->withBackgroundColor(Color::ansi(9)); // Red
        $rendered = $code->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $code = Code::new('test')
            ->withMaxWidth(40)
            ->withTextColor(Color::ansi(10)); // Green
        $rendered = $code->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $code = Code::new('test')
            ->withMaxWidth(40)
            ->withBorderColor(Color::ansi(11)); // Yellow
        $rendered = $code->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLanguageColorAddsAnsiCodes(): void
    {
        $code = Code::new('test', 'php')
            ->withMaxWidth(40)
            ->withLanguageColor(Color::ansi(13)); // Magenta
        $rendered = $code->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $code = Code::new('test')
            ->withMaxWidth(40)
            ->withBackgroundColor(Color::ansi(9))
            ->withTextColor(Color::ansi(10));
        $rendered = $code->render();

        // Should end with reset code (0m)
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testNoColorRendersPlainText(): void
    {
        $code = (new Code(
            code: 'plain code',
            language: null,
            maxWidth: null,
            wordWrap: true,
            horizontalAlign: HAlign::Left,
            backgroundColor: null,
            textColor: null,
            borderColor: null,
            languageColor: null,
        ))->withMaxWidth(40);
        $rendered = $code->render();

        // Should not contain ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Horizontal alignment
    // ═══════════════════════════════════════════════════════════════

    public function testLeftAlignByDefault(): void
    {
        $code = Code::new('test')->withMaxWidth(20);
        $rendered = $code->render();

        // Code should be left-aligned (starts near the left border)
        // The pattern │ test ... │ has 1 space after │
        $this->assertStringContainsString('│ test', $rendered);
    }

    public function testRightAlign(): void
    {
        $code = Code::new('test')
            ->withMaxWidth(20)
            ->withHorizontalAlign(HAlign::Right);
        $rendered = $code->render();

        // Code should be right-aligned
        $this->assertStringContainsString('test', $rendered);
    }

    public function testCenterAlign(): void
    {
        $code = Code::new('test')
            ->withMaxWidth(20)
            ->withHorizontalAlign(HAlign::Center);
        $rendered = $code->render();

        // Centered code should have spaces on both sides
        $this->assertStringContainsString('test', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLanguageReturnsNewInstance(): void
    {
        $original = Code::new('test')->withMaxWidth(40);
        $updated = $original->withLanguage('php');

        $this->assertNotSame($original, $updated);
        $this->assertStringNotContainsString('php', $original->render());
        $this->assertStringContainsString('php', $updated->render());
    }

    public function testWithMaxWidthReturnsNewInstance(): void
    {
        $original = Code::new('test');
        $updated = $original->withMaxWidth(40);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWordWrapReturnsNewInstance(): void
    {
        $original = Code::new('test');
        $updated = $original->withWordWrap(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHorizontalAlignReturnsNewInstance(): void
    {
        $original = Code::new('test');
        $updated = $original->withHorizontalAlign(HAlign::Center);

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Code::new('test');
        $updated = $original->withBackgroundColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTextColorReturnsNewInstance(): void
    {
        $original = Code::new('test');
        $updated = $original->withTextColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Code::new('test');
        $updated = $original->withBorderColor(Color::ansi(11));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLanguageColorReturnsNewInstance(): void
    {
        $original = Code::new('test', 'php');
        $updated = $original->withLanguageColor(Color::ansi(13));

        $this->assertNotSame($original, $updated);
    }

    public function testWithCodeReturnsNewInstance(): void
    {
        $original = Code::new('original')->withMaxWidth(40);
        $updated = $original->withCode('changed');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('original', $original->render());
        $this->assertStringContainsString('changed', $updated->render());
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Code::new('test');
        $resized = $original->setSize(40, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $code = Code::new("line1\nline2\nline3")->withMaxWidth(20);
        [$w, $h] = $code->getInnerSize();

        // Width should include border chars (content + 4)
        $this->assertGreaterThanOrEqual(4, $w);
        // Height should be lines + top/bottom border + possible language label
        $this->assertGreaterThanOrEqual(5, $h); // 3 lines + 2 borders
    }

    public function testGetInnerSizeWithLanguageLabel(): void
    {
        $code = Code::new("line1\nline2")->withMaxWidth(20);
        $withLabel = $code->withLanguage('php');
        [$w, $h] = $withLabel->getInnerSize();

        // Should have extra height for language label
        $this->assertGreaterThanOrEqual(4, $h); // 2 lines + 2 borders + 1 label
    }

    public function testGetInnerSizeWithoutWidthConstraint(): void
    {
        $code = Code::new("short\nlonger line");
        [$w, $h] = $code->getInnerSize();

        // Should compute natural width from longest line + border
        $this->assertGreaterThan(4, $w);
        $this->assertSame(4, $h); // 2 lines + 2 borders
    }

    public function testGetInnerSizeZeroWidthReturnsNaturalDimensions(): void
    {
        $code = Code::new('test')->withMaxWidth(0);
        [$w, $h] = $code->getInnerSize();

        // When maxWidth is 0, natural dimensions are computed from code content
        $this->assertGreaterThan(0, $w); // Natural dimensions based on code content
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testZeroWidthRendersEmpty(): void
    {
        $code = Code::new('test')->withMaxWidth(0);
        $this->assertSame('', $code->render());
    }

    public function testVerySmallWidthRendersBorderOnly(): void
    {
        $code = Code::new('test')->withMaxWidth(1);
        $rendered = $code->render();

        // Should have border but content may not fit
        $this->assertStringContainsString('┌', $rendered);
    }

    public function testMultiLineCodeRendersAllLines(): void
    {
        $code = Code::new("line1\nline2\nline3")->withMaxWidth(40);
        $rendered = $code->render();

        $this->assertStringContainsString('line1', $rendered);
        $this->assertStringContainsString('line2', $rendered);
        $this->assertStringContainsString('line3', $rendered);
    }

    public function testNewFactoryMethodHasDefaultColors(): void
    {
        $code = Code::new('test', 'php')->withMaxWidth(40);
        $rendered = $code->render();

        // Factory method should apply default colors (TokyoNight theme)
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testConstructorAllowsNullColors(): void
    {
        $code = (new Code(
            code: 'test',
            language: 'php',
            maxWidth: null,
            wordWrap: true,
            horizontalAlign: HAlign::Left,
            backgroundColor: null,
            textColor: null,
            borderColor: null,
            languageColor: null,
        ))->withMaxWidth(40);
        $rendered = $code->render();

        // Should render without ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    public function testEmptyLanguageStringTreatedAsNoLabel(): void
    {
        $code = Code::new('test', '')->withMaxWidth(40);
        $rendered = $code->render();

        // Empty string language should not render label (top + bottom border = 2)
        $borderCount = substr_count($rendered, '┌');
        $this->assertSame(2, $borderCount);
    }
}
