<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\AdaptiveImage;
use SugarCraft\Mosaic\Capability;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\PrecomputedImage;
use SugarCraft\Mosaic\Renderer\AsciiRenderer;
use SugarCraft\Mosaic\Renderer\AsciiColorMode;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\Iterm2Renderer;
use SugarCraft\Mosaic\Renderer\QuarterBlockRenderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\Scale;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;

/**
 * @covers \SugarCraft\Mosaic\Mosaic
 */
final class MosaicTest extends TestCase
{
    private string $fixture4x2;

    protected function setUp(): void
    {
        $this->fixture4x2 = __DIR__ . '/fixtures/4x2.png';
        if (!file_exists($this->fixture4x2)) {
            $this->markTestSkipped('Fixture tests/fixtures/4x2.png missing');
        }
    }

    // ─── Factory methods ───────────────────────────────────────────────────────

    public function testHalfBlockFactory(): void
    {
        $m = Mosaic::halfBlock();
        $this->assertSame('halfblock', $m->protocol());
        $this->assertInstanceOf(HalfBlockRenderer::class, $this->getRenderer($m));
    }

    public function testIterm2Factory(): void
    {
        $m = Mosaic::iterm2();
        $this->assertSame('iterm2', $m->protocol());
        $this->assertInstanceOf(Iterm2Renderer::class, $this->getRenderer($m));
    }

    public function testKittyProtocolThroughAuto(): void
    {
        // Pins the auto() detection path for kitty (a direct factory exists
        // too — see testKittyFactoryMirrorsForceFactories).
        // Test that kitty capability is detected when available
        // Clear TMUX to avoid tmux() wrapper prefix
        putenv('TMUX');
        putenv('TERM_PROGRAM=WezTerm');
        $m = Mosaic::auto();
        $protocol = $m->protocol();
        // WezTerm may return 'kitty' or 'tmux(kitty)' depending on environment
        $this->assertStringContainsString('kitty', $protocol);
        putenv('TERM_PROGRAM'); // clean up
    }

    public function testQuarterBlockFactory(): void
    {
        $m = Mosaic::quarterBlock();
        $this->assertSame('quarterblock', $m->protocol());
        $this->assertInstanceOf(QuarterBlockRenderer::class, $this->getRenderer($m));
    }

    public function testAsciiFactoryMono(): void
    {
        $m = Mosaic::ascii();
        $this->assertSame('ascii', $m->protocol());
        $this->assertInstanceOf(AsciiRenderer::class, $this->getRenderer($m));
    }

    public function testAsciiFactoryTrueColor(): void
    {
        $m = Mosaic::ascii(AsciiColorMode::TrueColor);
        $this->assertSame('truecolor', $m->protocol());
    }

    public function testAsciiFactoryAnsi256(): void
    {
        $m = Mosaic::ascii(AsciiColorMode::Ansi256);
        $this->assertSame('ansi256', $m->protocol());
    }

    public function testSixelFactoryDefault(): void
    {
        $m = Mosaic::sixel();
        $this->assertSame('sixel', $m->protocol());
        $this->assertInstanceOf(SixelRenderer::class, $this->getRenderer($m));
    }

    public function testSixelFactoryWithDither(): void
    {
        $m = Mosaic::sixel(Dither::Stucki);
        $this->assertSame('sixel', $m->protocol());
    }

    public function testSixelFactoryWithDitherNone(): void
    {
        $m = Mosaic::sixel(Dither::None);
        $this->assertSame('sixel', $m->protocol());
    }

    public function testChafaFactoryDefault(): void
    {
        $m = Mosaic::chafa();
        $this->assertSame('chafa', $m->protocol());
    }

    public function testChafaFactoryWithOptions(): void
    {
        $m = Mosaic::chafa('--colors=16', '--work=n');
        $this->assertSame('chafa', $m->protocol());
    }

    // ─── Instance methods ─────────────────────────────────────────────────────

