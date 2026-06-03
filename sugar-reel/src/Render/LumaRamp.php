<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Render;

/**
 * 256-entry luminance-to-character lookup table.
 *
 * Uses the BT.601 (SMPTE-C) luma formula (77R + 150G + 29B) >> 8 — the same
 * weights used by tplay and other video-to-ascii tools.
 *
 * No single upstream — the luma-ramp technique is drawn from maxcurzi/tplay,
 * seatedro/glyph, and joelibaceta/video-to-ascii.
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
     * Pre-built 256-entry character LUTs, memoized per ramp name.
     *
     * The hot path (one lookup per pixel, per frame) must be a plain array
     * index, not arithmetic — see char(). Each ramp's table is built once on
     * first use and reused for the life of the process.
     *
     * @var array<string, array<int, string>>
     */
    private static array $lutByName = [];

    /**
     * Return the 256-entry character array for the named ramp.
     *
     * The table is built once per ramp name and cached; repeated calls return
     * the same precomputed array rather than rebuilding it.
     *
     * @param string $name Ramp name: 'minimal', 'standard', 'dense'
     * @return array<int, string> 256-entry array indexed by luminance 0-255
     */
    public static function ramp(string $name = self::DEFAULT_RAMP): array
    {
        return self::$lutByName[$name] ??= self::buildLut($name);
    }

    /**
     * Return true if the named ramp is a valid known ramp.
     */
    public static function isValidRamp(string $name): bool
    {
        return isset(self::RAMPS[$name]);
    }

    /**
     * Return the character for a given 0-255 luminance value.
     *
     * @param float  $luma Luminance value 0.0 - 255.0
     * @param string $ramp Ramp name: 'minimal', 'standard', 'dense' (default: 'standard')
     * @return string Single character representing the luminance
     */
    public static function char(float $luma, string $ramp = 'standard'): string
    {
        $index = (int)\min(255, \max(0, $luma));
        return self::ramp($ramp)[$index];
    }

    /**
     * Compute BT.601 (SMPTE-C) luminance from RGB components.
     *
     * Y = 0.299R + 0.587G + 0.114B
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
     * @return array<int, string> 256-entry luma→char table
     */
    private static function buildLut(string $name): array
    {
        $chars = self::RAMPS[$name] ?? self::RAMPS[self::DEFAULT_RAMP];
        $len = \strlen($chars);

        $lut = [];
        for ($i = 0; $i < 256; $i++) {
            // Map 0-255 luminance to ramp character index.
            // Higher luminance → denser character (convention for ASCII art).
            $index = (int)(($i * $len) / 256);
            $lut[$i] = $chars[\min($index, $len - 1)];
        }

        return $lut;
    }
}
