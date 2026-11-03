<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\Video\Render\FrameRenderer;
use SugarCraft\Video\Render\GraphicsRenderer;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;

/**
 * The M1 capability door and the M28 probe memo.
 *
 * @covers \SugarCraft\Video\Render\RendererFactory
 */
final class CapabilityDoorTest extends TestCase
{
    private const GRAPHICS = [
        Capability::Sixel->value => true,
        Capability::KittyKeyboard->value => true,
        Capability::ITerm2->value => true,
    ];

    protected function tearDown(): void
    {
        RendererFactory::setDiagnoseForTesting(null);
    }

    public function testGraphicsRungsPassTheDoorWhenTheSeatClaimsThem(): void
    {
        RendererFactory::setDiagnoseForTesting(new ProbeReport(self::GRAPHICS));

        foreach ([Mode::Sixel, Mode::Kitty, Mode::Iterm2] as $mode) {
            $this->assertTrue(RendererFactory::supports($mode));
            $this->assertInstanceOf(GraphicsRenderer::class, RendererFactory::new($mode));
        }
    }

    public function testAnUnsupportedGraphicsRungIsALoudRefusalNotEscapeBytes(): void
    {
        RendererFactory::setDiagnoseForTesting(new ProbeReport([]));

        foreach ([Mode::Sixel, Mode::Kitty, Mode::Iterm2] as $mode) {
            $this->assertFalse(RendererFactory::supports($mode));
            try {
                RendererFactory::new($mode);
                $this->fail("{$mode->name} must not build on a text-only seat");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('is not supported', $e->getMessage());
                $this->assertStringContainsString($mode->label(), $e->getMessage());
                $this->assertStringContainsString(
                    RendererFactory::autoMode()->label(),
                    $e->getMessage(),
                    'the refusal must name the mode this seat actually gets',
                );
            }
        }
    }

    public function testTextRungsAreAlwaysTransportSafe(): void
    {
        RendererFactory::setDiagnoseForTesting(new ProbeReport([]));

        foreach ([Mode::Ascii, Mode::Ansi256, Mode::TrueColor, Mode::HalfBlock, Mode::QuarterBlock] as $mode) {
            $this->assertTrue(RendererFactory::supports($mode));
            $this->assertInstanceOf(FrameRenderer::class, RendererFactory::new($mode));
        }
    }

    public function testTheLiveProbeIsMemoizedOncePerProcess(): void
    {
        RendererFactory::setDiagnoseForTesting(null);

        $first = RendererFactory::diagnose();
        $this->assertSame($first, RendererFactory::diagnose(), 'M28: one negotiation per process, not per keypress');
    }

    public function testEveryAcquisitionPathFlowsThroughTheSingleDoor(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Render/RendererFactory.php');
        $this->assertSame(
            1,
            substr_count($src, '= Mosaic::diagnose();'),
            'the live probe is called from exactly one memoized site',
        );
        $this->assertSame(1, substr_count($src, 'self::supports($mode)'), 'one door, consulted by new()');

        $player = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Player.php');
        $this->assertStringNotContainsString(
            'Mosaic::diagnose',
            $player,
            'the mode cycle rides the shared door, not a private re-probe',
        );
    }

    /**
     * @testdox m34: the graphics door refuses typed when ext-gd is absent
     */
    public function testTheGraphicsDoorCarriesTheImageExtensionGate(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Render/RendererFactory.php');

        $this->assertStringContainsString("function_exists('imagecreatetruecolor')", $src);
        $this->assertStringContainsString('decoder.gd_missing', $src);
        $this->assertStringContainsString('isGraphics()', $src);
    }
}
