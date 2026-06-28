<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Render\AsciiRenderer;
use SugarCraft\Reel\Render\FrameRenderer;
use SugarCraft\Reel\Render\GraphicsRenderer;
use SugarCraft\Reel\Render\HalfBlockRenderer;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;

/**
 * Unit tests for RendererFactory — creates FrameRenderer instances by Mode.
 *
 * @covers \SugarCraft\Reel\Render\RendererFactory
 */
final class RendererFactoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // create() returns correct renderer types
    // -------------------------------------------------------------------------

    /**
     * @testdox create(Mode::Ascii) returns an AsciiRenderer instance
     */
    public function testCreateReturnsAsciiRendererForAsciiMode(): void
    {
        $renderer = RendererFactory::create(Mode::Ascii);

        $this->assertInstanceOf(AsciiRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::Ansi256) returns an AsciiRenderer instance
     */
    public function testCreateReturnsAnsi256RendererForAnsi256Mode(): void
    {
        $renderer = RendererFactory::create(Mode::Ansi256);

        $this->assertInstanceOf(AsciiRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::TrueColor) returns an AsciiRenderer instance
     */
    public function testCreateReturnsAsciiRendererForTrueColorMode(): void
    {
        $renderer = RendererFactory::create(Mode::TrueColor);

        $this->assertInstanceOf(AsciiRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::HalfBlock) returns a HalfBlockRenderer instance
     */
    public function testCreateReturnsHalfBlockRendererForHalfBlockMode(): void
    {
        $renderer = RendererFactory::create(Mode::HalfBlock);

        $this->assertInstanceOf(HalfBlockRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    // -------------------------------------------------------------------------
    // Graphics modes return GraphicsRenderer
    // -------------------------------------------------------------------------

    /**
     * @testdox create(Mode::Sixel) returns a GraphicsRenderer instance
     */
    public function testCreateReturnsGraphicsRendererForSixelMode(): void
    {
        $renderer = RendererFactory::create(Mode::Sixel);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::Kitty) returns a GraphicsRenderer instance
     */
    public function testCreateReturnsGraphicsRendererForKittyMode(): void
    {
        $renderer = RendererFactory::create(Mode::Kitty);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create(Mode::Iterm2) returns a GraphicsRenderer instance
     */
    public function testCreateReturnsGraphicsRendererForIterm2Mode(): void
    {
        $renderer = RendererFactory::create(Mode::Iterm2);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox create() accepts explicit cellPx args and still yields a GraphicsRenderer for graphics modes
     *
     * The trailing $cellPxW/$cellPxH args (used by graphics modes to recover the
     * cell footprint and size the sixel canvas) must not change the renderer type.
     *
     * @dataProvider graphicsModeProvider
     */
    public function testCreateWithExplicitCellPxReturnsGraphicsRenderer(Mode $mode): void
    {
        $renderer = RendererFactory::create($mode, 'standard', 12, 24);

        $this->assertInstanceOf(GraphicsRenderer::class, $renderer);
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /** @return list<array{Mode}> */
    public static function graphicsModeProvider(): array
    {
        return [
            'Sixel'  => [Mode::Sixel],
            'Kitty'  => [Mode::Kitty],
            'Iterm2' => [Mode::Iterm2],
        ];
    }

    /**
     * @testdox create() with explicit cellPx leaves the text modes as their normal renderers
     */
    public function testCreateWithExplicitCellPxTextModesUnchanged(): void
    {
        $this->assertInstanceOf(AsciiRenderer::class, RendererFactory::create(Mode::Ascii, 'standard', 12, 24));
        $this->assertInstanceOf(HalfBlockRenderer::class, RendererFactory::create(Mode::HalfBlock, 'standard', 12, 24));
    }

    // -------------------------------------------------------------------------
    // auto() selection
    // -------------------------------------------------------------------------

    /**
     * @testdox auto() returns an object that implements FrameRenderer
     */
    public function testAutoReturnsRenderer(): void
    {
        $renderer = RendererFactory::auto();

        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox auto() returns a renderer without throwing (selects best available mode)
     */
    public function testAutoSelectsBestAvailableMode(): void
    {
        // auto() should never throw — it probes capabilities and falls back safely.
        $renderer = RendererFactory::auto();

        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox auto($preferred) returns create($preferred) when a preferred mode is given
     */
    public function testAutoWithPreferredModeReturnsThatRenderer(): void
    {
        // When a preferred mode is given, it should be used directly.
        $preferred = RendererFactory::auto(Mode::Ascii);
        $direct    = RendererFactory::create(Mode::Ascii);

        $this->assertInstanceOf(AsciiRenderer::class, $preferred);
        $this->assertInstanceOf(AsciiRenderer::class, $direct);
    }

    // -------------------------------------------------------------------------
    // autoMode() — returns the Mode enum case directly (F3)
    // -------------------------------------------------------------------------

    /**
     * Regression for F3. RendererFactory::autoMode() must return a Mode enum
     * case (not null, not a renderer) so Reel::play() can pass it to
     * Player::open() which requires an explicit Mode — the auto-detected mode
     * was previously only used internally by auto() and never surfaced.
     *
     * @testdox autoMode() returns a Mode enum case
     */
    public function testAutoModeReturnsModeEnumCase(): void
    {
        $mode = RendererFactory::autoMode();

        $this->assertInstanceOf(Mode::class, $mode);
    }

    /**
     * Regression for F3. autoMode() must return one of the 7 known Mode cases
     * (never null, never throw) regardless of the capabilities reported at runtime.
     *
     * @testdox autoMode() returns a valid Mode that is one of the 7 cases
     */
    public function testAutoModeReturnsValidMode(): void
    {
        $mode = RendererFactory::autoMode();

        $validModes = Mode::cases();
        $this->assertContains($mode, $validModes,
            'autoMode() must return one of the known Mode::cases()');
    }

    /**
     * Regression for F3. autoMode() must not throw even when the terminal
     * reports no special capabilities — it must fall back to Ascii at minimum.
     *
     * @testdox autoMode() does not throw on a minimal-capability terminal
     */
    public function testAutoModeDoesNotThrowWithNoCapabilities(): void
    {
        // This test just verifies the method is callable without exception.
        // The real capability probing happens at runtime; we test the
        // fallback path doesn't crash.
        $mode = RendererFactory::autoMode();

        $this->assertInstanceOf(Mode::class, $mode);
    }

    // -------------------------------------------------------------------------
    // FrameRenderer contract — all created renderers implement the interface
    // -------------------------------------------------------------------------

    /**
     * @testdox every mode that create() can produce (without throwing) has render() and cellDimensions()
     */
    public function testAllImplementedModesHaveRequiredInterfaceMethods(): void
    {
        $implementedModes = [
            Mode::Ascii,
            Mode::Ansi256,
            Mode::TrueColor,
            Mode::HalfBlock,
        ];

        foreach ($implementedModes as $mode) {
            $renderer = RendererFactory::create($mode);

            $this->assertTrue(
                method_exists($renderer, 'render'),
                "{$mode->value} renderer missing render() method"
            );
            $this->assertTrue(
                method_exists($renderer, 'cellDimensions'),
                "{$mode->value} renderer missing cellDimensions() method"
            );

            // render() must accept (RgbFrame, Mode) and return string.
            $frame = new \SugarCraft\Reel\Decode\RgbFrame("\x00\x00\x00", 1, 1);
            $result = $renderer->render($frame, $mode);
            $this->assertIsString($result, "render() should return string for {$mode->value}");

            // cellDimensions() must return array with w and h.
            $dims = $renderer->cellDimensions($mode);
            $this->assertIsArray($dims);
            $this->assertArrayHasKey('w', $dims);
            $this->assertArrayHasKey('h', $dims);
        }
    }
}