    public function testWithDitherOnSixelRenderer(): void
    {
        $m = Mosaic::sixel(Dither::FloydSteinberg);
        $m2 = $m->withDither(Dither::Atkinson);

        // Returns new instance carrying the requested dither
        $this->assertNotSame($m, $m2);
        $this->assertSame(Dither::Atkinson, $m2->renderer()->dither());
        $this->assertSame(Dither::FloydSteinberg, $m->renderer()->dither());
    }

    public function testWithDitherPreservesSixelColorAndCellTuning(): void
    {
        $m = new Mosaic(new SixelRenderer(Dither::FloydSteinberg, 16, 12, 24), Capability::universal(), null, null, null);
        $m2 = $m->withDither(Dither::Atkinson);

        // Re-tinting swaps only the dither — the colour budget and the real
        // terminal cell geometry survive (a reset to defaults would mis-scale
        // the pixel canvas).
        $this->assertSame(Dither::Atkinson, $m2->renderer()->dither());
        $this->assertSame(16, $m2->renderer()->maxColors());
    }

    public function testWithDitherThroughTmuxEnvelopePeelsAndReapplies(): void
    {
        $inner = new SixelRenderer(Dither::FloydSteinberg, 32, 10, 20);
        $m = new Mosaic(new TmuxPassthroughDecorator($inner), Capability::universal(), null, null, null);
        $m2 = $m->withDither(Dither::Atkinson);

        $renderer = $m2->renderer();
        $this->assertInstanceOf(TmuxPassthroughDecorator::class, $renderer);
        $this->assertInstanceOf(SixelRenderer::class, $renderer->inner());
        $this->assertSame(Dither::Atkinson, $renderer->inner()->dither());
        $this->assertSame(32, $renderer->inner()->maxColors());
    }

    public function testWithDitherOnTmuxWrappedNonSixelIsNoOp(): void
    {
        $m = new Mosaic(new TmuxPassthroughDecorator(new HalfBlockRenderer()), Capability::universal(), null, null, null);

        $this->assertSame($m, $m->withDither(Dither::Atkinson));
    }

    public function testWithDitherOnNonSixelRendererIsNoOp(): void
    {
        $m = Mosaic::halfBlock();
        $m2 = $m->withDither(Dither::Atkinson);

        // Returns same instance since halfblock doesn't use dither
        $this->assertSame($m, $m2);
    }

    public function testCapabilityReturnsCapabilityObject(): void
    {
        $m = Mosaic::iterm2();
        $this->assertNotNull($m->capability());
        $this->assertTrue($m->capability()->iterm2);
    }

    public function testProtocol(): void
    {
        $this->assertSame('halfblock', Mosaic::halfBlock()->protocol());
        $this->assertSame('iterm2', Mosaic::iterm2()->protocol());
        $this->assertSame('kitty', Mosaic::kitty()->protocol());
        $this->assertSame('sixel', Mosaic::sixel()->protocol());
        $this->assertSame('quarterblock', Mosaic::quarterBlock()->protocol());
        $this->assertSame('chafa', Mosaic::chafa()->protocol());
        $this->assertSame('ascii', Mosaic::ascii()->protocol());
    }

    public function testSupportedProtocols(): void
    {
        $protocols = Mosaic::supportedProtocols();
        $this->assertContains('kitty', $protocols);
        $this->assertContains('sixel', $protocols);
        $this->assertContains('iterm2', $protocols);
        $this->assertContains('halfblock', $protocols);
        $this->assertContains('quarterblock', $protocols);
        $this->assertContains('ascii', $protocols);
        $this->assertContains('chafa', $protocols);
        $this->assertCount(7, $protocols);
    }

    public function testKittyFactoryMirrorsForceFactories(): void
    {
        $m = Mosaic::kitty();
        $this->assertInstanceOf(Mosaic::class, $m);
        $this->assertSame('kitty', $m->protocol());
        $this->assertTrue($m->capability()->kitty);
        $this->assertFalse($m->isInline());
        $this->assertTrue($m->renderer() instanceof \SugarCraft\Mosaic\Renderer\KittyRenderer);
    }

