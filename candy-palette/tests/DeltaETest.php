<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\DeltaE;

/**
 * Coverage for the three delta-E metrics.
 *
 * CIEDE2000 is pinned against all 34 published test pairs from
 * Sharma, Wu & Dalal, "The CIEDE2000 Color-Difference Formula", Color Research
 * & Application 30(1), 2005 — the canonical CIE-issued validation set.
 */
final class DeltaETest extends TestCase
{
    /**
     * @return array<string, array{0: array{l: float, a: float, b: float}}>
     */
    public static function labProvider(): array
    {
        return [
            'black'       => [['l' => 0.0, 'a' => 0.0, 'b' => 0.0]],
            'white'       => [['l' => 100.0, 'a' => 0.0, 'b' => 0.0]],
            'saturated'   => [['l' => 53.2408, 'a' => 80.0925, 'b' => 67.2032]],
            'deep-blue'   => [['l' => 32.2970, 'a' => 79.1875, 'b' => -107.8602]],
        ];
    }

    /**
     * @dataProvider labProvider
     *
     * @param array{l: float, a: float, b: float} $lab
     */
    public function testSelfDistanceIsZero(array $lab): void
    {
        self::assertEqualsWithDelta(0.0, DeltaE::cie76($lab, $lab), 1e-12);
        self::assertEqualsWithDelta(0.0, DeltaE::cie94($lab, $lab), 1e-12);
        self::assertEqualsWithDelta(0.0, DeltaE::cie2000($lab, $lab), 1e-12);
    }

    /**
     * @dataProvider labProvider
     *
     * @param array{l: float, a: float, b: float} $lab
     */
    public function testDistanceToOriginIsNotDegenerate(array $lab): void
    {
        $origin = ['l' => 0.0, 'a' => 0.0, 'b' => 0.0];
        if ($lab === $origin) {
            self::markTestSkipped('origin compared against itself');
        }
        self::assertGreaterThan(0.0, DeltaE::cie76($lab, $origin));
        self::assertGreaterThan(0.0, DeltaE::cie94($lab, $origin));
        self::assertGreaterThan(0.0, DeltaE::cie2000($lab, $origin));
    }

    public function testCie76AndCie2000AreSymmetric(): void
    {
        $a = ['l' => 50.0, 'a' => 2.6772, 'b' => -79.7751];
        $b = ['l' => 61.0, 'a' => -5.0, 'b' => 29.0];
        self::assertEqualsWithDelta(
            DeltaE::cie76($a, $b),
            DeltaE::cie76($b, $a),
            1e-12,
        );
        self::assertEqualsWithDelta(
            DeltaE::cie2000($a, $b),
            DeltaE::cie2000($b, $a),
            1e-12,
        );
    }

    public function testCie94IsAsymmetricByDesign(): void
    {
        // The first triple supplies the reference chroma for SC/SH, so order
        // changes the score when chromas differ (CIE 15:2004 ΔE94).
        $achromatic = ['l' => 50.0, 'a' => 0.0, 'b' => 0.0];
        $chromatic  = ['l' => 48.0, 'a' => 20.0, 'b' => 10.0];
        self::assertNotSame(
            DeltaE::cie94($achromatic, $chromatic),
            DeltaE::cie94($chromatic, $achromatic),
        );
    }

    public function testCie76HandComputed(): void
    {
        // sqrt((50-48)^2 + (0- -1)^2 + (0-2)^2) = sqrt(4+1+4) = 3
        $a = ['l' => 50.0, 'a' => 0.0, 'b' => 0.0];
        $b = ['l' => 48.0, 'a' => -1.0, 'b' => 2.0];
        self::assertEqualsWithDelta(3.0, DeltaE::cie76($a, $b), 1e-12);
    }

