<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\QRCode;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class QRCodeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testQRCodeImplementsSizer(): void
    {
        $qr = QRCode::new('test');
        $this->assertInstanceOf(Sizer::class, $qr);
    }

    public function testQRCodeImplementsItem(): void
    {
        $qr = QRCode::new('test');
        $this->assertInstanceOf(Item::class, $qr);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $qr = QRCode::new('hello');
        $rendered = $qr->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsQrCharacters(): void
    {
        $qr = QRCode::new('test');
        $rendered = $qr->render();

        // Should contain block characters
        $this->assertMatchesRegularExpression('/[██]/', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $qr = QRCode::new('test');
        $rendered = $qr->render();

        // QR code is multi-line
        $this->assertStringContainsString("\n", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Content handling
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentContentProducesDifferentOutput(): void
    {
        $qr1 = QRCode::new('hello');
        $qr2 = QRCode::new('world');

        $rendered1 = $qr1->render();
        $rendered2 = $qr2->render();

        // Different content should produce different patterns
        $this->assertNotSame($rendered1, $rendered2);
    }

    public function testSameContentProducesSameOutput(): void
    {
        $qr1 = QRCode::new('consistent');
        $qr2 = QRCode::new('consistent');

        $rendered1 = $qr1->render();
        $rendered2 = $qr2->render();

        // Same content should produce same pattern (deterministic)
        $this->assertSame($rendered1, $rendered2);
    }

    public function testEmptyContentStillRenders(): void
    {
        $qr = QRCode::new('');
        $rendered = $qr->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentSizesProduceDifferentDimensions(): void
    {
        $qrSmall = QRCode::new('test')->withSize(6);
        $qrLarge = QRCode::new('test')->withSize(12);

        $renderedSmall = $qrSmall->render();
        $renderedLarge = $qrLarge->render();

        // Larger size should produce more output
        $this->assertGreaterThan(
            strlen($renderedSmall),
            strlen($renderedLarge)
        );
    }

    public function testMinimumSizeEnforced(): void
    {
        $qr = QRCode::new('test')->withSize(2);
        $rendered = $qr->render();

        // Should still render (minimum size enforced)
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $qr = QRCode::new('test')->withFilledColor(Color::ansi(9));
        $rendered = $qr->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $qr = QRCode::new('test')
            ->withFilledColor(Color::ansi(9))
            ->withEmptyColor(Color::ansi(8));
        $rendered = $qr->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $qr = QRCode::new('test');
        [$w, $h] = $qr->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithSizeParameter(): void
    {
        $qr = QRCode::new('test')->withSize(10);
        [$w, $h] = $qr->getInnerSize();

        // Width should be size * 2 (each cell is 2 chars)
        $this->assertSame(20, $w);
        $this->assertSame(10, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = QRCode::new('old');
        $updated = $original->withContent('new');

        $this->assertNotSame($original, $updated);
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $original = QRCode::new('test');
        $updated = $original->withSize(10);

        $this->assertNotSame($original, $updated);
    }

    public function testWithBorderedReturnsNewInstance(): void
    {
        $original = QRCode::new('test');
        $updated = $original->withBordered(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFilledColorReturnsNewInstance(): void
    {
        $original = QRCode::new('test');
        $updated = $original->withFilledColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithEmptyColorReturnsNewInstance(): void
    {
        $original = QRCode::new('test');
        $updated = $original->withEmptyColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = QRCode::new('test');
        $resized = $original->setSize(20, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testLongContent(): void
    {
        $qr = QRCode::new('This is a very long content string for testing');
        $rendered = $qr->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSpecialCharacters(): void
    {
        $qr = QRCode::new('Hello 世界 🌍');
        $rendered = $qr->render();

        $this->assertNotSame('', $rendered);
    }
}
