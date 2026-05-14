<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Form;

use SugarCraft\Dash\Components\Form\Input;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testInputImplementsSizer(): void
    {
        $input = Input::new();
        $this->assertInstanceOf(Sizer::class, $input);
    }

    public function testInputImplementsItem(): void
    {
        $input = Input::new();
        $this->assertInstanceOf(Item::class, $input);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $input = Input::new();
        $rendered = $input->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $input = Input::new();
        $rendered = $input->render();

        // Default uses '─' and '│' as borders
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    public function testRenderEmptyInputShowsPlaceholder(): void
    {
        $input = Input::new()->withPlaceholder('Enter text...');
        $rendered = $input->render();

        $this->assertStringContainsString('Enter text...', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Value handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithValue(): void
    {
        $input = Input::new()->withValue('my input');
        $rendered = $input->render();

        $this->assertStringContainsString('my input', $rendered);
    }

    public function testValueOverridesPlaceholder(): void
    {
        $input = Input::new('actual value')->withPlaceholder('placeholder');
        $rendered = $input->render();

        $this->assertStringContainsString('actual value', $rendered);
        $this->assertStringNotContainsString('placeholder', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Password masking
    // ═══════════════════════════════════════════════════════════════

    public function testMaskedShowsDots(): void
    {
        $input = Input::new('secret')->withMasked(true);
        $rendered = $input->render();

        $this->assertStringContainsString('●', $rendered);
        $this->assertStringNotContainsString('secret', $rendered);
    }

    public function testPasswordFactory(): void
    {
        $input = Input::password('mypassword');
        $rendered = $input->render();

        // Password input should mask the value
        $this->assertStringContainsString('●', $rendered);
    }

    public function testUnmaskedShowsValue(): void
    {
        $input = Input::new('visible')->withMasked(false);
        $rendered = $input->render();

        $this->assertStringContainsString('visible', $rendered);
        $this->assertStringNotContainsString('●', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithLabel(): void
    {
        $input = Input::new()->withLabel('Username');
        $rendered = $input->render();

        $this->assertStringContainsString('Username', $rendered);
    }

    public function testWithLabelFactory(): void
    {
        $input = Input::labeled('test@example.com', 'Email');
        $rendered = $input->render();

        $this->assertStringContainsString('Email', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Error handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithError(): void
    {
        $input = Input::new()->withError('This field is required');
        $rendered = $input->render();

        $this->assertStringContainsString('This field is required', $rendered);
    }

    public function testErrorChangesBorderColor(): void
    {
        $input = Input::new()->withError('Error');
        $rendered = $input->render();

        // Error should use red color for border
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $input = Input::new()
            ->withBorderColor(Color::ansi(9));
        $rendered = $input->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $input = Input::new('text')
            ->withTextColor(Color::ansi(12));
        $rendered = $input->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $input = Input::new()
            ->withBorderColor(Color::ansi(9))
            ->withTextColor(Color::ansi(12));
        $rendered = $input->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $input = Input::new()->withStyle('double');
        $rendered = $input->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $input = Input::new()->withStyle('rounded');
        $rendered = $input->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $input = Input::new()->withStyle('bold');
        $rendered = $input->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Input::new();
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $input = Input::new('Test')->setSize(40, 3);
        $rendered = $input->render();

        // Should fit within allocated width
        $this->assertNotSame('', $rendered);
    }

    public function testWithWidth(): void
    {
        $input = Input::new('Test')->withWidth(50);
        $rendered = $input->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithValueReturnsNewInstance(): void
    {
        $original = Input::new();
        $updated = $original->withValue('new value');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('new value', $updated->render());
    }

    public function testWithPlaceholderReturnsNewInstance(): void
    {
        $original = Input::new();
        $updated = $original->withPlaceholder('Enter...');

        $this->assertNotSame($original, $updated);
    }

    public function testWithErrorReturnsNewInstance(): void
    {
        $original = Input::new();
        $updated = $original->withError('Error');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithValue(): void
    {
        $original = Input::new();
        $original->withValue('Changed');
        $rendered = $original->render();

        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $input = Input::new();
        [$w, $h] = $input->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h); // Top border, middle, bottom border
    }

    public function testGetInnerSizeWithErrorIsHigher(): void
    {
        $inputNoError = Input::new();
        $inputWithError = Input::new()->withError('Error message here');

        [, $h1] = $inputNoError->getInnerSize();
        [, $h2] = $inputWithError->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyValue(): void
    {
        $input = Input::new('');
        $rendered = $input->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongValueTruncates(): void
    {
        $input = Input::new('This is a very long value that exceeds the width')->setSize(20, 3);
        $rendered = $input->render();

        // Should truncate, not crash
        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeValue(): void
    {
        $input = Input::new('日本語入力');
        $rendered = $input->render();

        $this->assertStringContainsString('日本語入力', $rendered);
    }
}
