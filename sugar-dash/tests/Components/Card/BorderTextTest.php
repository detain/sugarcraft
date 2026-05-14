<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\BorderText;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class BorderTextTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBorderTextImplementsSizer(): void
    {
        $border = BorderText::new('Test content');
        $this->assertInstanceOf(Sizer::class, $border);
    }

    public function testBorderTextImplementsItem(): void
    {
        $border = BorderText::new('Test content');
        $this->assertInstanceOf(Item::class, $border);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $border = BorderText::new('Test content');
        $rendered = $border->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $border = BorderText::new('Hello World');
        $rendered = $border->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $border = BorderText::new('Test');
        $rendered = $border->render();

        // Default uses '─' and '│' as borders
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testWithBordersFactory(): void
    {
        $border = BorderText::withBorders('Content');
        $rendered = $border->render();

        // Should have extra padding lines
        $this->assertStringContainsString('Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $border = BorderText::new('Test')->withStyle('double');
        $rendered = $border->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $border = BorderText::new('Test')->withStyle('rounded');
        $rendered = $border->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $border = BorderText::new('Test')->withStyle('bold');
        $rendered = $border->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    public function testStyleAsciiUsesPlainChars(): void
    {
        $border = BorderText::new('Test')->withStyle('ascii');
        $rendered = $border->render();

        // ASCII style uses + - |
        $this->assertStringContainsString('+', $rendered);
        $this->assertStringContainsString('-', $rendered);
        $this->assertStringContainsString('|', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $border = BorderText::new('Test')
            ->withBorderColor(Color::ansi(9));
        $rendered = $border->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $border = BorderText::new('Test')
            ->withTextColor(Color::ansi(12));
        $rendered = $border->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Padding handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithPadding(): void
    {
        $border = BorderText::new('Content')->withPadding(2, 2);
        $rendered = $border->render();

        // Should have extra padding lines
        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(5, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = BorderText::new('Test');
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $border = BorderText::new('Test content')->setSize(40, 10);
        $rendered = $border->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = BorderText::new('Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testOriginalUnchangedAfterWithContent(): void
    {
        $original = BorderText::new('Original');
        $original->withContent('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $border = BorderText::new('Test');
        [$w, $h] = $border->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $border = BorderText::new('');
        $rendered = $border->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $border = BorderText::new('日本語コンテンツ');
        $rendered = $border->render();

        $this->assertStringContainsString('日本語コンテンツ', $rendered);
    }

    public function testDashedStyle(): void
    {
        $border = BorderText::new('Test')->withStyle('dashed');
        $rendered = $border->render();

        // Dashed style uses ┅┇
        $this->assertMatchesRegularExpression('/[┅┇]/', $rendered);
    }

    public function testDottedStyle(): void
    {
        $border = BorderText::new('Test')->withStyle('dotted');
        $rendered = $border->render();

        // Dotted style uses ┄┆
        $this->assertMatchesRegularExpression('/[┄┆]/', $rendered);
    }
}
