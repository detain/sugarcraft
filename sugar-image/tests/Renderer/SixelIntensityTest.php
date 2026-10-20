<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * C-07: intensity-ladder pins — chafa's depth base
 * (chafa-canvas.c:56-59 + :591-614) multiplies the ORDERED texture
 * magnitude only, the diffusion kernel scales its error by the user
 * strength (chafa-pixops.c fs_dither_grain `(accum - col) * intensity`)
 * with null = exactly 1.0 so the byte-frozen default stream is untouched,
 * and Mosaic::sixel() threads the knob to its renderer.
 */
final class SixelIntensityTest extends TestCase
{
    /** Dither::None gradient bytes — the zero-bias reference (frozen at base). */
    private const FROZEN_NONE_SHA1 = 'c65c8465e501645f8eb6c76bb46a97cf38e948d0';

    /** Default (FS, null intensity) gradient bytes — frozen post-C-01. */
    private const FROZEN_DEFAULT_SHA1 = '47d7e3d7b2e1a1a3e21e363f836d6d03410987f8';

    public function testZeroBiasTextureRendersIdenticalToNoDither(): void
    {
        $source = $this->source();

        foreach ([Dither::Ordered, Dither::Noise] as $textured) {
            $this->assertSame(
                self::FROZEN_NONE_SHA1,
                sha1((new SixelRenderer($textured, intensity: 0.0))->render($source, 16, 12)),
                $textured->name . ' at intensity 0.0 must equal the unbaised render',
            );
        }
    }

    public function testIntensityAboveOneClampsToTheFullBias(): void
    {
        $source = $this->source();

        $this->assertSame(
            (new SixelRenderer(Dither::Ordered, intensity: 1.0))->render($source, 16, 12),
            (new SixelRenderer(Dither::Ordered, intensity: 2.0))->render($source, 16, 12),
        );
        // chafa's diffusion arm clamps MIN(intensity, 1.0) too
        $this->assertSame(
            (new SixelRenderer(Dither::FloydSteinberg, intensity: 1.0))->render($source, 16, 12),
            (new SixelRenderer(Dither::FloydSteinberg, intensity: 5.0))->render($source, 16, 12),
        );
    }

    /** @return iterable<string, array{float}> */
    public static function refusedIntensities(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'minus zero-point-zero' => [-PHP_FLOAT_MIN];
        yield 'NaN' => [NAN];
    }

