<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Color;
use SugarCraft\Palette\ColorMath;

/**
 * Reference-vector coverage for the sRGB -> linear -> XYZ (D65) -> L*a*b* chain.
 *
 * Lab targets are the widely published D65 / 2-degree values for the sRGB
 * primaries and neutral ramp (e.g. the tables accompanying CIE 15:2004 and
 * IEC 61966-2-1), asserted with a 0.02 delta to absorb white-point rounding.
 */
final class ColorMathTest extends TestCase
{
    public function testBlackMapsToLabOrigin(): void
    {
        $lab = ColorMath::toLab(0, 0, 0);
        self::assertEqualsWithDelta(0.0, $lab['l'], 1e-9);
        self::assertEqualsWithDelta(0.0, $lab['a'], 1e-9);
        self::assertEqualsWithDelta(0.0, $lab['b'], 1e-9);
    }

    public function testD65WhiteMapsToL100Neutral(): void
    {
        $lab = ColorMath::toLab(255, 255, 255);
        self::assertEqualsWithDelta(100.0, $lab['l'], 0.01);
        self::assertEqualsWithDelta(0.0, $lab['a'], 0.01);
        self::assertEqualsWithDelta(0.0, $lab['b'], 0.01);
    }

    /**
     * Published sRGB D65 Lab values for the RGB primaries.
     *
     * @return array<string, array{0: array{int,int,int}, 1: array{float,float,float}}>
     */
    public static function primaryProvider(): array
    {
        return [
            'red'   => [[255, 0, 0], [53.2408, 80.0925, 67.2032]],
            'green' => [[0, 255, 0], [87.7347, -86.1827, 83.1793]],
            'blue'  => [[0, 0, 255], [32.2970, 79.1875, -107.8602]],
        ];
    }

    /**
     * @dataProvider primaryProvider
     *
     * @param array{int,int,int}       $rgb
     * @param array{float,float,float} $expectedLab
     */
    public function testPrimariesMatchPublishedLabVectors(array $rgb, array $expectedLab): void
    {
        $lab = ColorMath::toLab(...$rgb);
        self::assertEqualsWithDelta($expectedLab[0], $lab['l'], 0.02);
        self::assertEqualsWithDelta($expectedLab[1], $lab['a'], 0.02);
        self::assertEqualsWithDelta($expectedLab[2], $lab['b'], 0.02);
    }

    public function testMidGreyLMatchesPublishedValue(): void
    {
        $lab = ColorMath::toLab(128, 128, 128);
        self::assertEqualsWithDelta(53.5850, $lab['l'], 0.02);
        self::assertEqualsWithDelta(0.0, $lab['a'], 0.02);
        self::assertEqualsWithDelta(0.0, $lab['b'], 0.02);
    }

    public function testSrgbToLinearEndpointsAndLowTail(): void
    {
        self::assertSame(0.0, ColorMath::srgbToLinear(0));
        self::assertEqualsWithDelta(1.0, ColorMath::srgbToLinear(255), 1e-12);
        // 10/255 = 0.0392 < 0.04045 -> linear segment: c / 12.92
        self::assertEqualsWithDelta(10 / 255 / 12.92, ColorMath::srgbToLinear(10), 1e-12);
        // Mid-grey linearizes to the published 0.215861.
        self::assertEqualsWithDelta(0.215861, ColorMath::srgbToLinear(128), 1e-4);
    }

    public function testRgbToXyzOfWhiteMatchesD65WhitePoint(): void
    {
        $xyz = ColorMath::rgbToXyz(255, 255, 255);
        self::assertEqualsWithDelta(0.95047, $xyz['x'], 1e-4);
        self::assertEqualsWithDelta(1.00000, $xyz['y'], 1e-4);
        self::assertEqualsWithDelta(1.08883, $xyz['z'], 1e-4);
    }

    public function testXyzToLabIsDeterministicOnKnownTriple(): void
    {
        // The D65 white point itself must decode to L*=100, a*=b*=0.
        $lab = ColorMath::xyzToLab(['x' => 0.95047, 'y' => 1.0, 'z' => 1.08883]);
        self::assertEqualsWithDelta(100.0, $lab['l'], 1e-9);
        self::assertEqualsWithDelta(0.0, $lab['a'], 1e-9);
        self::assertEqualsWithDelta(0.0, $lab['b'], 1e-9);
    }

    public function testOutboundBytesAreClampedNotRejected(): void
    {
        self::assertSame(0.0, ColorMath::srgbToLinear(-5));
        self::assertEqualsWithDelta(1.0, ColorMath::srgbToLinear(300), 1e-12);
        self::assertSame(
            ColorMath::toLab(0, 255, 12),
            ColorMath::toLab(-30, 300, 12),
        );
    }

    public function testColorToLabDelegatesToColorMath(): void
    {
        $color = new Color(30, 140, 250);
        self::assertSame(
            ColorMath::toLab(30, 140, 250),
            $color->toLab(),
        );
    }

    public function testXyzToLabRejectsMalformedComponents(): void
    {
        // Public boundary: missing / non-numeric / non-finite components must
        // fail loud rather than leak NAN/TypeError out of the cube-root branch.
        try {
            ColorMath::xyzToLab(['y' => 1.0, 'z' => 1.0]);
            self::fail('missing component was accepted');
        } catch (\InvalidArgumentException $expected) {
            self::assertStringContainsString('XYZ component x is missing', $expected->getMessage());
        }

        try {
            ColorMath::xyzToLab(['x' => 'ten', 'y' => 1.0, 'z' => 1.0]);
            self::fail('non-numeric component was accepted');
        } catch (\InvalidArgumentException $expected) {
            self::assertStringContainsString('not a finite number', $expected->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        ColorMath::xyzToLab(['x' => NAN, 'y' => 1.0, 'z' => 1.0]);
    }

    public function testXyzToLabExtrapolatesBelowEpsilonWithoutNan(): void
    {
        // Ratios <= epsilon (black, or negative out-of-range XYZ) take the
        // linear branch — never the fractional power that would yield NAN.
        $black = ColorMath::xyzToLab(['x' => 0.0, 'y' => 0.0, 'z' => 0.0]);
        self::assertEqualsWithDelta(0.0, $black['l'], 1e-12);
        self::assertSame(0.0, $black['a']);
        self::assertSame(0.0, $black['b']);

        $negative = ColorMath::xyzToLab(['x' => -0.1, 'y' => 0.2, 'z' => -0.1]);
        foreach ($negative as $component) {
            self::assertTrue(\is_finite($component));
        }
    }
}
