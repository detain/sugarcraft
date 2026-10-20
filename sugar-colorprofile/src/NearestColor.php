<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

/**
 * True nearest-color matching against fixed (or arbitrary) palettes.
 *
 * Unlike {@see Color::toAnsi256Index()} — which *rounds* each channel into the
 * 6×6×6 cube with a greyscale special case — this matcher searches the palette
 * for the minimum-distance entry under a pluggable {@see ColorDistance}
 * strategy. The default strategy is EUCLIDEAN in sRGB; the CIE strategies are
 * opt-in perceptual upgrades.
 *
 * Cost control: L*a*b* values for the full 256-entry ANSI palette (the 16
 * standard colors, the 6×6×6 cube and the grey ramp) are computed once per
 * process and memoized statically — a CIEDE2000 match otherwise pays 256
 * conversions per call, and media clients quantize palettes per frame. Measured
 * on PHP 8.3 (see NearestColorTest): a warm CIEDE2000 search over all 256
 * entries costs about 1 ms; re-converting the palette would add ~40 % on top.
 *
 * Palette table: indices 0-15 are the {@see StandardColors} ANSI-16 set;
 * 16-231 the canonical xterm 6×6×6 cube (channel levels 0, 95, 135, 175, 215,
 * 255 — {@see Color::CUBE_LEVELS}); 232-255 the 8+10n grey ramp. The table is
 * built straight from {@see Color::fromAnsi256Index()} so the matcher's
 * palette, the encoder's rounding targets, and sugar-core's Util\Color::ansi256
 * share ONE source of truth (a cross-library pin guards this — the old local
 * even-51-step cube diverged from every real terminal in 721 channel values
 * and made encode/decode round-trips disagree with matching).
 *
 * Exact ties resolve to the first matching palette entry (lowest index).
 */
final class NearestColor
{
    /** @var list<Color>|null Memoized ANSI-256 palette (index == palette key). */
    private static ?array $ansi256Palette = null;

    /** @var list<array{l: float, a: float, b: float}>|null Memoized Lab of {@see self::$ansi256Palette}. */
    private static ?array $ansi256PaletteLab = null;

    /** @var list<array{l: float, a: float, b: float}>|null Memoized Lab of the ANSI-16 palette. */
    private static ?array $ansi16PaletteLab = null;

    /** B-07 damping applied to the stored error before it pushes the query, per chafa chafa-palette.c:1170. */
    private const ERROR_DAMPING = 0.9;

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
     * B-07: error-compensated nearest lookup against the ANSI-256 palette.
     *
     * Mirrors chafa chafa-palette.c:1160-1197 (`chafa_palette_lookup_with_error`):
     * the query is pushed toward accumulated quantization error, damped by a
     * factor of 0.9/16 (the damping prevents the feedback oscillation a raw
     * residual would cause across a scanline), and the palette entry is chosen
     * for the COMPENSATED query. The new residual — compensated query minus
     * chosen color, computed from the UNCLAMPED compensated value so a pinned
     * channel keeps its push into the next lookup — is written back through
     * $err, forming the error-diffusion chain that pairs with ordered dithering.
     *
     * $err is a channel accumulator `[r, g, b]` in 0-255 units (floats, or
     * ints); passing null (or by reference a variable that is null) means
     * "no feedback" and the call is exactly {@see self::ansi256()} — plain
     * nearest, no writes.
     *
     * The `with` in the name reads "accompanied by", not the immutable-setter
     * convention: this is a lookup with a by-reference feedback vector, the
     * shape the survey (B-07) prescribes verbatim for dither loops.
     *
     * @param ?array{0: float|int, 1: float|int, 2: float|int} $err in/out accumulator, list-shaped triple
     *
     * @throws \InvalidArgumentException when $err is non-null but not a
     *                                   3-element list of numbers
     */
    public function withError(Color $color, ?array &$err = null): int
    {
        if ($err === null) {
            return $this->ansi256($color);
        }

        if (!array_is_list($err) || count($err) !== 3) {
            throw new \InvalidArgumentException(
                'withError() expects a 3-element list [r, g, b]; got ' . count($err) . ' element(s)',
            );
        }
        foreach ($err as $channel) {
            if (!is_int($channel) && !is_float($channel)) {
                throw new \InvalidArgumentException(
                    'withError() accumulator channels must be int or float; got ' . get_debug_type($channel),
                );
            }
        }

        // Damped feedback, chafa :1170-1175. The lookup uses the clamped,
        // rounded result (Color's ctor clamps); the residual written back uses
        // the unclamped float, chafa :1189-1192.
        $damped = [];
        foreach ([0 => $color->r, 1 => $color->g, 2 => $color->b] as $i => $channel) {
            $damped[$i] = $channel + ($err[$i] * self::ERROR_DAMPING) / 16;
        }

        $index = $this->ansi256(new Color(
            (int) round($damped[0]),
            (int) round($damped[1]),
            (int) round($damped[2]),
        ));

        $found = self::palette256()[$index];
        $err = [
            $damped[0] - $found->r,
            $damped[1] - $found->g,
            $damped[2] - $found->b,
        ];

        return $index;
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
        for ($index = 16; $index < 256; $index++) {
            $palette[] = Color::fromAnsi256Index($index);
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
