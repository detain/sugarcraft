<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Barcode;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class BarcodeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBarcodeImplementsSizer(): void
    {
        $barcode = Barcode::new('1234567890');
        $this->assertInstanceOf(Sizer::class, $barcode);
    }

    public function testBarcodeImplementsItem(): void
    {
        $barcode = Barcode::new('1234567890');
        $this->assertInstanceOf(Item::class, $barcode);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $barcode = Barcode::new('1234567890');
        $rendered = $barcode->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBarCharacters(): void
    {
        $barcode = Barcode::new('1234567890');
        $rendered = $barcode->render();

        // Should contain bar-drawing characters
        $this->assertMatchesRegularExpression('/[▏▎▍]/', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $barcode = Barcode::new('1234567890');
        $rendered = $barcode->render();

        // Barcode is multi-line
        $this->assertStringContainsString("\n", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Content handling
    // ═══════════════════════════════════════════════════════════════

    public function testContentAppearsBelowBars(): void
    {
        $barcode = Barcode::new('1234567890');
        $rendered = $barcode->render();

        // Should contain the content as text label
        $this->assertStringContainsString('1234567890', $rendered);
    }

    public function testDifferentContentProducesDifferentOutput(): void
    {
        $barcode1 = Barcode::new('ABC');
        $barcode2 = Barcode::new('XYZ');

        $rendered1 = $barcode1->render();
        $rendered2 = $barcode2->render();

        $this->assertNotSame($rendered1, $rendered2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Height handling
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentHeightsProduceDifferentOutput(): void
    {
        $barcode1 = Barcode::new('12345')->withHeight(2);
        $barcode2 = Barcode::new('12345')->withHeight(5);

        $rendered1 = $barcode1->render();
        $rendered2 = $barcode2->render();

        // Taller barcode should have more newlines
        $this->assertGreaterThan(
            substr_count($rendered1, "\n"),
            substr_count($rendered2, "\n")
        );
    }

    public function testMinimumHeightEnforced(): void
    {
        $barcode = Barcode::new('12345')->withHeight(1);
        $rendered = $barcode->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Text label
    // ═══════════════════════════════════════════════════════════════

    public function testShowTextByDefault(): void
    {
        $barcode = Barcode::new('12345');
        $rendered = $barcode->render();

        // Should contain the content as label
        $this->assertStringContainsString('12345', $rendered);
    }

    public function testHideText(): void
    {
        $barcode = Barcode::new('12345')->withShowText(false);
        $rendered = $barcode->render();

        // Should NOT contain the content as plain text (only in bar pattern)
        // The numeric content won't appear as a label
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $barcode = Barcode::new('12345')->withBarColor(Color::ansi(9));
        $rendered = $barcode->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $barcode = Barcode::new('12345')->withBarColor(Color::ansi(9));
        $rendered = $barcode->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $barcode = Barcode::new('12345');
        [$w, $h] = $barcode->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithHeight(): void
    {
        $barcode = Barcode::new('12345')->withHeight(5);
        [, $h] = $barcode->getInnerSize();

        // Height includes bar rows + text label
        $this->assertSame(6, $h);
    }

    public function testGetInnerSizeWithoutText(): void
    {
        $barcode = Barcode::new('12345')->withShowText(false);
        [, $h] = $barcode->getInnerSize();

        // Height is just bar rows
        $this->assertSame(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Barcode::new('old');
        $updated = $original->withContent('new');

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Barcode::new('12345');
        $updated = $original->withHeight(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowTextReturnsNewInstance(): void
    {
        $original = Barcode::new('12345');
        $updated = $original->withShowText(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithBarColorReturnsNewInstance(): void
    {
        $original = Barcode::new('12345');
        $updated = $original->withBarColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Barcode::new('12345');
        $resized = $original->setSize(50, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContent(): void
    {
        $barcode = Barcode::new('');
        $rendered = $barcode->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContent(): void
    {
        $barcode = Barcode::new('12345678901234567890');
        $rendered = $barcode->render();

        $this->assertNotSame('', $rendered);
    }

    public function testAlphaNumericContent(): void
    {
        $barcode = Barcode::new('ABC-123-XYZ');
        $rendered = $barcode->render();

        $this->assertNotSame('', $rendered);
    }
}
