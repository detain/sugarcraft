<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\AdaptiveImage;
use SugarCraft\Image\Capability;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\PrecomputedImage;
use SugarCraft\Image\Renderer\AsciiRenderer;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\Iterm2Renderer;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\Scale;
use SugarCraft\Image\Symbol\CanvasMode;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * @covers \SugarCraft\Image\Mosaic
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
        // Mono's protocol already IS its mode value, so modeDetail()
        // stays null — the 'ascii' name alone identifies the bytes.
        $m = Mosaic::ascii();
        $this->assertSame('ascii', $m->protocol());
        $this->assertNull($m->modeDetail());
        $this->assertInstanceOf(AsciiRenderer::class, $this->getRenderer($m));
    }

    public function testAsciiFactoryTrueColor(): void
    {
        // Protocol() names the PROTOCOL ('ascii'), never the colour
        // arm; modeDetail() carries the arm that distinguishes the bytes.
        $m = Mosaic::ascii(AsciiColorMode::TrueColor);
        $this->assertSame('ascii', $m->protocol());
        $this->assertSame('truecolor', $m->modeDetail());
    }

    public function testAsciiFactoryAnsi256(): void
    {
        // See testAsciiFactoryTrueColor — 'ansi256' is colour detail,
        // not a protocol name.
        $m = Mosaic::ascii(AsciiColorMode::Ansi256);
        $this->assertSame('ascii', $m->protocol());
        $this->assertSame('ansi256', $m->modeDetail());
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

    // ─── Consultable dial silence (finding 038) ──────────────────────────────

    public function testPredicateMatrixPerTier(): void
    {
        // Which backend hears which dial, pinned per tier: [mosaic, dither,
        // canvas, work, selectors]. ignoredDials must agree with the four
        // booleans in its own fixed order.
        $matrix = [
            'sixel'        => [Mosaic::sixel(),        true,  false, false, false],
            'symbols'      => [Mosaic::symbols(),      false, true,  true,  true],
            'quarterBlock' => [Mosaic::quarterBlock(), false, true,  false, false],
            'halfBlock'    => [Mosaic::halfBlock(),    false, false, false, false],
            'ascii'        => [Mosaic::ascii(),        false, false, false, false],
            'kitty'        => [Mosaic::kitty(),        false, false, false, false],
            'iterm2'       => [Mosaic::iterm2(),       false, false, false, false],
            'chafa'        => [Mosaic::chafa(),        false, false, false, false],
        ];

        foreach ($matrix as $tier => [$m, $dither, $canvas, $work, $selectors]) {
            $this->assertSame($dither, $m->acceptsDither(), "$tier dither");
            $this->assertSame($canvas, $m->acceptsCanvasMode(), "$tier canvas");
            $this->assertSame($work, $m->acceptsWorkFactor(), "$tier work");
            $this->assertSame($selectors, $m->acceptsSymbolSelectors(), "$tier selectors");

            $expected = [];
            if (!$dither) {
                $expected[] = 'dither';
            }
            if (!$canvas) {
                $expected[] = 'canvasMode';
            }
            if (!$work) {
                $expected[] = 'workFactor';
            }
            if (!$selectors) {
                $expected[] = 'symbolSelectors';
            }
            $this->assertSame($expected, $m->ignoredDials(), "$tier ignoredDials");
        }
    }

    public function testPredicatesAnswerThroughThePassthroughEnvelope(): void
    {
        // A Sixel renderer wrapped in the tmux envelope still hears its
        // dither — the consultation unwraps exactly like the with* doors.
        $sixel = new SixelRenderer(Dither::FloydSteinberg, 16, 12, 24);
        $m     = new Mosaic(new TmuxPassthroughDecorator($sixel), Capability::universal(), null, null, null);

        $this->assertTrue($m->acceptsDither());
        $this->assertSame(['canvasMode', 'workFactor', 'symbolSelectors'], $m->ignoredDials());
    }

    public function testEveryDialDoorAgreesWithItsPredicate(): void
    {
        // The consultation must not drift from the doors it describes:
        // turning a dial yields a different mosaic exactly when its
        // predicate says the backend has an ear.
        foreach ([Mosaic::halfBlock(), Mosaic::symbols(), Mosaic::quarterBlock(), Mosaic::sixel()] as $i => $m) {
            $this->assertSame($m->acceptsDither(), $m->withDither(Dither::Atkinson) !== $m, "tier $i dither door");
            $this->assertSame($m->acceptsCanvasMode(), $m->withCanvasMode(CanvasMode::Ansi256) !== $m, "tier $i canvas door");
            $this->assertSame($m->acceptsWorkFactor(), $m->withWorkFactor(0.5) !== $m, "tier $i work door");
            $this->assertSame($m->acceptsSymbolSelectors(), $m->withSymbolSelectors('braille') !== $m, "tier $i selectors door");
        }
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
        $this->assertSame('symbols', Mosaic::symbols()->protocol());
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
        $this->assertContains('symbols', $protocols);
        $this->assertCount(8, $protocols);
    }

    public function testKittyFactoryMirrorsForceFactories(): void
    {
        $m = Mosaic::kitty();
        $this->assertInstanceOf(Mosaic::class, $m);
        $this->assertSame('kitty', $m->protocol());
        $this->assertTrue($m->capability()->kitty);
        $this->assertFalse($m->isInline());
        $this->assertTrue($m->renderer() instanceof \SugarCraft\Image\Renderer\KittyRenderer);
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

    public function testRenderWithNonPositiveWidthRefusedNotSlivered(): void
    {
        // Old behavior (finding 037): width 0 decayed to a silent 1-column
        // sliver. A caller with a zero-column pane must fail loudly.
        $m   = Mosaic::halfBlock();
        $img = ImageSource::fromFile($this->fixture4x2);

        foreach ([0, -1] as $bogus) {
            try {
                $m->render($img, $bogus, 4);
                $this->fail("width {$bogus} must be refused, not clamped");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString((string) $bogus, $e->getMessage());
            }
        }
    }

    public function testRenderWithNonPositiveHeightRefused(): void
    {
        $m   = Mosaic::halfBlock();
        $img = ImageSource::fromFile($this->fixture4x2);

        foreach ([0, -3] as $bogus) {
            try {
                $m->render($img, 8, $bogus);
                $this->fail("height {$bogus} must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('height', $e->getMessage());
            }
        }
    }

    public function testRenderRowsRefusesNonPositiveWidthToo(): void
    {
        // The row-lazy twin shares resolveRenderBox, so it carries the
        // exact same refusal before its first yield.
        $m   = Mosaic::halfBlock();
        $img = ImageSource::fromFile($this->fixture4x2);

        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($m->renderRows($img, 0));
    }

    public function testZeroColumnBuilderBoxRefusedAtRenderTime(): void
    {
        // The candy-code route: a pane measured at zero columns got baked
        // into the builder box — that box must fail loud, not sliver.
        $img = ImageSource::fromFile($this->fixture4x2);
        $m   = Mosaic::builder()
            ->withRenderer(new HalfBlockRenderer())
            ->withResize(0, 4)
            ->build();

        try {
            $m->render($img);
            $this->fail('a forced zero-width box must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('positive cell count', $e->getMessage());
        }
    }

    public function testNullSizingStillFallsBackToBoxAndAspect(): void
    {
        // The green path the throws must NOT touch: null width takes the
        // builder box, null height derives from aspect.
        $img = ImageSource::fromFile($this->fixture4x2);
        $m   = Mosaic::builder()
            ->withRenderer(new HalfBlockRenderer())
            ->withResize(8)
            ->build();

        $this->assertNotEmpty($m->render($img));
        $this->assertNotEmpty($m->render($img, 6, null));
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
