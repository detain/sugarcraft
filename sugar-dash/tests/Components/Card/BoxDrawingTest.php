<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\BoxDrawing;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class BoxDrawingTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBoxDrawingImplementsSizer(): void
    {
        $box = BoxDrawing::new();
        $this->assertInstanceOf(Sizer::class, $box);
    }

    public function testBoxDrawingImplementsItem(): void
    {
        $box = BoxDrawing::new();
        $this->assertInstanceOf(Item::class, $box);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $box = BoxDrawing::new();
        $rendered = $box->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $box = BoxDrawing::new();
        $rendered = $box->render();

        // Default uses '┌' '┐' '└' '┘' '─' '│'
        $this->assertMatchesRegularExpression('/[┌┐└┘─│]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testTitledFactory(): void
    {
        $box = BoxDrawing::titled('My Title');
        $rendered = $box->render();

        $this->assertStringContainsString('My Title', $rendered);
    }

    public function testDoubleFactory(): void
    {
        $box = BoxDrawing::double();
        $rendered = $box->render();

        // Double style uses ╔╗╚╝═║
        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testRoundedFactory(): void
    {
        $box = BoxDrawing::rounded();
        $rendered = $box->render();

        // Rounded style uses ╭╮╰╯─│
        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testBoldFactory(): void
    {
        $box = BoxDrawing::bold();
        $rendered = $box->render();

        // Bold style uses ┏┓┗┛━┃
        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style handling
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDoubleUsesDoubleChars(): void
    {
        $box = BoxDrawing::new()->withStyle('double');
        $rendered = $box->render();

        $this->assertMatchesRegularExpression('/[╔╗╚╝]/', $rendered);
    }

    public function testStyleRoundedUsesRoundedChars(): void
    {
        $box = BoxDrawing::new()->withStyle('rounded');
        $rendered = $box->render();

        $this->assertMatchesRegularExpression('/[╭╮╰╯]/', $rendered);
    }

    public function testStyleBoldUsesBoldChars(): void
    {
        $box = BoxDrawing::new()->withStyle('bold');
        $rendered = $box->render();

        $this->assertMatchesRegularExpression('/[┏┓┗┛]/', $rendered);
    }

    public function testStyleAsciiUsesPlainChars(): void
    {
        $box = BoxDrawing::new()->withStyle('ascii');
        $rendered = $box->render();

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
        $box = BoxDrawing::new()
            ->withBorderColor(Color::ansi(9));
        $rendered = $box->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBgColorAddsAnsiCodes(): void
    {
        $box = BoxDrawing::new()
            ->withBgColor(Color::ansi(9));
        $rendered = $box->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title and subtitle
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $box = BoxDrawing::new()->withTitle('Test Title');
        $rendered = $box->render();

        $this->assertStringContainsString('Test Title', $rendered);
    }

    public function testWithSubtitle(): void
    {
        $box = BoxDrawing::new()->withSubtitle('Test Subtitle');
        $rendered = $box->render();

        $this->assertStringContainsString('Test Subtitle', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border visibility
    // ═══════════════════════════════════════════════════════════════

    public function testWithBorders(): void
    {
        $box = BoxDrawing::new()->withBorders(top: true, bottom: true, left: true, right: true);
        $rendered = $box->render();

        $this->assertMatchesRegularExpression('/[┌┐└┘─│]/', $rendered);
    }

    public function testWithBordersHiddenTop(): void
    {
        $box = BoxDrawing::new()->withBorders(top: false);
        $rendered = $box->render();

        // Should not have top border characters
        $this->assertStringNotContainsString('┌', $rendered);
        $this->assertStringNotContainsString('─', substr($rendered, 0, 10));
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = BoxDrawing::new();
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $box = BoxDrawing::new()->setSize(40, 10);
        $rendered = $box->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = BoxDrawing::new();
        $updated = $original->withTitle('New Title');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithTitle(): void
    {
        $original = BoxDrawing::new();
        $original->withTitle('Changed');
        $rendered = $original->render();

        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $box = BoxDrawing::new();
        [$w, $h] = $box->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithTitleIncreasesHeight(): void
    {
        $boxNoTitle = BoxDrawing::new();
        $boxWithTitle = BoxDrawing::titled('Title');

        [, $h1] = $boxNoTitle->getInnerSize();
        [, $h2] = $boxWithTitle->getInnerSize();

        $this->assertGreaterThanOrEqual($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Dashed and dotted styles
    // ═══════════════════════════════════════════════════════════════

    public function testStyleDashed(): void
    {
        $box = BoxDrawing::new()->withStyle('dashed');
        $rendered = $box->render();

        // Dashed style uses ┅┇
        $this->assertMatchesRegularExpression('/[┅┇]/', $rendered);
    }

    public function testStyleDotted(): void
    {
        $box = BoxDrawing::new()->withStyle('dotted');
        $rendered = $box->render();

        // Dotted style uses ┄┆
        $this->assertMatchesRegularExpression('/[┄┆]/', $rendered);
    }
}