    public function testCie94HandComputedGraphicArts(): void
    {
        // Reference (50, 20, 0): C1 = 20 -> SC = 1 + 0.045*20 = 1.9, SH = 1.3.
        // Sample  (48, -1, 2):  C2 = sqrt(5).
        // dL = 2, dC = 20 - sqrt(5) = 17.763932,
        // dH^2 = 21^2 + (-2)^2 - dC^2 = 445 - 315.557275 = 129.442725.
        // dE94 = sqrt(4 + 87.411966 + 76.593329) = sqrt(168.005295) = 12.9617.
        $reference = ['l' => 50.0, 'a' => 20.0, 'b' => 0.0];
        $sample = ['l' => 48.0, 'a' => -1.0, 'b' => 2.0];
        self::assertEqualsWithDelta(12.9617, DeltaE::cie94($reference, $sample), 1e-3);
    }

    /**
     * All 34 Lab pairs of the Sharma/Wu/Dalal (2005) supplementary CIEDE2000
     * test data (Color Research and Application 30(1):21-30), kL = kC = kH = 1,
     * values verbatim from the author's official table at
     * https://www.ece.rochester.edu/~gsharma/ciede2000/dataNprograms/ciede2000testdata.txt
     * (cross-checked against the scikit-image and michel-leonard reproductions).
     *
     * @return array<string, array{0: array{l: float, a: float, b: float},
     *                             1: array{l: float, a: float, b: float},
     *                             2: float}>
     */
    public static function sharmaProvider(): array
    {
        $pair = static fn (
            float $l1, float $a1, float $b1,
            float $l2, float $a2, float $b2,
        ): array => [
            ['l' => $l1, 'a' => $a1, 'b' => $b1],
            ['l' => $l2, 'a' => $a2, 'b' => $b2],
        ];

        return [
            // [pair A, pair B, expected dE00]
            'row-01' => [...$pair(50.0000, 2.6772, -79.7751, 50.0000, 0.0000, -82.7485), 2.0425],
            'row-02' => [...$pair(50.0000, 3.1571, -77.2803, 50.0000, 0.0000, -82.7485), 2.8615],
            'row-03' => [...$pair(50.0000, 2.8361, -74.0200, 50.0000, 0.0000, -82.7485), 3.4412],
            'row-04' => [...$pair(50.0000, -1.3802, -84.2814, 50.0000, 0.0000, -82.7485), 1.0000],
            'row-05' => [...$pair(50.0000, -1.1848, -84.8006, 50.0000, 0.0000, -82.7485), 1.0000],
            'row-06' => [...$pair(50.0000, -0.9009, -85.5211, 50.0000, 0.0000, -82.7485), 1.0000],
            'row-07' => [...$pair(50.0000, 0.0000, 0.0000, 50.0000, -1.0000, 2.0000), 2.3669],
            'row-08' => [...$pair(50.0000, -1.0000, 2.0000, 50.0000, 0.0000, 0.0000), 2.3669],
            'row-09' => [...$pair(50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0009), 7.1792],
            'row-10' => [...$pair(50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0010), 7.1792],
            'row-11' => [...$pair(50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0011), 7.2195],
            'row-12' => [...$pair(50.0000, 2.4900, -0.0010, 50.0000, -2.4900, 0.0012), 7.2195],
            'row-13' => [...$pair(50.0000, -0.0010, 2.4900, 50.0000, 0.0009, -2.4900), 4.8045],
            'row-14' => [...$pair(50.0000, -0.0010, 2.4900, 50.0000, 0.0010, -2.4900), 4.8045],
            'row-15' => [...$pair(50.0000, -0.0010, 2.4900, 50.0000, 0.0011, -2.4900), 4.7461],
            'row-16' => [...$pair(50.0000, 2.5000, 0.0000, 50.0000, 0.0000, -2.5000), 4.3065],
            'row-17' => [...$pair(50.0000, 2.5000, 0.0000, 73.0000, 25.0000, -18.0000), 27.1492],
            'row-18' => [...$pair(50.0000, 2.5000, 0.0000, 61.0000, -5.0000, 29.0000), 22.8977],
            'row-19' => [...$pair(50.0000, 2.5000, 0.0000, 56.0000, -27.0000, -3.0000), 31.9030],
            'row-20' => [...$pair(50.0000, 2.5000, 0.0000, 58.0000, 24.0000, 15.0000), 19.4535],
            'row-21' => [...$pair(50.0000, 2.5000, 0.0000, 50.0000, 3.1736, 0.5854), 1.0000],
            'row-22' => [...$pair(50.0000, 2.5000, 0.0000, 50.0000, 3.2972, 0.0000), 1.0000],
            'row-23' => [...$pair(50.0000, 2.5000, 0.0000, 50.0000, 1.8634, 0.5757), 1.0000],
            'row-24' => [...$pair(50.0000, 2.5000, 0.0000, 50.0000, 3.2592, 0.3350), 1.0000],
            'row-25' => [...$pair(60.2574, -34.0099, 36.2677, 60.4626, -34.1751, 39.4387), 1.2644],
            'row-26' => [...$pair(63.0109, -31.0961, -5.8663, 62.8187, -29.7946, -4.0864), 1.2630],
            'row-27' => [...$pair(61.2901, 3.7196, -5.3901, 61.4292, 2.2480, -4.9620), 1.8731],
            'row-28' => [...$pair(35.0831, -44.1164, 3.7933, 35.0232, -40.0716, 1.5901), 1.8645],
            'row-29' => [...$pair(22.7233, 20.0904, -46.6940, 23.0331, 14.9730, -42.5619), 2.0373],
            'row-30' => [...$pair(36.4612, 47.8580, 18.3852, 36.2715, 50.5065, 21.2231), 1.4146],
            'row-31' => [...$pair(90.8027, -2.0831, 1.4410, 91.1528, -1.6435, 0.0447), 1.4441],
            'row-32' => [...$pair(90.9257, -0.5406, -0.9208, 88.6381, -0.8985, -0.7239), 1.5381],
            'row-33' => [...$pair(6.7747, -0.2908, -2.4247, 5.8714, -0.0985, -2.2286), 0.6377],
            'row-34' => [...$pair(2.0776, 0.0795, -1.1350, 0.9033, -0.0636, -0.5514), 0.9082],
        ];
    }