    /**
     * C-07: negatives are a user error (loud refusal, fail-fast). NaN needs
     * the guard's explicit is_nan leg — PHP orders NaN ABOVE every number
     * (NAN >= 0.0 is true there, unlike IEEE), so a bare sign comparison
     * would wave it through to min() and silently mean "full bias".
     * +INF is NOT refused — it clamps to the full 1.0 bias, the direction
     * chafa's MIN clamp already takes.
     */
    public function testNegativeAndNanIntensityAreRefused(): void
    {
        foreach (self::refusedIntensities() as $label => $case) {
            try {
                new SixelRenderer(Dither::Ordered, intensity: $case[0]);
                $this->fail("intensity $label must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('intensity', $e->getMessage());
            }
        }
    }

    public function testDepthLadderMatchesChafaCanvasRungs(): void
    {
        // chafa-canvas.c: 256/240/truecolor → 0.1, 16 (and 16_8) → 0.25,
        // 8 → 0.5, FGBG → 1.0; our proxy is maxColors with 17..239
        // disclosed onto the 0.1 rung.
        $this->assertSame(0.1, $this->orderedMagnitude(256, null));
        $this->assertSame(0.1, $this->orderedMagnitude(17, null));
        $this->assertSame(0.25, $this->orderedMagnitude(16, null));
        $this->assertSame(0.5, $this->orderedMagnitude(8, null));
        $this->assertSame(1.0, $this->orderedMagnitude(2, null));
    }

    public function testLadderMultipliesUserIntensity(): void
    {
        $this->assertSame(0.125, $this->orderedMagnitude(16, 0.5));
        $this->assertSame(0.05, $this->orderedMagnitude(256, 0.5));
        // >1 clamps BEFORE the fold: full strength on the 0.25 rung
        $this->assertSame(0.25, $this->orderedMagnitude(16, 2.0));
    }

    /**
     * C-05 posture pinned under C-07: the NOISE arm takes chafa's 0.1 bias
     * scale of the USER intensity only — the depth ladder is ORDERED-only
     * (chafa-canvas.c:591-614), so rung changes must not move noise.
     */
    public function testNoiseMagnitudeIgnoresTheLadder(): void
    {
        $this->assertSame(0.1, $this->noiseMagnitude(256, null));
        $this->assertSame(0.1, $this->noiseMagnitude(16, null));
        $this->assertSame(0.1, $this->noiseMagnitude(2, null));
        $this->assertSame(0.05, $this->noiseMagnitude(256, 0.5));
    }

    /**
     * C-07 byte-freeze contract (orchestrator micro-4): the null default
     * AND an explicit 1.0 both reproduce the merged post-C-01 stream
     * exactly; a reduced diffusion strength must actually change it
     * (non-vacuity — the mutation that never multiplies intensity into
     * the error reddens this leg).
     */
    public function testDiffusionStreamFrozenAtOneAndAliveBelowOne(): void
    {
        $source = $this->source();

        $this->assertSame(
            self::FROZEN_DEFAULT_SHA1,
            sha1((new SixelRenderer())->render($source, 16, 12)),
        );
        $this->assertSame(
            self::FROZEN_DEFAULT_SHA1,
            sha1((new SixelRenderer(Dither::FloydSteinberg, intensity: 1.0))->render($source, 16, 12)),
            'float x * 1.0 is bit-exact: the explicit default must not perturb the frozen stream',
        );
        $this->assertNotSame(
            self::FROZEN_DEFAULT_SHA1,
            sha1((new SixelRenderer(Dither::FloydSteinberg, intensity: 0.5))->render($source, 16, 12)),
            'half-strength diffusion must visibly differ',
        );
    }

    public function testWithDitherCarriesIntensity(): void
    {
        $rebuilt = (new SixelRenderer(Dither::Ordered, intensity: 0.5))->withDither(Dither::Noise);

        $this->assertSame(
            'dither=noise|maxColors=256|cellWidth=10|cellHeight=20|intensity=0.5',
            $rebuilt->configFingerprint(),
        );
    }

    /**
     * C-07: Mosaic::sixel() threads the knob — the renderer fingerprint the
     * poster cache folds (D-04) gains the intensity segment only when set,
     * so a 0.5-ordered poster never cross-serves the default entry.
     */
    public function testMosaicSixelThreadsIntensity(): void
    {
        $this->assertSame(
            'dither=floyd-steinberg|maxColors=256|cellWidth=10|cellHeight=20',
            $this->mosaicRendererFingerprint(Mosaic::sixel()),
        );
        $this->assertSame(
            'dither=ordered|maxColors=256|cellWidth=10|cellHeight=20|intensity=0.5',
            $this->mosaicRendererFingerprint(Mosaic::sixel(Dither::Ordered, intensity: 0.5)),
        );
        $this->assertStringNotContainsString(
            'intensity',
            $this->mosaicRendererFingerprint(Mosaic::sixel(Dither::None)),
        );
    }

    private function source(): ImageSource
    {
        return ImageSource::fromFile(__DIR__ . '/../fixtures/gradient_64x64.png');
    }

    private function orderedMagnitude(int $maxColors, ?float $intensity): float
    {
        $method = new \ReflectionMethod(SixelRenderer::class, 'orderedTextureMagnitude');
        $method->setAccessible(true);

        return $method->invoke(new SixelRenderer(Dither::Ordered, maxColors: $maxColors, intensity: $intensity));
    }

    private function noiseMagnitude(int $maxColors, ?float $intensity): float
    {
        $method = new \ReflectionMethod(SixelRenderer::class, 'noiseTextureMagnitude');
        $method->setAccessible(true);

        return $method->invoke(new SixelRenderer(Dither::Noise, maxColors: $maxColors, intensity: $intensity));
    }

    private function mosaicRendererFingerprint(Mosaic $mosaic): string
    {
        $method = new \ReflectionMethod(Mosaic::class, 'rendererConfigFingerprint');
        $method->setAccessible(true);

        return $method->invoke($mosaic);
    }
}
