<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

/**
 * 256-entry luminance-to-character lookup table.
 *
 * BT.709 luma is approximated with the integer formula
 * (77R + 150G + 29B) >> 8 — the same weights used by tplay and
 * other video-to-ascii tools for perceptual accuracy.
 * (This is the SMPTE-C / BT.601 weighting; the exact BT.709 integer
 * approximation (54R + 183G + 19B) >> 8 is visually indistinguishable
 * in practice and the 77/150/29 coefficients are used by upstream tplay.)
 *
 * Mirrors charmbracelet/sugar-reel luma.Ramp.
 */
final class LumaRamp
{
    /**
     * Minimal 10-character ramp — space through @.
     */
    public const CHARS1 = ' .:-=+*#%@';

    /**
     * Standard 16-character ramp for general use.
     */
    public const CHARS2 = ' .,:;i1tfLCG08@';

    /**
     * Dense 70-character ramp for high-fidelity ASCII art.
     */
    public const CHARS3 = ' .\`^",:;Il!i><~+_-?][}{1)(|\\/tfjrxnuvczXYUJCLQ0OZmwqpdbkhao*#MW&8%B@$';

    /**
     * Default ramp name when none specified.
     */
    private const DEFAULT_RAMP = 'standard';

    /**
     * All available named ramps keyed by name.
     *
     * @var array<string, string>
     */
    private const RAMPS = [
        'minimal'  => self::CHARS1,
        'standard'  => self::CHARS2,
        'dense'    => self::CHARS3,
    ];

    /**
     * The pre-built 256-entry character LUT.
     *
     * @var array<int, string>
     */
    private static array $lut = [];

    /**
     * Return the 256-entry character array for the named ramp.
     *
     * @param string $name Ramp name: 'minimal', 'standard', 'dense'
     * @return array<int, string> 256-entry array indexed by luminance 0-255
     */
    public static function ramp(string $name = self::DEFAULT_RAMP): array
    {
        $chars = self::RAMPS[$name] ?? self::RAMPS[self::DEFAULT_RAMP];
        $len = \strlen($chars);

        // Build LUT lazily — 256 bytes of storage, computed once.
        if (self::$lut === []) {
            self::buildLut($name);
        }

        $ramp = [];
        for ($i = 0; $i < 256; $i++) {
            // Map 0-255 luminance to ramp character index.
            // Higher luminance → darker character (convention for ASCII art).
            $index = (int)(($i * $len) / 256);
            $ramp[$i] = $chars[\min($index, $len - 1)];
        }

        return $ramp;
    }

    /**
     * Return the character for a given 0-255 luminance value.
     *
     * Uses the default ramp (standard) in the hot path — callers
     * that need a specific ramp should use ramp() directly.
     *
     * @param float $luma Luminance value 0.0 - 255.0
     * @return string Single character representing the luminance
     */
    public static function char(float $luma): string
    {
        $index = (int)\min(255, \max(0, $luma));
        $ramp = self::ramp(self::DEFAULT_RAMP);
        return $ramp[$index];
    }

    /**
     * Compute BT.709 luminance from RGB components.
     *
     * Y = 0.2126R + 0.7152G + 0.0722B
     * Integer approximation: (77*R + 150*G + 29*B) >> 8
     *
     * @param int $r Red component 0-255
     * @param int $g Green component 0-255
     * @param int $b Blue component 0-255
     * @return int Luminance value 0-255
     */
    public static function compute(int $r, int $g, int $b): int
    {
        return (($r * 77) + ($g * 150) + ($b * 29)) >> 8;
    }

    /**
     * Pre-build the 256-entry LUT for a named ramp.
     *
     * @param string $name Ramp name
     */
    private static function buildLut(string $name): void
    {
        $chars = self::RAMPS[$name] ?? self::RAMPS[self::DEFAULT_RAMP];
        $len = \strlen($chars);

        for ($i = 0; $i < 256; $i++) {
            $index = (int)(($i * $len) / 256);
            self::$lut[$i] = $chars[\min($index, $len - 1)];
        }
    }
}
