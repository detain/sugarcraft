<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Label;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class LabelTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testLabelImplementsSizer(): void
    {
        $label = Label::new('Test');
        $this->assertInstanceOf(Sizer::class, $label);
    }

    public function testLabelImplementsItem(): void
    {
        $label = Label::new('Test');
        $this->assertInstanceOf(Item::class, $label);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $label = Label::new('Test');
        $rendered = $label->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsText(): void
    {
        $label = Label::new('Field Name');
        $rendered = $label->render();

        $this->assertStringContainsString('Field Name', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Required indicator
    // ═══════════════════════════════════════════════════════════════

    public function testRequiredShowsAsterisk(): void
    {
        $label = Label::required('Required Field');
        $rendered = $label->render();

        $this->assertStringContainsString('Required Field', $rendered);
        $this->assertStringContainsString('*', $rendered);
    }

    public function testNonRequiredDoesNotShowAsterisk(): void
    {
        $label = Label::new('Optional Field');
        $rendered = $label->render();

        $this->assertStringContainsString('Optional Field', $rendered);
        $this->assertStringNotContainsString('*', $rendered);
    }

    public function testWithRequired(): void
    {
        $label = Label::new('Field')->withRequired(true);
        $rendered = $label->render();

        $this->assertStringContainsString('*', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Help text
    // ═══════════════════════════════════════════════════════════════

    public function testWithHelpText(): void
    {
        $label = Label::withHelp('Email', 'Enter your email address');
        $rendered = $label->render();

        $this->assertStringContainsString('Email', $rendered);
        $this->assertStringContainsString('Enter your email address', $rendered);
    }

    public function testHelpTextOnNewLine(): void
    {
        $label = Label::withHelp('Field', 'Help text');
        $rendered = $label->render();

        // Help text should be on a new line (after a newline character)
        $this->assertStringContainsString("\n", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $label = Label::new('Test')
            ->withColor(Color::ansi(12));
        $rendered = $label->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $label = Label::new('Test')
            ->withColor(Color::ansi(9));
        $rendered = $label->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Required indicator customization
    // ═══════════════════════════════════════════════════════════════

    public function testCustomRequiredIndicator(): void
    {
        $label = Label::new('Field')
            ->withRequired(true)
            ->withRequiredIndicator(' (required)');
        $rendered = $label->render();

        $this->assertStringContainsString('(required)', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Label::new('Test');
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $label = Label::new('Field')->setSize(30, 5);
        $rendered = $label->render();

        // Should fit within allocated width
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTextReturnsNewInstance(): void
    {
        $original = Label::new('Original');
        $updated = $original->withText('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithRequiredReturnsNewInstance(): void
    {
        $original = Label::new('Test');
        $updated = $original->withRequired(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHelpTextReturnsNewInstance(): void
    {
        $original = Label::new('Test');
        $updated = $original->withHelpText('Help');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithText(): void
    {
        $original = Label::new('Original');
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
        $label = Label::new('Test');
        [$w, $h] = $label->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithHelpTextIncreasesHeight(): void
    {
        $labelNoHelp = Label::new('Field');
        $labelWithHelp = Label::withHelp('Field', 'Some help text');

        [, $h1] = $labelNoHelp->getInnerSize();
        [, $h2] = $labelWithHelp->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyLabel(): void
    {
        $label = Label::new('');
        $rendered = $label->render();

        $this->assertNotSame('', $rendered);
    }

    public function testVeryLongLabel(): void
    {
        $label = Label::new(str_repeat('x', 100));
        $rendered = $label->render();

        $this->assertStringContainsString('x', $rendered);
    }

    public function testUnicodeLabel(): void
    {
        $label = Label::new('日本語ラベル');
        $rendered = $label->render();

        $this->assertStringContainsString('日本語ラベル', $rendered);
    }

    public function testSpecialCharsLabel(): void
    {
        $label = Label::new('Field <name> & "value"');
        $rendered = $label->render();

        $this->assertStringContainsString('Field <name> & "value"', $rendered);
    }
}
