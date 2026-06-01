<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\HalfBlockRenderer;
use SugarCraft\Reel\Render\Mode;

/**
 * Unit tests for HalfBlockRenderer — delegates to candy-mosaic.
 *
 * @covers HalfBlockRenderer
 */
final class HalfBlockRendererTest extends TestCase
{
    private static function makeFrame(): RgbFrame
    {
        // Synthetic 3×3 frame (27 bytes).
        $bytes = "\x00\x00\x00"  // R0C0: black
               . "\xff\x00\x00"  // R0C1: red
               . "\x00\xff\x00"  // R0C2: green
               . "\x00\x00\xff"  // R1C0: blue
               . "\xff\xff\x00"  // R1C1: yellow
               . "\xff\x00\xff"  // R1C2: magenta
               . "\x00\xff\xff"  // R2C0: cyan
               . "\xff\xff\xff"  // R2C1: white
               . "\x40\x40\x40"; // R2C2: dark grey

        return new RgbFrame($bytes, 3, 3);
    }

    private function getRenderer(): HalfBlockRenderer
    {
        return new HalfBlockRenderer();
    }

    // -------------------------------------------------------------------------
    // Character output
    // -------------------------------------------------------------------------

    /**
     * @testdox output contains the Unicode half-block character ▀ (U+2580)
     */
    public function testRendersHalfBlockCharacter(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::HalfBlock);

        $this->assertStringContainsString("\u{2580}", $output);
    }

    // -------------------------------------------------------------------------
    // TrueColor SGR format (38;2;RRGGBB fg, 48;2;RRGGBB bg)
    // -------------------------------------------------------------------------

    /**
     * @testdox TrueColor mode output contains 38;2; and 48;2; SGR sequences
     */
    public function testOutputContainsTrueColorSgr(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::TrueColor);

        // TrueColor: foreground 38;2;R;G;B and background 48;2;R;G;B.
        $this->assertStringContainsString("\x1b[38;2;", $output, 'Missing foreground TrueColor SGR');
        $this->assertStringContainsString("\x1b[48;2;", $output, 'Missing background TrueColor SGR');
    }

    // -------------------------------------------------------------------------
    // HalfBlock mode (the main mode for this renderer)
    // -------------------------------------------------------------------------

    /**
     * @testdox HalfBlock mode output contains TrueColor SGR and half-block glyphs
     */
    public function testHalfBlockModeOutputFormat(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::HalfBlock);

        $this->assertStringContainsString("\u{2580}", $output);
        $this->assertStringContainsString("\x1b[38;2;", $output);
        $this->assertStringContainsString("\x1b[48;2;", $output);
    }

    // -------------------------------------------------------------------------
    // cellDimensions
    // -------------------------------------------------------------------------

    /**
     * @testdox cellDimensions returns [w => 1, h => 2] for HalfBlock mode
     */
    public function testCellDimensionsReturnsW1H2(): void
    {
        $result = $this->getRenderer()->cellDimensions(Mode::HalfBlock);

        $this->assertSame(['w' => 1, 'h' => 2], $result);
    }

    // -------------------------------------------------------------------------
    // Empty frame guard
    // -------------------------------------------------------------------------

    /**
     * @testdox render returns empty string for zero-width frame
     */
    public function testRenderEmptyFrameReturnsEmpty(): void
    {
        $frame = new RgbFrame('', 0, 0);
        $output = $this->getRenderer()->render($frame, Mode::HalfBlock);

        $this->assertSame('', $output);
    }

    /**
     * @testdox render returns empty string for zero-height frame
     */
    public function testRenderZeroHeightReturnsEmpty(): void
    {
        $frame = new RgbFrame('', 3, 0);
        $output = $this->getRenderer()->render($frame, Mode::HalfBlock);

        $this->assertSame('', $output);
    }

    // -------------------------------------------------------------------------
    // Integration-style: valid RgbFrame produces non-empty output
    // -------------------------------------------------------------------------

    /**
     * @testdox a valid 3×3 RgbFrame renders without throwing and produces non-empty output
     */
    public function testDelegatesToCandyMosaic(): void
    {
        $frame = self::makeFrame();

        // This test will fail if MosaicHalfBlockRenderer is not available
        // or if the GD image conversion fails.
        $output = $this->getRenderer()->render($frame, Mode::HalfBlock);

        $this->assertNotEmpty($output, 'HalfBlockRenderer should produce non-empty output for a valid frame');
    }

    /**
     * @testdox output length is non-zero for a valid frame
     */
    public function testOutputIsNonEmptyForValidFrame(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::HalfBlock);

        $this->assertGreaterThan(0, strlen($output));
    }
}
