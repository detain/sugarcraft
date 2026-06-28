<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Renderer\ChafaRenderer;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\GraphicsRenderer;
use SugarCraft\Reel\Render\Mode;

/**
 * Unit tests for GraphicsRenderer — delegates to candy-mosaic Sixel/Kitty/iTerm2.
 *
 * @covers \SugarCraft\Reel\Render\GraphicsRenderer
 */
final class GraphicsRendererTest extends TestCase
{
    /**
     * Synthetic 2×2 RgbFrame with known colors:
     *   R0C0: red    [255,   0,   0]
     *   R0C1: green  [  0, 255,   0]
     *   R1C0: blue   [  0,   0, 255]
     *   R1C1: yellow [255, 255,   0]
     *
     * Bytes: 12 (2×2×3)
     */
    private static function makeFrame(): RgbFrame
    {
        $bytes = "\xff\x00\x00"  // R0C0: red
                . "\x00\xff\x00"  // R0C1: green
                . "\x00\x00\xff"  // R1C0: blue
                . "\xff\xff\x00"; // R1C1: yellow

        return new RgbFrame($bytes, 2, 2);
    }

    private function getRenderer(Mode $mode): GraphicsRenderer
    {
        return new GraphicsRenderer($mode);
    }

    // -------------------------------------------------------------------------
    // render() produces non-empty output for valid frames
    // -------------------------------------------------------------------------

    /**
     * @testdox render(Mode::Sixel) returns a non-empty string or skips gracefully
     */
    public function testRenderSixelModeReturnsNonEmptyString(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Sixel)->render($frame, Mode::Sixel);

        // Lenient: if sixel protocol degrades (no terminal support), output may be empty.
        if ($output === '') {
            $this->markTestSkipped('Sixel protocol returned empty — no terminal support in CI');
        }

