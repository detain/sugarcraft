<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\AdaptiveImage;
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
        // Kitty is only available via auto() or probe(), not a direct factory
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

        // Returns new instance with different dither
        $this->assertNotSame($m, $m2);
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
        $this->assertContains('chafa', $protocols);
        $this->assertCount(6, $protocols);
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
