<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\AsciiRenderer;
use SugarCraft\Reel\Render\LumaRamp;
use SugarCraft\Reel\Render\Mode;

/**
 * Unit tests for AsciiRenderer — ASCII and ANSI rendering of RgbFrames.
 *
 * Synthetic 3×3 frame used across tests:
 *   Row 0: black [0,0,0],  red [255,0,0],  green [0,255,0]
 *   Row 1: blue [0,0,255], yellow [255,255,0], magenta [255,0,255]
 *   Row 2: cyan [0,255,255], white [255,255,255], dark grey [64,64,64]
 *
 * @covers AsciiRenderer
 */
final class AsciiRendererTest extends TestCase
{
    // 27 bytes (3×3×3), row-major RGB:
    // R0C0=black, R0C1=red, R0C2=green, R1C0=blue, R1C1=yellow, R1C2=magenta, R2C0=cyan, R2C1=white, R2C2=dark_grey
    private const SYNTHETIC_BYTES =
        "\x00\x00\x00"  // R0C0: black
        . "\xff\x00\x00"  // R0C1: red
        . "\x00\xff\x00"  // R0C2: green
        . "\x00\x00\xff"  // R1C0: blue
        . "\xff\xff\x00"  // R1C1: yellow
        . "\xff\x00\xff"  // R1C2: magenta
        . "\x00\xff\xff"  // R2C0: cyan
        . "\xff\xff\xff"  // R2C1: white
        . "\x40\x40\x40"; // R2C2: dark grey

    private static function makeFrame(): RgbFrame
    {
        return new RgbFrame(self::SYNTHETIC_BYTES, 3, 3);
    }

    private function getRenderer(): AsciiRenderer
    {
        return new AsciiRenderer();
    }

    // -------------------------------------------------------------------------
    // Mode::Ascii — no color, grayscale chars only
    // -------------------------------------------------------------------------

    /**
     * @testdox Ascii mode renders no SGR escapes, only ASCII characters and newlines
     */
    public function testRenderAsciiMode(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::Ascii);

        // Must not contain any ANSI escape sequences.
        $this->assertStringNotContainsString("\x1b", $output);

        // Must be non-empty.
        $this->assertNotEmpty($output);

        // Must contain only printable ASCII characters (0x20-0x7E) and newlines.
        $lines = explode("\r\n", $output);
        $this->assertCount(3, $lines);

