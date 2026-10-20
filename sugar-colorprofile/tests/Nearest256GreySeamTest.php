<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Color;
use SugarCraft\Core\Util\Color as CoreColor;
use SugarCraft\Core\Util\ColorProfile as CoreProfile;

/**
 * B-05 SEAM PIN: sugar-core's private Color::nearest256() vs this library's
 * Color::toAnsi256Index().
 *
 * Both encoders agree on the cube candidate, on the grey bin's magnitude, and
 * on the cube-vs-grey comparison. They differ ONLY in how a grey-ramp TIE is
 * resolved: core keeps the single rounded bin, so an exact midpoint rounds UP
 * to the brighter slot (232+k+1); B-05 probes bin-1/bin/bin+1 and keeps the
 * first strict minimum, so the same midpoint resolves DOWN (232+k). The two
 * bins are equidistant at a midpoint — for grey levels g and g+10,
 * d(g) - d(g+10) = 60 * (avg - g - 5), which is zero exactly there — so the
 * seam is a tie-side convention, not a distance gap.
 *
 * sugar-core cannot reference this library (the dependency runs the other
 * way: sugar-colorprofile requires sugar-core), so the pin lives here and
 * drives core through its PUBLIC path — Color::rgb() -> toFg(Ansi256), the
 * exact SGR a 256-colour terminal receives. This file discharges the §4.15
 * register row ("mint the seam when a consumer ever pins both paths") as a
 * measured boundary, not a claim: the census below fixes WHICH colours
 * diverge and by how much, so either encoder moving without the other is a
 * red test instead of a silent drift.
 */
final class Nearest256GreySeamTest extends TestCase
{
    /** Exact step-3 census size: 86 values per axis. */
    private const CENSUS_SIZE = 636056;

    /** Exact number of colours in that census where the encoders disagree. */
    private const CENSUS_DIVERGED = 3803;

    /** Grey-ramp midpoints: 13, 23, ..., 233. */
    private const MIDPOINTS = [
        13, 23, 33, 43, 53, 63, 73, 83, 93, 103, 113, 123,
        133, 143, 153, 163, 173, 183, 193, 203, 213, 223, 233,
    ];

    /**
     * Core's answer via its public SGR route, parsed back to a palette index.
     *
     * Throws (fail fast) rather than asserting: the census calls this ~636k
     * times, and a per-call assertion would dominate the test's runtime.
     */
    private static function coreIndex(int $r, int $g, int $b): int
    {
        $sgr = CoreColor::rgb($r, $g, $b)->toFg(CoreProfile::Ansi256);
        if (!str_starts_with($sgr, "\x1b[38;5;") || !str_ends_with($sgr, 'm')) {
            throw new \RuntimeException('core must emit a 256-colour fg SGR, got ' . json_encode($sgr));
        }

        return (int) substr($sgr, 7, -1);
    }

    /**
     * The seam is the grey-midpoint plane and nothing else: a divergence
     * requires a channel sum of exactly 39 + 30k (avg 13..233) and, on that
     * plane, the core side is exactly one slot brighter. Everywhere else the
     * encoders are identical.
     */
    public function testTheSeamSplitsOnlyOnTheGreyMidpointPlane(): void
    {
        $checked = 0;
        $diverged = 0;

        for ($r = 0; $r <= 255; $r += 3) {
            for ($g = 0; $g <= 255; $g += 3) {
                for ($b = 0; $b <= 255; $b += 3) {
                    $checked++;
                    $core = self::coreIndex($r, $g, $b);
                    $profile = (new Color($r, $g, $b))->toAnsi256Index();

                    if ($core === $profile) {
                        continue;
                    }

                    $diverged++;
                    $sum = $r + $g + $b;

                    self::assertSame(
                        9,
                        $sum % 30,
                        "seam outside the midpoint plane at ($r,$g,$b): sum $sum is not 39 + 30k",
                    );
                    self::assertGreaterThanOrEqual(39, $sum);
                    self::assertLessThanOrEqual(699, $sum);
                    self::assertSame(
                        $profile + 1,
                        $core,
                        "core's tie side must be exactly one slot brighter at ($r,$g,$b)",
                    );
                }
            }
        }

        self::assertSame(self::CENSUS_SIZE, $checked, 'census shape changed');
        self::assertSame(
            self::CENSUS_DIVERGED,
            $diverged,
            'seam census moved: one encoder changed without the other',
        );
    }

    /**
     * Uniform-grey midpoints, one row per midpoint: [core slot, profile slot].
     * Nineteen diverge by one slot; 93/133/173/213 are the four where the
     * cube candidate is strictly nearer and both encoders pick it (59, 102,
     * 145, 188) — proof that the seam never overrides the cube/grey choice.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function uniformGreyMidpointProvider(): array
    {
        return [
            '13'  => [13, 233, 232],
            '23'  => [23, 234, 233],
            '33'  => [33, 235, 234],
            '43'  => [43, 236, 235],
            '53'  => [53, 237, 236],
            '63'  => [63, 238, 237],
            '73'  => [73, 239, 238],
            '83'  => [83, 240, 239],
            '93'  => [93, 59, 59],
            '103' => [103, 242, 241],
            '113' => [113, 243, 242],
            '123' => [123, 244, 243],
            '133' => [133, 102, 102],
            '143' => [143, 246, 245],
            '153' => [153, 247, 246],
            '163' => [163, 248, 247],
            '173' => [173, 145, 145],
            '183' => [183, 250, 249],
            '193' => [193, 251, 250],
            '203' => [203, 252, 251],
            '213' => [213, 188, 188],
            '223' => [223, 254, 253],
            '233' => [233, 255, 254],
        ];
    }

    /**
     * @param array{int, int, int} $row
     * @dataProvider uniformGreyMidpointProvider
     */
    public function testUniformGreyMidpointsPinBothTieSides(int $value, int $coreSlot, int $profileSlot): void
    {
        self::assertSame($coreSlot, self::coreIndex($value, $value, $value));
        self::assertSame($profileSlot, (new Color($value, $value, $value))->toAnsi256Index());
    }

    /**
     * The provider above must cover every midpoint in the seam window —
     * a new midpoint cannot be added silently.
     */
    public function testTheMidpointRosterIsComplete(): void
    {
        $provider = self::uniformGreyMidpointProvider();
        $values = array_values(array_map(static fn (array $row): int => $row[0], $provider));
        self::assertSame(self::MIDPOINTS, $values);
    }
}
