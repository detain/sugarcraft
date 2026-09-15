<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * True nearest-color matching against fixed (or arbitrary) palettes.
 *
 * Unlike {@see Color::toAnsi256Index()} — which *rounds* each channel into the
 * 6×6×6 cube with a greyscale special case — this matcher searches the palette
 * for the minimum-distance entry under a pluggable {@see ColorDistance}
 * strategy. The default strategy is EUCLIDEAN in sRGB; the CIE strategies are
 * opt-in perceptual upgrades.
 *
 * Cost control: L*a*b* values for the 240-entry ANSI-256 tail (cube + grey
 * ramp, plus the 16 standard colors) are computed once per process and memoized
 * statically — a CIEDE2000 match otherwise pays 256 conversions per call, and
 * media clients quantize palettes per frame. Measured on PHP 8.3 (see
 * NearestColorTest): a warm CIEDE2000 search over all 256 entries costs well
 * under a millisecond once the memo is primed.
 *
 * Palette table: indices 0-15 are the {@see StandardColors} ANSI-16 set; 16-231
 * are the 6×6×6 cube at this library's even 51-step quantization (the exact
 * inverse of {@see Color::toAnsi256Index()}'s encoder); 232-255 the 8+10n grey
 * ramp. {@see Color::fromAnsi256Index()} is deliberately not used to build the
 * table: its cube decode divides with floats instead of `intdiv()`, so indices
 * 16-231 come back wrong (e.g. 17 -> #010933 instead of #000033).
 *
 * On exact ties the lowest palette index wins.
 */
final class NearestColor
{
    /** Channel step of this library's even 6-level cube (0, 51, 102, 153, 204, 255). */
    private const CUBE_STEP = 51;

    /** First grey ramp byte and its step — indices 232-255, same as Color::fromAnsi256Index(). */
    private const GREY_BASE = 8;
    private const GREY_STEP = 10;

    /** @var list<Color>|null Memoized ANSI-256 palette (index == palette key). */
    private static ?array $ansi256Palette = null;

    /** @var list<array{l: float, a: float, b: float}>|null Memoized Lab of {@see self::$ansi256Palette}. */
    private static ?array $ansi256PaletteLab = null;

    /** @var list<array{l: float, a: float, b: float}>|null Memoized Lab of the ANSI-16 palette. */
    private static ?array $ansi16PaletteLab = null;

    public function __construct(
        private readonly ColorDistance $metric = ColorDistance::Euclidean,
    ) {
    }

    /**
     * The distance strategy this matcher was built with.
     */
    public function metric(): ColorDistance
    {
        return $this->metric;
    }

    /**
     * Nearest index (0-255) in the ANSI-256 palette.
     */
    public function ansi256(Color $color): int
    {
        if ($this->metric === ColorDistance::Euclidean) {
            return self::bestRgbIndex($color, self::palette256());
        }

        return self::bestLabIndex(
            $this->metric,
            ColorMath::toLab($color->r, $color->g, $color->b),
            self::palette256Lab(),
        );
    }

    /**
     * Nearest index (0-15) in the standard ANSI-16 palette.
     */
    public function ansi16(Color $color): int
    {
        if ($this->metric === ColorDistance::Euclidean) {
            return self::bestRgbIndex($color, StandardColors::all());
        }

        return self::bestLabIndex(
            $this->metric,
            ColorMath::toLab($color->r, $color->g, $color->b),
            self::palette16Lab(),
        );
    }

    /**
     * Nearest key in an arbitrary palette map of Colors.
     *
     * No memoization applies here — every candidate is converted per call, so
     * prefer {@see self::ansi256()} / {@see self::ansi16()} for the fixed
     * terminal palettes.
     *
     * @param array<array-key, Color> $palette
     *
     * @throws \InvalidArgumentException when the palette is empty
     */
    public function closest(Color $color, array $palette): int|string
    {
        if ($palette === []) {
            throw new \InvalidArgumentException(Lang::t('nearest.empty_palette'));
        }

        if ($this->metric === ColorDistance::Euclidean) {
            return self::bestRgbIndex($color, $palette);
        }

        $labs = [];
        foreach ($palette as $key => $entry) {
            $labs[$key] = ColorMath::toLab($entry->r, $entry->g, $entry->b);
        }

        return self::bestLabIndex(
            $this->metric,
            ColorMath::toLab($color->r, $color->g, $color->b),
            $labs,
        );
    }

    /**
     * The 256-color palette as Colors — computed once, memoized statically.
     *
     * @return list<Color>
     */
    public static function palette256(): array
    {
        if (self::$ansi256Palette !== null) {
            return self::$ansi256Palette;
        }

        $palette = StandardColors::all();
        for ($index = 16; $index < 232; $index++) {
            $n = $index - 16;
            $palette[] = new Color(
                intdiv($n, 36) * self::CUBE_STEP,
                intdiv($n % 36, 6) * self::CUBE_STEP,
                ($n % 6) * self::CUBE_STEP,
            );
        }
        for ($step = 0; $step < 24; $step++) {
            $grey = self::GREY_BASE + $step * self::GREY_STEP;
            $palette[] = new Color($grey, $grey, $grey);
        }

        return self::$ansi256Palette = $palette;
    }

    /**
     * L*a*b* of {@see self::palette256()}, computed once and memoized — the
     * hot-loop guard that makes CIEDE2000 nearest matching affordable.
     *
     * @return list<array{l: float, a: float, b: float}>
     */
    public static function palette256Lab(): array
    {
        if (self::$ansi256PaletteLab !== null) {
            return self::$ansi256PaletteLab;
        }

        $labs = [];
        foreach (self::palette256() as $entry) {
            $labs[] = ColorMath::toLab($entry->r, $entry->g, $entry->b);
        }

        return self::$ansi256PaletteLab = $labs;
    }

    /**
     * L*a*b* of the ANSI-16 palette, computed once and memoized.
     *
     * @return list<array{l: float, a: float, b: float}>
     */
    public static function palette16Lab(): array
    {
        if (self::$ansi16PaletteLab !== null) {
            return self::$ansi16PaletteLab;
        }

        $labs = [];
        foreach (StandardColors::all() as $entry) {
            $labs[] = ColorMath::toLab($entry->r, $entry->g, $entry->b);
        }

        return self::$ansi16PaletteLab = $labs;
    }

    /**
     * Lowest-key winner by squared Euclidean RGB distance (order-preserving).
     *
     * @param array<array-key, Color> $palette
     */
    private static function bestRgbIndex(Color $needle, array $palette): int|string
    {
        $best = array_key_first($palette);
        $bestDistance = \INF;
        foreach ($palette as $key => $entry) {
            $dr = $needle->r - $entry->r;
            $dg = $needle->g - $entry->g;
            $db = $needle->b - $entry->b;
            $distance = $dr * $dr + $dg * $dg + $db * $db;
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $key;
            }
        }

        return $best;
    }

    /**
     * Lowest-key winner by a Lab-space metric over pre-computed palette Labs.
     *
     * @param array<array-key, array{l: float, a: float, b: float}> $paletteLab
     */
    private static function bestLabIndex(ColorDistance $metric, array $needle, array $paletteLab): int|string
    {
        $best = array_key_first($paletteLab);
        $bestDistance = \INF;
        foreach ($paletteLab as $key => $lab) {
            $distance = $metric->betweenLab($needle, $lab);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $key;
            }
        }

        return $best;
    }
}
