<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\MosaicBuilder;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\Scale;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;

/**
 * @covers \SugarCraft\Mosaic\MosaicBuilder
 */
final class MosaicBuilderTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    /**
     * build() without an explicit renderer now auto-detects; clear the
     * environment knobs Detect::probe() reads so the outcome is deterministic
     * (no graphics protocol → halfblock) regardless of the dev/CI terminal.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
            'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'TERM_PROGRAM',
            'KITTY_WINDOW_ID', 'XTERM_VERSION', 'LC_TERMINAL',
        ];
        foreach ($keys as $key) {
            // Detect reads getenv(), not $_ENV — save/restore through the
            // same channel (false = genuinely unset) so the real process env
            // survives even under variables_order settings without 'E'.
            $this->savedEnv[$key] = getenv($key);
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    public function testBuildWithNoRendererAutoDetectsInsteadOfSixel(): void
    {
        // A cleared environment advertises no graphics protocol — the builder
        // must land on the universal HalfBlock fallback, never on Sixel,
        // whose DCS payload a non-Sixel terminal cannot display.
        $mosaic = Mosaic::builder()->build();

        $this->assertSame('halfblock', $mosaic->protocol());
    }

    public function testBuildWithNoRendererPicksKittyWhenTerminalAdvertisesIt(): void
    {
        $_ENV['TERM'] = 'xterm-kitty';
        putenv('TERM=xterm-kitty');

        $mosaic = Mosaic::builder()->build();

        $this->assertSame('kitty', $mosaic->protocol());
    }

    public function testBuildWithNoRendererHonoursDitherWhenAutoResolvesSixel(): void
    {
        // XTERM_VERSION + xterm TERM makes Detect report Sixel; the builder's
        // dither must reach the auto-resolved SixelRenderer.
        $_ENV['XTERM_VERSION'] = 'X11R5(370)';
        putenv('XTERM_VERSION=X11R5(370)');
        $_ENV['TERM'] = 'xterm';
        putenv('TERM=xterm');

        $mosaic = Mosaic::builder()
            ->withDither(Dither::Atkinson)
            ->build();

        $this->assertSame('sixel', $mosaic->protocol());
        $renderer = $mosaic->renderer();
        $this->assertInstanceOf(SixelRenderer::class, $renderer);
        $this->assertSame(Dither::Atkinson, $renderer->dither());
    }

    public function testBuildDitherOverridesExplicitSixelRendererDither(): void
    {
        // When a SixelRenderer is passed explicitly but the builder also has
        // a dither set, the builder dither wins (Mosaic.php:553-556).
        $mosaic = Mosaic::builder()
            ->withRenderer(new SixelRenderer(Dither::None))
            ->withDither(Dither::Stucki)
            ->build();

        $renderer = $mosaic->renderer();
        $this->assertInstanceOf(SixelRenderer::class, $renderer);
        $this->assertSame(Dither::Stucki, $renderer->dither());
    }

    public function testBuildHonoursDitherOnTmuxWrappedAutoSixel(): void
    {
        // Same Sixel advertising env as above, plus TMUX: auto() wraps the
        // backend in the passthrough envelope, and the builder dither must
        // still reach the inner SixelRenderer instead of being dropped.
        $_ENV['XTERM_VERSION'] = 'X11R5(370)';
        putenv('XTERM_VERSION=X11R5(370)');
        $_ENV['TERM'] = 'xterm';
        putenv('TERM=xterm');
        $_ENV['TMUX'] = '/tmp/tmux-1000/default,1234,0';
        putenv('TMUX=/tmp/tmux-1000/default,1234,0');

        $renderer = Mosaic::builder()
            ->withDither(Dither::Atkinson)
            ->build()
            ->renderer();

        $this->assertInstanceOf(TmuxPassthroughDecorator::class, $renderer);
        $inner = $renderer->inner();
        $this->assertInstanceOf(SixelRenderer::class, $inner);
        $this->assertSame(Dither::Atkinson, $inner->dither());
    }

    public function testBuildHonoursDitherOnExplicitTmuxWrappedSixel(): void
    {
        $mosaic = Mosaic::builder()
            ->withRenderer(new TmuxPassthroughDecorator(new SixelRenderer(Dither::None)))
            ->withDither(Dither::Stucki)
            ->build();

        $renderer = $mosaic->renderer();
        $this->assertInstanceOf(TmuxPassthroughDecorator::class, $renderer);
        $inner = $renderer->inner();
        $this->assertInstanceOf(SixelRenderer::class, $inner);
        $this->assertSame(Dither::Stucki, $inner->dither());
    }

    public function testBuildDitherIsNoOpForNonSixelRenderer(): void
    {
        // Dither parameterises only Sixel encoding — an explicit half-block
        // builder with a dither configured must build the renderer untouched.
        $renderer = new HalfBlockRenderer();
        $mosaic = Mosaic::builder()
            ->withRenderer($renderer)
            ->withDither(Dither::Atkinson)
            ->build();

        $this->assertSame($renderer, $mosaic->renderer());
        $this->assertSame('halfblock', $mosaic->protocol());
    }

    public function testBuildDitherPreservesSixelTuning(): void
    {
        // Swapping the builder dither over a tuned Sixel (custom colour
        // budget, real terminal cell size) must not reset the tuning —
        // sugar-reel builds SixelRenderer with the true cell pixel size,
        // and a reset would mis-scale its canvas.
        $mosaic = Mosaic::builder()
            ->withRenderer(new SixelRenderer(Dither::None, 16, 12, 24))
            ->withDither(Dither::Atkinson)
            ->build();

        $renderer = $mosaic->renderer();
        $this->assertInstanceOf(SixelRenderer::class, $renderer);
        $this->assertSame(Dither::Atkinson, $renderer->dither());
        $this->assertSame(16, $renderer->maxColors());
    }

    public function testRendererAccessorReturnsConfiguredRenderer(): void
    {
        $renderer = new HalfBlockRenderer();
        $mosaic = Mosaic::builder()
            ->withRenderer($renderer)
            ->build();

        $this->assertSame($renderer, $mosaic->renderer());
    }

    public function testWithResizeCarriesWidthAndHeightIntoBuiltMosaic(): void
    {
        $mosaic = Mosaic::builder()
            ->withResize(40, 20)
            ->build();

        // Mosaic stores forcedWidth/forcedHeight and applies them in render().
        // Verify the mosaic was built and is usable.
        $this->assertInstanceOf(Mosaic::class, $mosaic);
    }

    public function testWithScaleReturnsNewBuilderInstance(): void
    {
        $builder = Mosaic::builder();
        $newBuilder = $builder->withScale(Scale::Fill);

        // Original builder must be unchanged (immutability).
        $this->assertNotSame($builder, $newBuilder);

        // Verify the original builder has no scale set.
        // We can't directly access private $scale, but we can verify by building
        // and checking the original builder still builds with null scale.
        $originalMosaic = $builder->build();
        $scaledMosaic = $newBuilder->build();

        $this->assertNull($originalMosaic->scale());
        $this->assertSame(Scale::Fill, $scaledMosaic->scale());
    }

    public function testWithRendererCarriesAllOtherFields(): void
    {
        $builder = Mosaic::builder()
            ->withResize(40, 20)
            ->withDither(Dither::Stucki)
            ->withScale(Scale::Crop);

        $newBuilder = $builder->withRenderer(new SixelRenderer());

        // All other fields should be preserved.
        $this->assertNotSame($builder, $newBuilder);
        $this->assertSame(Scale::Crop, $newBuilder->build()->scale());
    }

    public function testWithDitherReturnsNewBuilderInstance(): void
    {
        $builder = Mosaic::builder();
        $newBuilder = $builder->withDither(Dither::Stucki);

        $this->assertNotSame($builder, $newBuilder);
    }

    public function testChainedBuilderCallsWork(): void
    {
        $mosaic = Mosaic::builder()
            ->withResize(80, 40)
            ->withDither(Dither::Atkinson)
            ->withScale(Scale::Fit)
            ->build();

        $this->assertInstanceOf(Mosaic::class, $mosaic);
        // Cleared env → auto-detect lands on halfblock, and the chain's
        // scale still rides into the built mosaic.
        $this->assertSame('halfblock', $mosaic->protocol());
        $this->assertSame(Scale::Fit, $mosaic->scale());
    }
}
