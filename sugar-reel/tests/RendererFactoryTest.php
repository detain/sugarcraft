<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Render\AsciiRenderer;
use SugarCraft\Reel\Render\FrameRenderer;
use SugarCraft\Reel\Render\HalfBlockRenderer;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;

/**
 * Unit tests for RendererFactory — creates FrameRenderer instances by Mode.
 *
 * @covers RendererFactory
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
    // Unimplemented modes throw InvalidArgumentException
    // -------------------------------------------------------------------------

    /**
     * @testdox create(Mode::Sixel) throws InvalidArgumentException (Step 6 scope)
     */
    public function testCreateThrowsForSixelMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sixel');

        RendererFactory::create(Mode::Sixel);
    }

    /**
     * @testdox create(Mode::Kitty) throws InvalidArgumentException (Step 6 scope)
     */
    public function testCreateThrowsForKittyMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kitty');

        RendererFactory::create(Mode::Kitty);
    }

    /**
     * @testdox create(Mode::Iterm2) throws InvalidArgumentException (Step 6 scope)
     */
    public function testCreateThrowsForIterm2Mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('iterm2');

        RendererFactory::create(Mode::Iterm2);
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