        foreach ($lines as $line) {
            for ($i = 0; $i < strlen($line); $i++) {
                $ord = ord($line[$i]);
                $this->assertTrue(
                    ($ord >= 0x20 && $ord <= 0x7E) || $ord === 0x09,
                    "Char 0x" . dechex($ord) . " at position {$i} in '{$line}' is not printable ASCII"
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Mode::TrueColor — 38;2;R;G;B foreground, no ▀ (that's HalfBlock)
    // -------------------------------------------------------------------------

    /**
     * @testdox TrueColor mode output contains 38;2; SGR foreground sequences
     */
    public function testRenderTrueColorMode(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::TrueColor);

        // Must contain TrueColor 38;2; foreground sequences.
        $this->assertStringContainsString("\x1b[38;2;", $output);

        // Must NOT contain the half-block character (that's HalfBlockRenderer).
        $this->assertStringNotContainsString("\u{2580}", $output);
    }

    // -------------------------------------------------------------------------
    // Mode::Ansi256 — 38;5;N foreground
    // -------------------------------------------------------------------------

    /**
     * @testdox Ansi256 mode output contains 38;5; SGR foreground sequences
     */
    public function testRenderAnsi256Mode(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::Ansi256);

        // Must contain ANSI 256-color 38;5; foreground sequences.
        $this->assertStringContainsString("\x1b[38;5;", $output);
    }

    // -------------------------------------------------------------------------
    // SGR reset — no color bleed between adjacent cells
    // -------------------------------------------------------------------------

    /**
     * @testdox TrueColor mode resets SGR after each character cell (no color bleed)
     */
    public function testRenderResetsAfterEachCharacter(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::TrueColor);

        // In TrueColor mode, each cell is: SGR + char + reset.
        // After each reset, the next SGR sequence should start fresh.
        // Count occurrences of the reset sequence — should equal the number of cells (9).
        $resetCount = substr_count($output, "\x1b[0m");

        // 3×3 = 9 cells, so we expect at least 9 resets (one per cell).
        $this->assertSame(9, $resetCount, "Expected 9 SGR resets (one per cell) in TrueColor output, got {$resetCount}");
    }

    // -------------------------------------------------------------------------
    // Empty frame
    // -------------------------------------------------------------------------

    /**
     * @testdox Empty frame (w=0) returns empty string
     */
    public function testRenderEmptyFrameReturnsEmptyString(): void
    {
        $frame = new RgbFrame('', 0, 0);
        $output = $this->getRenderer()->render($frame, Mode::Ascii);

        $this->assertSame('', $output);
    }

    /**
     * @testdox Frame with h=0 returns empty string
     */
    public function testRenderZeroHeightFrameReturnsEmpty(): void
    {
        $frame = new RgbFrame('', 3, 0);
        $output = $this->getRenderer()->render($frame, Mode::Ascii);

        $this->assertSame('', $output);
    }

    // -------------------------------------------------------------------------
    // Black pixel → darkest character
    // -------------------------------------------------------------------------

    /**
     * @testdox Black pixel [0,0,0] renders as the darkest character in the ramp
     */
    public function testRenderBlackPixelIsDarkChar(): void
    {
        // Pure black frame (1×1 pixel, RGB=0,0,0).
        $blackBytes = "\x00\x00\x00";
        $frame = new RgbFrame($blackBytes, 1, 1);

        $output = $this->getRenderer()->render($frame, Mode::Ascii);

        // The rendered output should be the darkest character in the standard ramp.
        // For a 1×1 frame, output is exactly 1 character (no row delimiter since 1 row).
        $darkestChar = LumaRamp::char(0.0);

        // Do NOT use trim() — it strips the space character! Compare the first char.
        $this->assertGreaterThanOrEqual(1, strlen($output));
        $this->assertSame($darkestChar, $output[0]);
    }

    // -------------------------------------------------------------------------
    // cellDimensions
    // -------------------------------------------------------------------------

    /**
     * @testdox cellDimensions returns [w => 1, h => 1] for all ASCII modes
     */
    public function testCellDimensionsReturnsW1H1(): void
    {
        $renderer = $this->getRenderer();

        $this->assertSame(['w' => 1, 'h' => 1], $renderer->cellDimensions(Mode::Ascii));
        $this->assertSame(['w' => 1, 'h' => 1], $renderer->cellDimensions(Mode::Ansi256));
        $this->assertSame(['w' => 1, 'h' => 1], $renderer->cellDimensions(Mode::TrueColor));
    }

    // -------------------------------------------------------------------------
    // Row count matches frame height
    // -------------------------------------------------------------------------

    /**
     * @testdox output has one line per frame row
     */
    public function testOutputHasOneLinePerFrameRow(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::Ascii);

        $lines = explode("\r\n", $output);
        $this->assertCount(3, $lines);
    }

    /**
     * @testdox each output line has one character per frame column
     */
    public function testEachLineHasOneCharPerColumn(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::Ascii);

        $lines = explode("\r\n", $output);
        foreach ($lines as $line) {
            $this->assertSame(3, strlen($line), "Line '{$line}' should be 3 characters for a 3-wide frame");
        }
    }

    // -------------------------------------------------------------------------
    // Ansi256 output uses 38;5;N format (not 38;2;)
    // -------------------------------------------------------------------------

    /**
     * @testdox Ansi256 mode does NOT contain 38;2; (uses 38;5; instead)
     */
    public function testAnsi256ModeDoesNotContainTrueColorSgr(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer()->render($frame, Mode::Ansi256);

        // Ansi256 must use 38;5; format, NOT 38;2;.
        $this->assertStringContainsString("\x1b[38;5;", $output);
        $this->assertStringNotContainsString("\x1b[38;2;", $output);
    }
}
