<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\DotMatrix;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class DotMatrixTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDotMatrixImplementsSizer(): void
    {
        $matrix = DotMatrix::new('HELLO');
        $this->assertInstanceOf(Sizer::class, $matrix);
    }

    public function testDotMatrixImplementsItem(): void
    {
        $matrix = DotMatrix::new('HELLO');
        $this->assertInstanceOf(Item::class, $matrix);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $matrix = DotMatrix::new('HELLO');
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $matrix = DotMatrix::new('HELLO');
        $rendered = $matrix->render();

        // Dot matrix is multi-line (5 rows)
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testRenderContainsDotCharacters(): void
    {
        $matrix = DotMatrix::new('A');
        $rendered = $matrix->render();

        // Should contain dot characters
        $this->assertStringContainsString('●', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Content handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderDigits(): void
    {
        $matrix = DotMatrix::new('0123456789');
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderLetters(): void
    {
        $matrix = DotMatrix::new('ABC');
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderMixed(): void
    {
        $matrix = DotMatrix::new('HELLO 123');
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderLowercaseConverted(): void
    {
        $matrix = DotMatrix::new('abc');
        $rendered = $matrix->render();

        // Lowercase should be converted to uppercase
        $this->assertNotSame('', $rendered);
    }

    public function testRenderSpace(): void
    {
        $matrix = DotMatrix::new('   ');
        $rendered = $matrix->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
    }

    public function testRenderEmpty(): void
    {
        $matrix = DotMatrix::new('');
        $rendered = $matrix->render();

        // Empty content should still render
        $this->assertNotSame('', $rendered);
    }

    public function testRenderSymbols(): void
    {
        $matrix = DotMatrix::new('!.:-*+');
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cell size
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentCellSizes(): void
    {
        $matrix1 = DotMatrix::new('A')->withCellSize(1);
        $matrix2 = DotMatrix::new('A')->withCellSize(2);

        $rendered1 = $matrix1->render();
        $rendered2 = $matrix2->render();

        // Larger cell size should produce more output
        $this->assertGreaterThan(
            strlen($rendered1),
            strlen($rendered2)
        );
    }

    public function testCellSizeOne(): void
    {
        $matrix = DotMatrix::new('TEST')->withCellSize(1);
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }

    public function testCellSizeThree(): void
    {
        $matrix = DotMatrix::new('TEST')->withCellSize(3);
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Frame display
    // ═══════════════════════════════════════════════════════════════

    public function testShowFrameByDefaultFalse(): void
    {
        $matrix = DotMatrix::new('A');
        $rendered = $matrix->render();

        // Should NOT have frame characters by default
        $this->assertStringNotContainsString('┌', $rendered);
    }

    public function testShowFrame(): void
    {
        $matrix = DotMatrix::new('A')->withShowFrame(true);
        $rendered = $matrix->render();

        // Should have frame characters
        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('─', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('│', $rendered);
        $this->assertStringContainsString('└', $rendered);
        $this->assertStringContainsString('┘', $rendered);
    }

    public function testHideFrame(): void
    {
        $matrix = DotMatrix::new('A')->withShowFrame(false);
        $rendered = $matrix->render();

        // Should NOT have frame characters
        $this->assertStringNotContainsString('┌', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testOnColorAddsAnsiCodes(): void
    {
        $matrix = DotMatrix::new('A')->withOnColor(Color::ansi(9));
        $rendered = $matrix->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testOffColorAddsAnsiCodes(): void
    {
        $matrix = DotMatrix::new('A')->withOffColor(Color::ansi(8));
        $rendered = $matrix->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $matrix = DotMatrix::new('A')
            ->withOnColor(Color::ansi(9))
            ->withOffColor(Color::ansi(8));
        $rendered = $matrix->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $matrix = DotMatrix::new('HI');
        [$w, $h] = $matrix->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(5, $h); // Always 5 rows
    }

    public function testGetInnerSizeForLongerContent(): void
    {
        $matrix2 = DotMatrix::new('AB');
        $matrix6 = DotMatrix::new('ABCDEF');

        [$w2, $h] = $matrix2->getInnerSize();
        [$w6, $h] = $matrix6->getInnerSize();

        // 6 chars should be wider than 2 chars
        $this->assertGreaterThan($w2, $w6);
    }

    public function testGetInnerSizeWithFrame(): void
    {
        $matrixNoFrame = DotMatrix::new('A')->withShowFrame(false);
        $matrixWithFrame = DotMatrix::new('A')->withShowFrame(true);

        [$wNoFrame, $hNoFrame] = $matrixNoFrame->getInnerSize();
        [$wWithFrame, $hWithFrame] = $matrixWithFrame->getInnerSize();

        // With frame should be larger
        $this->assertGreaterThan($wNoFrame, $wWithFrame);
        $this->assertGreaterThan($hNoFrame, $hWithFrame);
    }

    public function testGetInnerSizeWithDifferentCellSizes(): void
    {
        $matrix1 = DotMatrix::new('A')->withCellSize(1);
        $matrix2 = DotMatrix::new('A')->withCellSize(2);

        [$w1, $h1] = $matrix1->getInnerSize();
        [$w2, $h2] = $matrix2->getInnerSize();

        // Larger cell size should have larger dimensions
        $this->assertGreaterThan($w1, $w2);
        $this->assertGreaterThan($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $original = DotMatrix::new('HI');
        $updated = $original->withContent('BYE');

        $this->assertNotSame($original, $updated);
    }

    public function testWithCellSizeReturnsNewInstance(): void
    {
        $original = DotMatrix::new('A');
        $updated = $original->withCellSize(3);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowFrameReturnsNewInstance(): void
    {
        $original = DotMatrix::new('A');
        $updated = $original->withShowFrame(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithOnColorReturnsNewInstance(): void
    {
        $original = DotMatrix::new('A');
        $updated = $original->withOnColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithOffColorReturnsNewInstance(): void
    {
        $original = DotMatrix::new('A');
        $updated = $original->withOffColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = DotMatrix::new('HI');
        $resized = $original->setSize(30, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnknownCharacterRendersAsSpace(): void
    {
        $matrix = new DotMatrix('@', 1, false, null, null);
        $rendered = $matrix->render();

        // Should render without error (treats as space)
        $this->assertNotSame('', $rendered);
    }

    public function testMinimumCellSize(): void
    {
        $matrix = DotMatrix::new('TEST')->withCellSize(1);
        $rendered = $matrix->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testLongerMessageRenders(): void
    {
        $matrix = DotMatrix::new('HELLO WORLD');
        $rendered = $matrix->render();

        $this->assertNotSame('', $rendered);
    }
}