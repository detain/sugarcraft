<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Color;
use SugarCraft\ColorProfile\ColorDistance;
use SugarCraft\ColorProfile\ColorMath;
use SugarCraft\ColorProfile\DeltaE;
use SugarCraft\ColorProfile\NearestColor;

/**
 * Strategy-enum coverage: dispatch, RGB/Lab separation of concerns, back-compat scale.
 */
final class ColorDistanceTest extends TestCase
{
    public function testCasesAreBackedAndOrdered(): void
    {
        self::assertSame(
            [
                ColorDistance::Euclidean,
                ColorDistance::Cie76,
                ColorDistance::Cie94,
                ColorDistance::Cie2000,
            ],
            ColorDistance::cases(),
        );
        self::assertSame(ColorDistance::Cie2000, ColorDistance::from('cie2000'));
        self::assertNull(ColorDistance::tryFrom('cie-deprecated'));
    }

    public function testEuclideanBetweenIsRgbSpace(): void
    {
        // 3-4-5 right triangle in R/G.
        self::assertEqualsWithDelta(
            5.0,
            ColorDistance::Euclidean->between(new Color(0, 0, 0), new Color(3, 4, 0)),
            1e-12,
        );
    }

    public function testCieMetricsBetweenConvertThroughLab(): void
    {
        $a = new Color(30, 120, 30);
        $b = new Color(0, 102, 0);
        $expected = DeltaE::cie2000($a->toLab(), $b->toLab());
        self::assertEqualsWithDelta($expected, ColorDistance::Cie2000->between($a, $b), 1e-12);
        self::assertEqualsWithDelta(
            DeltaE::cie76($a->toLab(), $b->toLab()),
            ColorDistance::Cie76->between($a, $b),
            1e-12,
        );
    }

    public function testBetweenLabOnEuclideanFailsLoudly(): void
    {
        // EUCLIDEAN compares RGB bytes; silently returning a Lab magnitude
        // under the same case name is the scale-mixing trap this guards.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('use between() instead of betweenLab()');
        ColorDistance::Euclidean->betweenLab(ColorMath::toLab(12, 34, 56), ColorMath::toLab(200, 90, 30));
    }

    public function testBetweenLabDispatchesToCie94AndCie2000(): void
    {
        $labA = ColorMath::toLab(12, 34, 56);
        $labB = ColorMath::toLab(200, 90, 30);
        self::assertSame(DeltaE::cie94($labA, $labB), ColorDistance::Cie94->betweenLab($labA, $labB));
        self::assertSame(DeltaE::cie2000($labA, $labB), ColorDistance::Cie2000->betweenLab($labA, $labB));
    }

    public function testSelfDistanceIsZeroUnderEveryMetric(): void
    {
        $color = new Color(77, 13, 200);
        foreach (ColorDistance::cases() as $metric) {
            self::assertEqualsWithDelta(
                0.0,
                $metric->between($color, $color),
                1e-12,
                $metric->value,
            );
        }
    }

    /**
     * CP-19 pin: the shared default seat. ColorDistance::DEFAULT_METRIC is
     * Euclidean (the byte-compat legacy CALIBER blesses — NOT a silent
     * alignment onto PaletteIndex's Cie76), and NearestColor's constructor
     * default resolves THROUGH the const so the signature stays
     * byte-compatible while the split can no longer drift unnoted.
     */
    public function testTheSharedNearestColorDefaultSeatIsEuclidean(): void
    {
        $this->assertSame(ColorDistance::Euclidean, ColorDistance::DEFAULT_METRIC);

        $default = (new \ReflectionParameter([NearestColor::class, '__construct'], 'metric'))
            ->getDefaultValue();
        $this->assertSame(ColorDistance::DEFAULT_METRIC, $default);
        $this->assertSame(
            ColorDistance::Euclidean,
            (new NearestColor())->metric(),
            'the resolved default answer is unchanged — BC by construction',
        );
    }
}