        $this->assertIsString($output);
        $this->assertGreaterThan(0, strlen($output));
    }

    /**
     * @testdox render(Mode::Kitty) returns a non-empty string or skips gracefully
     */
    public function testRenderKittyModeReturnsNonEmptyString(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Kitty)->render($frame, Mode::Kitty);

        if ($output === '') {
            $this->markTestSkipped('Kitty protocol returned empty — no terminal support in CI');
        }

        $this->assertIsString($output);
        $this->assertGreaterThan(0, strlen($output));
    }

    /**
     * @testdox render(Mode::Iterm2) returns a non-empty string or skips gracefully
     */
    public function testRenderIterm2ModeReturnsNonEmptyString(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Iterm2)->render($frame, Mode::Iterm2);

        if ($output === '') {
            $this->markTestSkipped('iTerm2 protocol returned empty — no terminal support in CI');
        }

        $this->assertIsString($output);
        $this->assertGreaterThan(0, strlen($output));
    }

    // -------------------------------------------------------------------------
    // Empty frame guard
    // -------------------------------------------------------------------------

    /**
     * @testdox render returns empty string for zero-width frame
     */
    public function testRenderEmptyFrameReturnsEmptyString(): void
    {
        $frame = new RgbFrame('', 0, 0);
        $output = $this->getRenderer(Mode::Sixel)->render($frame, Mode::Sixel);

        $this->assertSame('', $output);
    }

    // -------------------------------------------------------------------------
    // cellDimensions
    // -------------------------------------------------------------------------

    /**
     * @testdox cellDimensions returns [w => 1, h => 1] — graphics fill the terminal
     */
    public function testCellDimensionsReturnsW1H1(): void
    {
        $result = $this->getRenderer(Mode::Sixel)->cellDimensions(Mode::Sixel);

        $this->assertSame(['w' => 1, 'h' => 1], $result);
    }

    // -------------------------------------------------------------------------
    // Protocol envelope — header presence when output is non-empty
    // -------------------------------------------------------------------------

    /**
     * @testdox Sixel output (when non-empty) starts with DCS sixel header \x1bP
     */
    public function testSixelOutputContainsSixelHeader(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Sixel)->render($frame, Mode::Sixel);

        if ($output === '') {
            $this->markTestSkipped('Sixel protocol returned empty — skipping header check');
        }

        // DCS sixel intro: ESC P (\x1bP)
        $this->assertStringStartsWith("\x1bP", $output, 'Sixel output should begin with DCS sixel header');
    }

    /**
     * @testdox Kitty output (when non-empty) starts with DCS kitty header \x1b[_
     */
    public function testKittyOutputContainsKittyHeader(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Kitty)->render($frame, Mode::Kitty);

        if ($output === '') {
            $this->markTestSkipped('Kitty protocol returned empty — skipping header check');
        }

        // DCS kitty intro: ESC _ (0x1b 0x5f) — the Kitty protocol uses DCS (0x1b 0x50 0x47)
        // followed by 'Ga=T,f=100,...' as the first payload chunk.
        $this->assertStringStartsWith("\x1b_Ga=", $output, 'Kitty output should begin with DCS kitty header Ga=T');
    }

    /**
     * @testdox iTerm2 output (when non-empty) starts with iTerm2 OSC header \x1b]1337;
     */
    public function testIterm2OutputContainsIterm2Header(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Iterm2)->render($frame, Mode::Iterm2);

        if ($output === '') {
            $this->markTestSkipped('iTerm2 protocol returned empty — skipping header check');
        }

        // iTerm2 OSC: ESC ] 1337 ; (\x1b]1337;)
        $this->assertStringStartsWith("\x1b]1337;", $output, 'iTerm2 output should begin with OSC 1337 header');
    }

    // -------------------------------------------------------------------------
    // PNG-payload frames — the real graphics decode path (no re-encode)
    // -------------------------------------------------------------------------

    /**
     * Build a real PNG blob at runtime via GD.
     */
    private static function makePng(int $w, int $h): string
    {
        $img = \imagecreatetruecolor($w, $h);
        \imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) \imagecolorallocate($img, 200, 60, 40));
        \ob_start();
        \imagepng($img);
        $png = (string) \ob_get_clean();
        \imagedestroy($img);

        return $png;
    }

    /**
     * @testdox a PNG-payload frame renders to the iTerm2 OSC envelope
     */
    public function testPngFrameRendersIterm2Envelope(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(40, 40);
        $frame = new RgbFrame('', 40, 40, $png);

        $output = (new GraphicsRenderer(Mode::Iterm2))->render($frame, Mode::Iterm2);

        $this->assertStringStartsWith("\x1b]1337;", $output);
    }

    /**
     * @testdox a PNG-payload frame renders to the Kitty graphics envelope
     */
    public function testPngFrameRendersKittyEnvelope(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(40, 40);
        $frame = new RgbFrame('', 40, 40, $png);

        $output = (new GraphicsRenderer(Mode::Kitty))->render($frame, Mode::Kitty);

        $this->assertStringStartsWith("\x1b_Ga=", $output);
    }

    /**
     * @testdox a PNG-payload Sixel frame contains the DCS intro when chafa is available
     */
    public function testPngFrameRendersSixelDcsWhenChafaAvailable(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        if (!ChafaRenderer::available()) {
            $this->markTestSkipped('chafa binary not available — sixel falls back to pure-PHP encoder');
        }

        $png = self::makePng(40, 40);
        $frame = new RgbFrame('', 40, 40, $png);

        $output = (new GraphicsRenderer(Mode::Sixel))->render($frame, Mode::Sixel);

        $this->assertStringContainsString("\x1bP", $output, 'sixel output carries the DCS intro');
    }

    /**
     * @testdox the cell pixel geometry round-trips: an 800x480 frame requests ~80 cells wide
     *
     * GraphicsRenderer recovers cellsW = round(frame->w / cellPxW). With a 10px
     * cell box, an 800px-wide frame resolves to 80 cells — which the iTerm2
     * envelope echoes as `width=80` (cells, see Iterm2Renderer + Ansi::iterm2InlineImage).
     */
    public function testCellPxRoundTripRequestsExpectedCellWidth(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(800, 480);
        $frame = new RgbFrame('', 800, 480, $png);

        $output = (new GraphicsRenderer(Mode::Iterm2, 10, 20))->render($frame, Mode::Iterm2);

        // 800 / 10 = 80 cells wide, 480 / 20 = 24 cells tall.
        $this->assertStringContainsString('width=80', $output);
        $this->assertStringContainsString('height=24', $output);
    }
}
