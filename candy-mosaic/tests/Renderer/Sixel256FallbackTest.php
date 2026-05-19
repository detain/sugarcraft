<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\SixelRenderer;

final class Sixel256FallbackTest extends TestCase
{
    public function testDefaultMaxColorsIs256(): void
    {
        $r = new SixelRenderer();
        $this->assertSame(256, $r->maxColors());
    }

    public function testCustomMaxColors(): void
    {
        $r = new SixelRenderer(Dither::None, 128);
        $this->assertSame(128, $r->maxColors());
    }

    public function testMaxColors16ProducesSmallerPalette(): void
    {
        $r256 = new SixelRenderer(Dither::None, 256);
        $r16 = new SixelRenderer(Dither::None, 16);

        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/gradient_64x64.png');

        $out256 = $r256->render($image, 8, 8);
        $out16 = $r16->render($image, 8, 8);

        // Both should produce valid Sixel output.
        $this->assertStringStartsWith("\x1bP1;0;0q", $out256);
        $this->assertStringStartsWith("\x1bP1;0;0q", $out16);

        // The 16-color version should have fewer DECGCI declarations
        // (one per palette entry). We can count DCS ... $ ST sequences.
        $count256 = substr_count($out256, "\x1bP");
        $count16 = substr_count($out16, "\x1bP");
        $this->assertGreaterThan($count16, $count256);
    }

    public function testMaxColors256Boundary(): void
    {
        $r = new SixelRenderer(Dither::None, 256);
        $this->assertSame(256, $r->maxColors());
    }

    public function testMaxColorsZeroThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SixelRenderer(Dither::None, 0);
    }

    public function testMaxColorsNegativeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SixelRenderer(Dither::None, -1);
    }

    public function testMaxColorsExceeds256Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SixelRenderer(Dither::None, 257);
    }

    public function testSupportsAlphaReturnsFalse(): void
    {
        $r = new SixelRenderer();
        $this->assertFalse($r->supportsAlpha());
    }

    public function testNameReturnsSixel(): void
    {
        $r = new SixelRenderer();
        $this->assertSame('sixel', $r->name());
    }

    public function testDitherAccessor(): void
    {
        $r1 = new SixelRenderer(Dither::FloydSteinberg);
        $this->assertSame(Dither::FloydSteinberg, $r1->dither());

        $r2 = new SixelRenderer(Dither::Atkinson, 64);
        $this->assertSame(Dither::Atkinson, $r2->dither());
        $this->assertSame(64, $r2->maxColors());
    }
}
