<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * CIE color-difference (delta-E) metrics computed on CIE L*a*b* (D65) triples.
 *
 * Every method takes the Lab pairs as `array{l: float, a: float, b: float}` —
 * the shape produced by {@see ColorMath::toLab()} — and validates that shape at
 * the boundary before any math runs. Higher-penalty differences:
 *
 * - {@see cie76()} — plain Euclidean distance in Lab; ignores perceptual
 *   non-uniformity (overestimates blues, underestimates greens).
 * - {@see cie94()} — chroma/hue weighted; graphic-arts parametrisation.
 * - {@see cie2000()} — adds the rotation term for the blue region; the most
 *   accurate of the three and the current CIE recommendation.
 *
 * @see CIE 15:2004, "Colorimetry", 3rd ed. — ΔE*ab (1976) and ΔE94
 * @see G. Sharma, W. Wu, E. N. Dalal, "The CIEDE2000 Color-Difference Formula",
 *      Color Research & Application 30(1), 2005 — CIEDE2000 + test data
 */
final class DeltaE
{
    /** CIE94 graphic-arts weighting constants (kL = kC = kH = 1, K1 = K2 = 0). */
    private const CIE94_CHROMA_FACTOR = 0.045;
    private const CIE94_HUE_FACTOR = 0.015;

    /** 25^7 — the CIEDE2000 chroma-extension divisor, precomputed. */
    private const CIEDE2000_G_DIVISOR = 6103515625.0;

    /**
     * CIE 1976 color difference: Euclidean distance in L*a*b*.
     *
     * Mirrors CIE 15:2004 §8 (ΔE*ab).
     */
    public static function cie76(array $labA, array $labB): float
    {
        [$l1, $a1, $b1] = self::components($labA, 'labA');
        [$l2, $a2, $b2] = self::components($labB, 'labB');

        $deltaL = $l1 - $l2;
        $deltaA = $a1 - $a2;
        $deltaB = $b1 - $b2;

        return sqrt($deltaL * $deltaL + $deltaA * $deltaA + $deltaB * $deltaB);
    }

    /**
     * CIE 1994 color difference in the graphic-arts parametrisation.
     *
     * The first triple is treated as the reference (its chroma drives the SC/SH
     * weightings), per CIE 15:2004 ΔE94 with kL:kC:kH = 1:1:1, l = C = 1.
     * The metric is therefore not perfectly symmetric for unequal chromas.
     */
    public static function cie94(array $labA, array $labB): float
    {
        [$l1, $a1, $b1] = self::components($labA, 'labA');
        [$l2, $a2, $b2] = self::components($labB, 'labB');

        $chroma1 = sqrt($a1 * $a1 + $b1 * $b1);
        $chroma2 = sqrt($a2 * $a2 + $b2 * $b2);

        $deltaL = $l1 - $l2;
        $deltaC = $chroma1 - $chroma2;
        $deltaH2 = ($a1 - $a2) ** 2 + ($b1 - $b2) ** 2 - $deltaC * $deltaC;
        if ($deltaH2 < 0) {
            // Algebraically >= 0; clamp away float noise.
            $deltaH2 = 0.0;
        }

        $sL = 1.0;
        $sC = 1 + self::CIE94_CHROMA_FACTOR * $chroma1;
        $sH = 1 + self::CIE94_HUE_FACTOR * $chroma1;

        return sqrt(
            ($deltaL / $sL) ** 2
            + ($deltaC / $sC) ** 2
            + $deltaH2 / ($sH * $sH)
        );
    }

