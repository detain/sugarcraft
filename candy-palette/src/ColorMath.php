<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

use SugarCraft\Core\Util\Clamp;

/**
 * Perceptual color-space conversion math: sRGB -> linear -> CIE XYZ (D65) -> CIE L*a*b*.
 *
 * The CIE 1931 2-degree standard observer with the D65 illuminant is assumed
 * throughout, which is the pairing the sRGB standard (IEC 61966-2-1) defines.
 * Bytes outside 0-255 are clamped at this boundary, mirroring the {@see Color}
 * constructor; everything past the boundary is trusted float math.
 *
 * @see https://en.wikipedia.org/wiki/SRGB#From_sRGB_to_CIE_XYZ (IEC 61966-2-1 matrix)
 * @see CIE 15:2004, "Colorimetry", 3rd ed. — XYZ and L*a*b* definitions
 */
final class ColorMath
{
    /** D65 standard-illuminant white point (CIE 15:2004 table). */
    private const WHITE_X = 0.95047;
    private const WHITE_Y = 1.00000;
    private const WHITE_Z = 1.08883;

    /** CIE 1976 L*a*b* piecewise-linear threshold and slope (6/29)^3 and (29/3)^3. */
    private const LAB_EPSILON = 216 / 24389;
    private const LAB_KAPPA = 24389 / 27;

    /** sRGB linearization breakpoint (IEC 61966-2-1). */
    private const SRGB_LINEAR_THRESHOLD = 0.04045;

    /**
     * Convert an sRGB byte triplet to CIE L*a*b* (D65).
     *
     * @param int $r red byte, clamped to 0-255
     * @param int $g green byte, clamped to 0-255
     * @param int $b blue byte, clamped to 0-255
     *
     * @return array{l: float, a: float, b: float}
     */
    public static function toLab(int $r, int $g, int $b): array
    {
        return self::xyzToLab(self::rgbToXyz($r, $g, $b));
    }

    /**
     * Convert an sRGB byte triplet to CIE XYZ (D65), Y normalized to 0-1.
     *
     * @return array{x: float, y: float, z: float}
     */
    public static function rgbToXyz(int $r, int $g, int $b): array
    {
        return self::linearToXyz(
            self::srgbToLinear($r),
            self::srgbToLinear($g),
            self::srgbToLinear($b),
        );
    }

    /**
     * Inverse-compatibility (gamma) decode one sRGB byte to linear light 0-1.
     */
    public static function srgbToLinear(int $byte): float
    {
        $channel = Clamp::byte($byte) / 255;
        if ($channel <= self::SRGB_LINEAR_THRESHOLD) {
            return $channel / 12.92;
        }
        return (($channel + 0.055) / 1.055) ** 2.4;
    }

    /**
     * Apply the IEC 61966-2-1 sRGB -> CIE XYZ (D65) matrix to linear light.
     *
     * @return array{x: float, y: float, z: float}
     */
    public static function linearToXyz(float $r, float $g, float $b): array
    {
        return [
            'x' => 0.4124564 * $r + 0.3575761 * $g + 0.1804375 * $b,
            'y' => 0.2126729 * $r + 0.7151522 * $g + 0.0721750 * $b,
            'z' => 0.0193339 * $r + 0.1191920 * $g + 0.9503041 * $b,
        ];
    }

    /**
     * Convert CIE XYZ (D65) to CIE L*a*b* (CIE 1976).
     *
     * @param array{x: float, y: float, z: float} $xyz
     *
     * @return array{l: float, a: float, b: float}
     */
    public static function xyzToLab(array $xyz): array
    {
        $fx = self::labCurve($xyz['x'] / self::WHITE_X);
        $fy = self::labCurve($xyz['y'] / self::WHITE_Y);
        $fz = self::labCurve($xyz['z'] / self::WHITE_Z);

        return [
            'l' => 116 * $fy - 16,
            'a' => 500 * ($fx - $fy),
            'b' => 200 * ($fy - $fz),
        ];
    }

    /**
     * The CIE 1976 cube-root / linear piecewise curve f(t).
     */
    private static function labCurve(float $t): float
    {
        if ($t > self::LAB_EPSILON) {
            return $t ** (1 / 3);
        }
        return (self::LAB_KAPPA * $t + 16) / 116;
    }
}