    public function testIsInlineForInlineRenderers(): void
    {
        // Half-block, quarter-block, ASCII renderers are inline
        $this->assertTrue(Mosaic::halfBlock()->isInline());
        $this->assertTrue(Mosaic::quarterBlock()->isInline());
        $this->assertTrue(Mosaic::ascii()->isInline());
    }

    public function testIsInlineForGraphicRenderers(): void
    {
        // Sixel and iTerm2 are NOT inline (pixel graphics blob)
        $this->assertFalse(Mosaic::sixel()->isInline());
        $this->assertFalse(Mosaic::iterm2()->isInline());
        // Chafa is inline (character-based)
        $this->assertTrue(Mosaic::chafa()->isInline());
    }

    public function testFontSizeReturnsNullWhenNoCellSize(): void
    {
        // Unknown capability has no cell size
        $m = Mosaic::halfBlock();
        $this->assertNull($m->fontSize());
    }

    public function testScaleReturnsNullByDefault(): void
    {
        $m = Mosaic::sixel();
        $this->assertNull($m->scale());
    }

    public function testAdaptiveReturnsAdaptiveImage(): void
    {
        $m = Mosaic::sixel();
        $img = ImageSource::fromFile($this->fixture4x2);
        $adaptive = $m->adaptive($img);

        $this->assertInstanceOf(AdaptiveImage::class, $adaptive);
    }

    public function testPrecomputeReturnsPrecomputedImage(): void
    {
        $m = Mosaic::sixel();
        $img = ImageSource::fromFile($this->fixture4x2);
        $precomputed = $m->precompute($img, 8, 4);

        $this->assertInstanceOf(PrecomputedImage::class, $precomputed);
    }

    public function testPrecomputeWithoutExplicitHeight(): void
    {
        $m = Mosaic::sixel();
        $img = ImageSource::fromFile($this->fixture4x2);
        $precomputed = $m->precompute($img, 8);

        $this->assertInstanceOf(PrecomputedImage::class, $precomputed);
    }

    // ─── Render edge cases ─────────────────────────────────────────────────────

    public function testRenderWithZeroWidthClampedToOne(): void
    {
        $m = Mosaic::halfBlock();
        $img = ImageSource::fromFile($this->fixture4x2);

        // Width 0 should be clamped to 1
        $out = $m->render($img, 0, 4);
        $this->assertNotEmpty($out);
    }

    public function testRenderWithExplicitNullHeight(): void
    {
        $m = Mosaic::halfBlock();
        $img = ImageSource::fromFile($this->fixture4x2);

        // Null height should derive from aspect ratio
        $out = $m->render($img, 8, null);
        $this->assertNotEmpty($out);
    }

    public function testRenderWithScaleFitNoHeight(): void
    {
        $m = Mosaic::sixel()->withScale(Scale::Fit);
        $img = ImageSource::fromFile($this->fixture4x2);

        $out = $m->render($img, 8, null);
        $this->assertNotEmpty($out);
    }

    public function testRenderWithScaleStretch(): void
    {
        $m = Mosaic::sixel()->withScale(Scale::Stretch);
        $img = ImageSource::fromFile($this->fixture4x2);

        $out = $m->render($img, 8, 4);
        $this->assertNotEmpty($out);
    }

    // ─── Builder::sixel() ─────────────────────────────────────────────────────

    public function testBuilderSixelDefault(): void
    {
        $m = Mosaic::builder()->sixel()->build();
        $this->assertSame('sixel', $m->protocol());
    }

    public function testBuilderSixelWithDither(): void
    {
        $m = Mosaic::builder()->sixel(Dither::Stucki)->build();
        $this->assertSame('sixel', $m->protocol());
    }

    public function testBuilderSixelOverridesWithDither(): void
    {
        // Builder sixel() sets the renderer; withDither should override if SixelRenderer
        $m = Mosaic::builder()->sixel()->withDither(Dither::Atkinson)->build();
        $this->assertSame('sixel', $m->protocol());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getRenderer(Mosaic $m): object
    {
        $r = new \ReflectionClass($m);
        $p = $r->getProperty('renderer');
        $p->setAccessible(true);
        return $p->getValue($m);
    }
}