    /**
     * CIEDE2000 (ΔE00) color difference.
     *
     * Formula as printed in Sharma, Wu & Dalal (2005) with all parametric
     * weighting factors kL = kC = kH = 1. Validated against the 34 published
     * test pairs (see DeltaETest).
     */
    public static function cie2000(array $labA, array $labB): float
    {
        [$l1, $a1, $b1] = self::components($labA, 'labA');
        [$l2, $a2, $b2] = self::components($labB, 'labB');

        $chroma1 = sqrt($a1 * $a1 + $b1 * $b1);
        $chroma2 = sqrt($a2 * $a2 + $b2 * $b2);
        $chromaBar = ($chroma1 + $chroma2) / 2;

        $g = 0.5 * (1 - sqrt($chromaBar ** 7 / ($chromaBar ** 7 + self::CIEDE2000_G_DIVISOR)));

        $a1Prime = (1 + $g) * $a1;
        $a2Prime = (1 + $g) * $a2;
        $chroma1Prime = sqrt($a1Prime * $a1Prime + $b1 * $b1);
        $chroma2Prime = sqrt($a2Prime * $a2Prime + $b2 * $b2);

        $h1Prime = self::hueAngle($a1Prime, $b1);
        $h2Prime = self::hueAngle($a2Prime, $b2);

        $deltaLPrime = $l2 - $l1;
        $deltaCPrime = $chroma2Prime - $chroma1Prime;
        $deltaHPrime = 2 * sqrt($chroma1Prime * $chroma2Prime)
            * sin(deg2rad(self::shortestHueArc($h1Prime, $h2Prime)) / 2);

        $lBarPrime = ($l1 + $l2) / 2;
        $cBarPrime = ($chroma1Prime + $chroma2Prime) / 2;
        $hBarPrime = self::meanHueAngle($h1Prime, $h2Prime, $chroma1Prime * $chroma2Prime);

        $t = 1
            - 0.17 * cos(deg2rad($hBarPrime - 30))
            + 0.24 * cos(deg2rad(2 * $hBarPrime))
            + 0.32 * cos(deg2rad(3 * $hBarPrime + 6))
            - 0.20 * cos(deg2rad(4 * $hBarPrime - 63));

        $deltaTheta = 30 * exp(-((($hBarPrime - 275) / 25) ** 2));
        $radicalC = 2 * sqrt($cBarPrime ** 7 / ($cBarPrime ** 7 + self::CIEDE2000_G_DIVISOR));

        $lOffset = $lBarPrime - 50;
        $sL = 1 + (0.015 * $lOffset * $lOffset) / sqrt(20 + $lOffset * $lOffset);
        $sC = 1 + 0.045 * $cBarPrime;
        $sH = 1 + 0.015 * $cBarPrime * $t;

        $rotationTerm = -sin(deg2rad(2 * $deltaTheta)) * $radicalC;

        $lTerm = $deltaLPrime / $sL;
        $cTerm = $deltaCPrime / $sC;
        $hTerm = $deltaHPrime / $sH;

        return sqrt(
            $lTerm * $lTerm
            + $cTerm * $cTerm
            + $hTerm * $hTerm
            + $rotationTerm * $cTerm * $hTerm
        );
    }

    /**
     * Parse one Lab triple into trusted finite floats, failing loudly on malformed input.
     *
     * @return array{0: float, 1: float, 2: float} [L, a, b]
     */
    private static function components(array $lab, string $label): array
    {
        $components = [];
        foreach (['l', 'a', 'b'] as $key) {
            if (!isset($lab[$key]) || !is_numeric($lab[$key])) {
                throw new \InvalidArgumentException(
                    Lang::t('deltae.invalid_lab', ['label' => $label, 'key' => $key]),
                );
            }
            $value = (float) $lab[$key];
            if (!is_finite($value)) {
                // is_numeric() happily admits NAN / "1e999"; those would poison
                // every downstream sqrt/cos with silent NAN results.
                throw new \InvalidArgumentException(
                    Lang::t('deltae.invalid_lab', ['label' => $label, 'key' => $key]),
                );
            }
            $components[] = $value;
        }
        return $components;
    }

    /**
     * CIE hue angle h' = atan2(b', a') in degrees, wrapped to [0, 360).
     */
    private static function hueAngle(float $aPrime, float $bPrime): float
    {
        if ($aPrime == 0.0 && $bPrime == 0.0) {
            return 0.0;
        }
        $degrees = rad2deg(atan2($bPrime, $aPrime));
        return $degrees < 0 ? $degrees + 360 : $degrees;
    }

    /**
     * Signed shortest arc from $h1 to $h2 in degrees, within [-180, 180].
     */
    private static function shortestHueArc(float $h1, float $h2): float
    {
        $arc = fmod($h2 - $h1, 360);
        if ($arc > 180) {
            return $arc - 360;
        }
        if ($arc < -180) {
            return $arc + 360;
        }
        return $arc;
    }

    /**
     * CIEDE2000 arithmetic mean hue angle h-bar-prime.
     *
     * When either chroma is zero ($chromaProduct == 0) the hues are undefined
     * and the standard collapses the mean to the sum of the two angles.
     */
    private static function meanHueAngle(float $h1, float $h2, float $chromaProduct): float
    {
        if ($chromaProduct == 0.0) {
            return $h1 + $h2;
        }
        if (abs($h1 - $h2) <= 180) {
            return ($h1 + $h2) / 2;
        }
        return ($h1 + $h2 + ($h1 + $h2 < 360 ? 360 : -360)) / 2;
    }
}
