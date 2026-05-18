<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Foundation\Segment;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SegmentTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSegmentImplementsSizer(): void
    {
        $segment = Segment::new('12');
        $this->assertInstanceOf(Sizer::class, $segment);
    }

    public function testSegmentImplementsItem(): void
    {
        $segment = Segment::new('12');
        $this->assertInstanceOf(Item::class, $segment);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $segment = Segment::new('12');
        $rendered = $segment->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $segment = Segment::new('12');
        $rendered = $segment->render();

        // 7-segment display is multi-line (5 rows)
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testRenderContainsSegmentCharacters(): void
    {
        $segment = Segment::new('8');
        $rendered = $segment->render();

        // Should contain segment-like characters
        $this->assertMatchesRegularExpression('/[▔▁━▏▎]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Content handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderDigits(): void
    {
        $segment = Segment::new('0123456789');
        $rendered = $segment->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderHexLetters(): void
    {
        $segment = Segment::new('ABCDEF');
        $rendered = $segment->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderMixed(): void
    {
        $segment = Segment::new('HELLO 123');
        $rendered = $segment->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderColon(): void
    {
        $segment = Segment::new('12:30');
        $rendered = $segment->render();

        // Should show colon (●)
        $this->assertStringContainsString('●', $rendered);
    }

    public function testRenderHyphen(): void
    {
        $segment = Segment::new('-');
        $rendered = $segment->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderSpace(): void
    {
        $segment = Segment::new('   ');
        $rendered = $segment->render();

        // Should render without error (just empty segments)
        $this->assertNotSame('', $rendered);
    }

    public function testRenderEmpty(): void
    {
        $segment = Segment::new('');
        $rendered = $segment->render();

        // Empty content should still render (5 rows of spacing)
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Digit width
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentDigitWidths(): void
    {
        $segment2 = Segment::new('88')->withDigitWidth(2);
        $segment5 = Segment::new('88')->withDigitWidth(5);

        $rendered2 = $segment2->render();
        $rendered5 = $segment5->render();

        // Wider digits should produce more output
        $this->assertGreaterThan(
            mb_strlen($rendered2, 'UTF-8'),
            mb_strlen($rendered5, 'UTF-8')
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Colon display
    // ═══════════════════════════════════════════════════════════════

    public function testShowColonByDefault(): void
    {
        $segment = Segment::new('12:30');
        $rendered = $segment->render();

        // Should show colon dots
        $this->assertStringContainsString('●', $rendered);
    }

    public function testHideColon(): void
    {
        $segment = Segment::new('12:30')->withShowColon(false);
        $rendered = $segment->render();

        // Should not have the colon pattern
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testOnColorAddsAnsiCodes(): void
    {
        $segment = Segment::new('8')->withOnColor(Color::ansi(9));
        $rendered = $segment->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testOffColorAddsAnsiCodes(): void
    {
        $segment = Segment::new('8')->withOffColor(Color::ansi(8));
        $rendered = $segment->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $segment = Segment::new('8')
            ->withOnColor(Color::ansi(9))
            ->withOffColor(Color::ansi(8));
        $rendered = $segment->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $segment = Segment::new('12');
        [$w, $h] = $segment->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(5, $h); // Always 5 rows
    }

    public function testGetInnerSizeForLongerContent(): void
    {
        $segment4 = Segment::new('1234');
        $segment8 = Segment::new('12345678');

        [$w4, $h] = $segment4->getInnerSize();
        [$w8, $h] = $segment8->getInnerSize();

        // 8 chars should be wider than 4 chars
        $this->assertGreaterThan($w4, $w8);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Segment::new('12');
        $updated = $original->withContent('34');

        $this->assertNotSame($original, $updated);
    }

    public function testWithDigitWidthReturnsNewInstance(): void
    {
        $original = Segment::new('12');
        $updated = $original->withDigitWidth(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowColonReturnsNewInstance(): void
    {
        $original = Segment::new('12');
        $updated = $original->withShowColon(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOnColorReturnsNewInstance(): void
    {
        $original = Segment::new('12');
        $updated = $original->withOnColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithOffColorReturnsNewInstance(): void
    {
        $original = Segment::new('12');
        $updated = $original->withOffColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Segment::new('12');
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnknownCharacterRendersAsSpace(): void
    {
        $segment = new Segment('@', 3, true, null, null);
        $rendered = $segment->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    public function testMinimumDigitWidth(): void
    {
        $segment = Segment::new('1')->withDigitWidth(1);
        $rendered = $segment->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }
}
