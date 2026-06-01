<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\AsciiRenderer;
use SugarCraft\Reel\Render\FrameRenderer;
use SugarCraft\Reel\Render\HalfBlockRenderer;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;

/**
 * Unit tests verifying the FrameRenderer interface contract.
 *
 * @covers FrameRenderer
 */
final class FrameRendererTest extends TestCase
{
    /**
     * @testdox FrameRenderer interface exists in the correct namespace
     */
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(FrameRenderer::class));
    }

    /**
     * @testdox FrameRenderer declares render(RgbFrame, Mode): string method
     */
    public function testInterfaceHasRenderMethod(): void
    {
        $rc = new \ReflectionClass(FrameRenderer::class);
        $this->assertTrue($rc->hasMethod('render'));

        $method = $rc->getMethod('render');
        $params = $method->getParameters();

        $this->assertCount(2, $params);

        // First param: RgbFrame $frame.
        $this->assertSame('frame', $params[0]->getName());
        $this->assertSame(RgbFrame::class, $params[0]->getType()->getName());

        // Second param: Mode $mode.
        $this->assertSame('mode', $params[1]->getName());
        $this->assertSame(Mode::class, $params[1]->getType()->getName());

        // Return type: string.
        $this->assertSame('string', $method->getReturnType()->getName());
    }

    /**
     * @testdox FrameRenderer declares cellDimensions(Mode): array{w:int,h:int} method
     */
    public function testInterfaceHasCellDimensionsMethod(): void
    {
        $rc = new \ReflectionClass(FrameRenderer::class);
        $this->assertTrue($rc->hasMethod('cellDimensions'));

        $method = $rc->getMethod('cellDimensions');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('mode', $params[0]->getName());
        $this->assertSame(Mode::class, $params[0]->getType()->getName());

        // Return type should be array (no named typed array in PHP 8.3 without asserts).
        $this->assertSame('array', $method->getReturnType()->getName());
    }

    /**
     * @testdox AsciiRenderer implements FrameRenderer
     */
    public function testAsciiRendererImplementsFrameRenderer(): void
    {
        $renderer = new AsciiRenderer();
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox HalfBlockRenderer implements FrameRenderer
     */
    public function testHalfBlockRendererImplementsFrameRenderer(): void
    {
        $renderer = new HalfBlockRenderer();
        $this->assertInstanceOf(FrameRenderer::class, $renderer);
    }

    /**
     * @testdox all renderers from RendererFactory::create() implement FrameRenderer
     */
    public function testFactoryCreateReturnsFrameRendererImplementations(): void
    {
        $modes = [Mode::Ascii, Mode::Ansi256, Mode::TrueColor, Mode::HalfBlock];

        foreach ($modes as $mode) {
            $renderer = RendererFactory::create($mode);
            $this->assertInstanceOf(
                FrameRenderer::class,
                $renderer,
                "Mode::{$mode->name} renderer must implement FrameRenderer"
            );
        }
    }
}
