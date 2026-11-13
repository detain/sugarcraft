<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Render\HalfBlockRenderer;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\QuarterBlockRenderer;
use SugarCraft\Video\Render\RendererFactory;

/**
 * Unit tests for HalfBlockRenderer — delegates to sugar-image.
 *
 * @covers \SugarCraft\Video\Render\HalfBlockRenderer
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
    public function testDelegatesToSugarImage(): void
    {
        $frame = self::makeFrame();

        // This test will fail if ImageHalfBlockRenderer is not available
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

    // -------------------------------------------------------------------------
    // SV-L72/072: gd-less seat answers the bridge with a typed refusal
    // -------------------------------------------------------------------------

    /**
     * @testdox SV-L72: on a gd-less build the half-block bridge refuses TYPED at render entry (it used to fatal on an undefined GD function mid-paint), while construction — the Player's own door — stays gd-free
     */
    public function testGdLessSeatRefusesTypedAtRenderEntryNotAtConstruction(): void
    {
        RendererFactory::setGraphicsGateForTesting(static fn (): bool => false);
        try {
            // The Player runtime paints blocks inline and never calls through
            // here, so refusing CONSTRUCTION would lie about its block rung.
            $renderer = RendererFactory::new(Mode::HalfBlock);
            $this->assertInstanceOf(HalfBlockRenderer::class, $renderer, 'the construction door must stay gd-free');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ext-gd');
            $renderer->render(self::makeFrame(), Mode::HalfBlock);
        } finally {
            RendererFactory::setGraphicsGateForTesting(null);
        }
    }

    /**
     * @testdox SV-L72: the quarter-block twin crosses the same GD bridge and answers the same typed refusal at entry
     */
    public function testGdLessSeatRefusesTypedAtQuarterBlockRenderEntry(): void
    {
        RendererFactory::setGraphicsGateForTesting(static fn (): bool => false);
        try {
            $renderer = RendererFactory::new(Mode::QuarterBlock);
            $this->assertInstanceOf(QuarterBlockRenderer::class, $renderer, 'the construction door must stay gd-free');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ext-gd');
            $renderer->render(self::makeFrame(), Mode::QuarterBlock);
        } finally {
            RendererFactory::setGraphicsGateForTesting(null);
        }
    }
}