    /**
     * Reference values are published rounded to 4 decimals; agreeing to 1e-4
     * is the conformance bar set by the paper itself.
     *
     * @dataProvider sharmaProvider
     *
     * @param array{l: float, a: float, b: float} $a
     * @param array{l: float, a: float, b: float} $b
     */
    public function testCie2000MatchesPublishedSamplePairs(array $a, array $b, float $expected): void
    {
        self::assertEqualsWithDelta($expected, DeltaE::cie2000($a, $b), 1e-4);
    }

    public function testMalformedLabArraysFailLoudly(): void
    {
        $valid = ['l' => 50.0, 'a' => 1.0, 'b' => 2.0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing a numeric "a" component');
        DeltaE::cie76($valid, ['l' => 10.0, 'b' => 2.0]);
    }

    public function testNonNumericLabComponentFailsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeltaE::cie2000(['l' => 'ten', 'a' => 0.0, 'b' => 0.0], ['l' => 0.0, 'a' => 0.0, 'b' => 0.0]);
    }

    public function testNonFiniteLabComponentsFailLoudly(): void
    {
        // is_numeric() admits NAN and overflow strings; they must not leak
        // poisoned arithmetic downstream as silent NAN distances.
        foreach ([NAN, \INF, -\INF, '1e999'] as $poison) {
            try {
                DeltaE::cie76(['l' => 50.0, 'a' => $poison, 'b' => 0.0], ['l' => 50.0, 'a' => 0.0, 'b' => 0.0]);
                self::fail('non-finite component was accepted');
            } catch (\InvalidArgumentException $expected) {
                self::assertStringContainsString('non-finite "a" component', $expected->getMessage());
            }
        }
    }

    public function testNumericStringComponentsAreParsed(): void
    {
        // Boundary parsing accepts numeric strings, casts to float internally.
        // Pair/value is the canonical CIEDE2000 worked example (ΔE00 = 2.0425).
        $a = ['l' => '50', 'a' => 2.6772, 'b' => -79.7751];
        $b = ['l' => 50.0, 'a' => 0.0, 'b' => -82.7485];
        self::assertEqualsWithDelta(2.0425, DeltaE::cie2000($a, $b), 1e-4);
    }
}
