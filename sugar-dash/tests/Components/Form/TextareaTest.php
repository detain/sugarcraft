<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Form;

use SugarCraft\Dash\Components\Form\Textarea;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class TextareaTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTextareaImplementsSizer(): void
    {
        $textarea = Textarea::new();
        $this->assertInstanceOf(Sizer::class, $textarea);
    }

    public function testTextareaImplementsItem(): void
    {
        $textarea = Textarea::new();
        $this->assertInstanceOf(Item::class, $textarea);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $textarea = Textarea::new('Content');
        $rendered = $textarea->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsValue(): void
    {
        $textarea = Textarea::new('Hello World');
        $rendered = $textarea->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Box-drawing characters
    // ═══════════════════════════════════════════════════════════════

    public function testRenderContainsBoxCharacters(): void
    {
        $textarea = Textarea::new('Test');
        $rendered = $textarea->render();

        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('│', $rendered);
        $this->assertStringContainsString('└', $rendered);
        $this->assertStringContainsString('┘', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset factories
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $textarea = Textarea::new('Content');
        $this->assertStringContainsString('Content', $textarea->render());
    }

    public function testLabeledFactory(): void
    {
        $textarea = Textarea::labeled('Content', 'Label');
        $rendered = $textarea->render();

        $this->assertStringContainsString('Content', $rendered);
        $this->assertStringContainsString('Label', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Row configuration
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultRowsIsFive(): void
    {
        $textarea = Textarea::new('Content');
        $rendered = $textarea->render();

        $lines = explode("\n", $rendered);
        // 1 top border + 5 content rows + 1 bottom border = 7 lines
        $this->assertCount(7, $lines);
    }

    public function testCustomRows(): void
    {
        $textarea = Textarea::new('Content', 3);
        $rendered = $textarea->render();

        $lines = explode("\n", $rendered);
        // 1 top border + 3 content rows + 1 bottom border = 5 lines
        $this->assertCount(5, $lines);
    }

    public function testWithRowsChangesHeight(): void
    {
        $textarea5 = Textarea::new('Content', 5);
        $textarea10 = Textarea::new('Content', 10);

        [, $h5] = $textarea5->getInnerSize();
        [, $h10] = $textarea10->getInnerSize();

        $this->assertSame($h5 + 5, $h10);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label handling
    // ═══════════════════════════════════════════════════════════════

    public function testLabelAppearsAboveTextarea(): void
    {
        $textarea = Textarea::labeled('Content', 'Field Label');
        $rendered = $textarea->render();

        $this->assertStringContainsString('Field Label', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Placeholder handling
    // ═══════════════════════════════════════════════════════════════

    public function testPlaceholderShownWhenEmpty(): void
    {
        $textarea = Textarea::new()->withPlaceholder('Enter text here...');
        $rendered = $textarea->render();

        $this->assertStringContainsString('Enter text here...', $rendered);
    }

    public function testValueOverridesPlaceholder(): void
    {
        $textarea = Textarea::new('Actual Value')->withPlaceholder('Placeholder');
        $rendered = $textarea->render();

        $this->assertStringContainsString('Actual Value', $rendered);
        $this->assertStringNotContainsString('Placeholder', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Error handling
    // ═══════════════════════════════════════════════════════════════

    public function testErrorChangesBorderColor(): void
    {
        $textarea = Textarea::new('Content')->withError('Error message');
        $rendered = $textarea->render();

        // Error border should be red (EF4444)
        $this->assertStringContainsString('Content', $rendered);
        $this->assertStringContainsString('Error message', $rendered);
    }

    public function testErrorAddsExtraLine(): void
    {
        $textareaNoError = Textarea::new('Content');
        $textareaWithError = Textarea::new('Content')->withError('Error!');

        [, $hNoError] = $textareaNoError->getInnerSize();
        [, $hWithError] = $textareaWithError->getInnerSize();

        $this->assertGreaterThan($hNoError, $hWithError);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $textarea = Textarea::new('Test')->withBorderColor(Color::ansi(9));
        $rendered = $textarea->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $textarea = Textarea::new('Test')->withBackgroundColor(Color::ansi(8));
        $rendered = $textarea->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $textarea = Textarea::new('Test')->withTextColor(Color::ansi(7));
        $rendered = $textarea->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleBorder(): void
    {
        $textarea = Textarea::new('Test')->withStyle('double');
        $rendered = $textarea->render();

        $this->assertStringContainsString('╔', $rendered);
        $this->assertStringContainsString('═', $rendered);
    }

    public function testStyleRoundedUsesRoundedBorder(): void
    {
        $textarea = Textarea::new('Test')->withStyle('rounded');
        $rendered = $textarea->render();

        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Textarea::new('Content');
        $resized = $original->setSize(60, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocationAffectsRender(): void
    {
        $textarea = Textarea::new('Content');
        $resized = $textarea->setSize(80, 5);
        $rendered = $resized->render();

        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(80, mb_strlen($lines[0] ?? '', 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithValueReturnsNewInstance(): void
    {
        $original = Textarea::new('Original');
        $updated = $original->withValue('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithPlaceholderReturnsNewInstance(): void
    {
        $original = Textarea::new();
        $updated = $original->withPlaceholder('New placeholder');

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = Textarea::new();
        $updated = $original->withLabel('Label');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Label', $updated->render());
    }

    public function testWithErrorReturnsNewInstance(): void
    {
        $original = Textarea::new();
        $updated = $original->withError('Error');

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Textarea::new();
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithRowsReturnsNewInstance(): void
    {
        $original = Textarea::new();
        $updated = $original->withRows(10);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithValue(): void
    {
        $original = Textarea::new('Original');
        $original->withValue('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $textarea = Textarea::new('Content');
        [$w, $h] = $textarea->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithErrorHasMoreHeight(): void
    {
        $textareaNoError = Textarea::new('Content');
        $textareaWithError = Textarea::new('Content')->withError('Long error message that wraps');

        [, $hNoError] = $textareaNoError->getInnerSize();
        [, $hWithError] = $textareaWithError->getInnerSize();

        $this->assertGreaterThan($hNoError, $hWithError);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testLongContentGetsWrapped(): void
    {
        $textarea = Textarea::new(str_repeat('word ', 50), 10);
        $rendered = $textarea->render();

        // Content should be visible
        $this->assertStringContainsString('word', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyValueRenders(): void
    {
        $textarea = Textarea::new('');
        $rendered = $textarea->render();

        $this->assertNotSame('', $rendered);
    }

    public function testNullValueRenders(): void
    {
        $textarea = Textarea::new(null);
        $rendered = $textarea->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $textarea = Textarea::new('こんにちは世界');
        $rendered = $textarea->render();

        $this->assertStringContainsString('こんにちは世界', $rendered);
    }

    public function testSpecialCharsInContent(): void
    {
        $textarea = Textarea::new('Test & <Tag> "Quote"');
        $rendered = $textarea->render();

        $this->assertStringContainsString('Test & <Tag> "Quote"', $rendered);
    }

    public function testZeroRowsClamped(): void
    {
        $textarea = Textarea::new('Content', 0);
        [, $h] = $textarea->getInnerSize();

        // Minimum should be 3 (top border + 1 row + bottom border)
        $this->assertGreaterThanOrEqual(3, $h);
    }
}
