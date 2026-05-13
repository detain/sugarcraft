<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\HexDump;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class HexDumpTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHexDumpImplementsSizer(): void
    {
        $hex = HexDump::new('Hello');
        $this->assertInstanceOf(Sizer::class, $hex);
    }

    public function testHexDumpImplementsItem(): void
    {
        $hex = HexDump::new('Hello');
        $this->assertInstanceOf(Item::class, $hex);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $hex = HexDump::new('Hello');
        $rendered = $hex->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsOffset(): void
    {
        $hex = HexDump::new('Hello');
        $rendered = $hex->render();

        // Should contain offset (starts at 0)
        $this->assertStringContainsString('00000000', $rendered);
    }

    public function testRenderContainsHexBytes(): void
    {
        $hex = HexDump::new('Hello');
        $rendered = $hex->render();

        // "Hello" in hex is 48 65 6c 6c 6f
        $this->assertStringContainsString('48', $rendered);
        $this->assertStringContainsString('65', $rendered);
    }

    public function testRenderContainsAscii(): void
    {
        $hex = HexDump::new('Hello');
        $rendered = $hex->render();

        // ASCII representation should be visible
        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderWithEmptyString(): void
    {
        $hex = HexDump::new('');
        $rendered = $hex->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bytes per line variants
    // ═══════════════════════════════════════════════════════════════

    public function testCompactFactoryUses8BytesPerLine(): void
    {
        $hex = HexDump::compact('0123456789ABCDEF');
        $lines = explode("\n", $hex->render());

        // Compact should have 2 lines for 16 bytes (8 per line)
        $this->assertCount(2, $lines);
    }

    public function testDefaultUses16BytesPerLine(): void
    {
        $hex = HexDump::new(str_repeat('A', 16));
        $lines = explode("\n", $hex->render());

        // Default should have 1 line for 16 bytes
        $this->assertCount(1, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Uppercase / lowercase
    // ═══════════════════════════════════════════════════════════════

    public function testUppercaseHexOutput(): void
    {
        $hex = HexDump::new('A');
        $rendered = $hex->render();

        // Should contain uppercase hex
        $this->assertStringContainsString('41', $rendered);
    }

    public function testLowercaseHexOutput(): void
    {
        $hex = HexDump::new('A')->withUppercase(false);
        $rendered = $hex->render();

        // Should contain lowercase hex
        $this->assertStringContainsString('41', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Non-printable characters
    // ═══════════════════════════════════════════════════════════════

    public function testNonPrintableBytesShowAsDot(): void
    {
        // \x00 is non-printable
        $hex = HexDump::new("\x00\x01\x02");
        $rendered = $hex->render();

        // Should contain dots for non-printable
        $this->assertStringContainsString('...', $rendered);
    }

    public function testPrintableBytesShowAsAscii(): void
    {
        $hex = HexDump::new('ABC');
        $rendered = $hex->render();

        // Should contain ABC in the ASCII section
        $this->assertStringContainsString('ABC', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = HexDump::new('Test');
        $resized = $original->setSize(80, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $hex = HexDump::new('Hello')->setSize(80, 10);
        $rendered = $hex->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithDataReturnsNewInstance(): void
    {
        $original = HexDump::new('Original');
        $updated = $original->withData('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithBytesPerLineReturnsNewInstance(): void
    {
        $original = HexDump::new('SomeData');
        $updated = $original->withBytesPerLine(HexDump::BYTES_PER_LINE_8);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOffsetColorReturnsNewInstance(): void
    {
        $original = HexDump::new('Test');
        $updated = $original->withOffsetColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithHexColorReturnsNewInstance(): void
    {
        $original = HexDump::new('Test');
        $updated = $original->withHexColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    public function testWithAsciiColorReturnsNewInstance(): void
    {
        $original = HexDump::new('Test');
        $updated = $original->withAsciiColor(Color::ansi(11));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithData(): void
    {
        $original = HexDump::new('Original');
        $original->withData('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $hex = HexDump::new('Hello');
        [$w, $h] = $hex->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithEmptyData(): void
    {
        $hex = HexDump::new('');
        [$w, $h] = $hex->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeIncreasesWithMoreData(): void
    {
        $hex1 = HexDump::new('A');
        $hex17 = HexDump::new(str_repeat('A', 17));
        $hex33 = HexDump::new(str_repeat('A', 33));

        [, $h1] = $hex1->getInnerSize();
        [, $h17] = $hex17->getInnerSize();
        [, $h33] = $hex33->getInnerSize();

        // 1 byte = 1 line, 17 bytes = 2 lines, 33 bytes = 3 lines (bytesPerLine=16)
        $this->assertLessThan($h17, $h1);
        $this->assertLessThan($h33, $h17);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongData(): void
    {
        $hex = HexDump::new(str_repeat('X', 1000));
        $rendered = $hex->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString('X', $rendered);
    }

    public function testUnicodeData(): void
    {
        $hex = HexDump::new('日本語');
        $rendered = $hex->render();

        // Unicode chars should be shown in hex
        $this->assertNotSame('', $rendered);
    }

    public function testBinaryData(): void
    {
        $hex = HexDump::new(b"\xFF\xFE\x00\x01");
        $rendered = $hex->render();

        // Should render non-printable as dots
        $this->assertStringContainsString('....', $rendered);
    }

    public function testSingleByte(): void
    {
        $hex = HexDump::new('A');
        $rendered = $hex->render();

        $this->assertStringContainsString('41', $rendered);
        $this->assertStringContainsString('A', $rendered);
    }
}
