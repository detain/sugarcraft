<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

/**
 * True nearest-color matching against fixed (or arbitrary) palettes.
 *
 * Unlike {@see Color::toAnsi256Index()} — which *rounds* each channel into the
 * 6×6×6 cube with a grayscale special case — this matcher searches the palette
 * for the minimum-distance entry under a pluggable {@see ColorDistance}
 * strategy. The default strategy is EUCLIDEAN in sRGB; the CIE strategies are
 * opt-in perceptual upgrades.
 *
 * Cost control: L*a*b* values for the full 256-entry ANSI palette (the 16
 * standard colors, the 6×6×6 cube and the gray ramp) are memoized statically,
 * keyed on a fingerprint of the mutable {@see StandardColors} slots — while the
 * slots are untouched each table stays the same instance (the hot-loop guard a
 * CIEDE2000 match needs: it otherwise pays 256 conversions per call, and media
 * clients quantize palettes per frame), and a redefinition lands on the next
 * call instead of hiding behind a frozen memo. Measured on PHP 8.3 (see
 * NearestColorTest): a warm CIEDE2000 search over all 256 entries costs about
 * 1 ms; re-converting the palette would add ~40 % on top.
 *
 * Palette table: indices 0-15 are the {@see StandardColors} ANSI-16 set;
 * 16-231 the canonical xterm 6×6×6 cube (channel levels 0, 95, 135, 175, 215,
 * 255 — {@see Color::CUBE_LEVELS}); 232-255 the 8+10n gray ramp. The table is
 * built straight from {@see Color::fromAnsi256Index()} so the matcher's
 * palette, the encoder's rounding targets, and sugar-core's Util\Color::ansi256
 * share ONE source of truth (a cross-library pin guards this — the old local
 * even-51-step cube diverged from every real terminal in 721 channel values
 * and made encode/decode round-trips disagree with matching).
 *
 * Exact ties resolve to the first matching palette entry (lowest index).
 *
 * Construction follows the house factory convention (CP-49, finding
 * -062/DX-14): {@see self::new()} for the default metric and
 * {@see self::forMetric()} when a strategy is chosen on purpose — the
 * constructor is the private implementation door.
 */
final class NearestColor
{
    /** @var list<Color>|null Memoized ANSI-256 palette (index == palette key), valid while {@see self::$ansi256PaletteKey} matches. */
    private static ?array $ansi256Palette = null;

    /** @var string|null Fingerprint of the ANSI-16 slots {@see self::$ansi256Palette} was built from. */
    private static ?string $ansi256PaletteKey = null;

    /** @var list<array{l: float, a: float, b: float}>|null Memoized Lab of {@see self::$ansi256Palette}, same key guard. */
    private static ?array $ansi256PaletteLab = null;

    /** @var string|null Fingerprint of the ANSI-16 slots {@see self::$ansi256PaletteLab} was built from. */
    private static ?string $ansi256PaletteLabKey = null;

    /** @var list<array{l: float, a: float, b: float}>|null Memoized Lab of the ANSI-16 palette, same key guard. */
    private static ?array $ansi16PaletteLab = null;

    /** @var string|null Fingerprint of the ANSI-16 slots {@see self::$ansi16PaletteLab} was built from. */
    private static ?string $ansi16PaletteLabKey = null;

    /**
     * The 256-color decoder tail (indices 16-255). Frozen by the xterm-256
     * color cube definition — unlike the ANSI-16 slots it can never be
     * redefined, so it is built once and shared across palette rebuilds.
     *
     * @var list<Color>|null
     */
    private static ?array $ansi256Tail = null;

    /** Damping applied to the stored error before it pushes the query, per chafa chafa-palette.c:1170. */
    private const ERROR_DAMPING = 0.9;

    /**
     * @param ColorDistance $metric distance strategy, defaulting to the shared
     *                              seat {@see ColorDistance::DEFAULT_METRIC}:
     *                              PaletteIndex defaults to Cie76 (perceptual,
     *                              matches chafa re-search), NearestColor stays
     *                              Euclidean (byte-compat legacy — CALIBER
     *                              [perceptual-distance]); documented split,
     *                              2.0 candidate.
     */
    private function __construct(
        private readonly ColorDistance $metric = ColorDistance::DEFAULT_METRIC,
    ) {
    }

    /**
     * House front door: a matcher on the default metric seat (CP-49).
     */
    public static function new(ColorDistance $metric = ColorDistance::DEFAULT_METRIC): self
    {
        return new self($metric);
    }

    /**
     * House front door spelling an intentional strategy choice (CP-49).
     */
    public static function forMetric(ColorDistance $metric): self
    {
        return new self($metric);
    }

    /**
     * The distance strategy this matcher was built with.
     */
    public function metric(): ColorDistance
    {
        return $this->metric;
    }

    /**
     * @deprecated 2026-11-13 (CP-49, finding -062/DX-14) — the `with` prefix
     *             masqueraded as the immutable-setter convention; use
     *             {@see self::bestMatchWithError()} (the error-compensated
     *             palette lookup mirroring chafa chafa-palette.c:1160-1197).
     *             This bridge is a same-behaviour passthrough — by-reference
     *             feedback forwarding intact — pinned by
     *             NearestColorErrorCompensationTest and removable at 2.0.
     */
    public function withError(Color $color, ?array &$err = null): int
    {
        return $this->bestMatchWithError($color, $err);
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
     * Error-compensated nearest lookup against the ANSI-256 palette.
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
     * The mirror is float-vs-integer, by choice and disclosed: chafa's
     * compensation lands in a gint16 (damped fraction truncated before the
     * clamp; residual computed from the truncated integer) while this
     * implementation rounds for the lookup and keeps the fraction in the
     * residual — see the inline note in the body for the two behavioral
     * consequences.
     *
     * $err is a channel accumulator `[r, g, b]` in 0-255 units (floats, or
     * ints); passing null (or by reference a variable that is null) means
     * "no feedback" and the call is exactly {@see self::ansi256()} — plain
     * nearest, no writes.
     *
     * The name says the shape: this is a lookup with a by-reference feedback
     * vector (the form chafa's dither loop prescribes verbatim), NOT an
     * immutable setter — CP-49 retired the old `withError` spelling, whose
     * `with` prefix read as the setter convention at a glance; a deprecated
     * same-behaviour bridge keeps that name answering until 2.0.
     *
     * @param ?array{0: float|int, 1: float|int, 2: float|int} $err in/out accumulator, list-shaped triple
     *
     * @throws \InvalidArgumentException when $err is non-null but not a
     *                                   3-element list of numbers
     */
    public function bestMatchWithError(Color $color, ?array &$err = null): int
    {
        if ($err === null) {
            return $this->ansi256($color);
        }

        if (!array_is_list($err) || count($err) !== 3) {
            throw new \InvalidArgumentException(
                Lang::t('nearest.error_shape', ['count' => count($err)]),
            );
        }
        foreach ($err as $channel) {
            if (!is_int($channel) && !is_float($channel)) {
                throw new \InvalidArgumentException(
                    Lang::t('nearest.error_channel', ['type' => get_debug_type($channel)]),
                );
            }
        }

        // Damped feedback, chafa :1170-1175 and :1189-1192 — with one
        // disclosed arithmetic split: chafa stores the damped compensation
        // in gint16, so it TRUNCATES toward zero before clamping to 0..255
        // for the lookup, and its residual is that truncated (unclamped)
        // integer minus the found color. We keep $damped as exact float:
        // the lookup rounds it (round-half-away; Color's ctor clamps) and
        // the residual written back is the full-precision unclamped
        // difference. In practice: a fractional compensation >= .5 inside
        // the gamut lands one palette code higher than chafa would pick,
        // and our residual carries the compensation fraction chafa drops.
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
     * The 256-color palette as Colors — memoized, keyed on the live
     * {@see StandardColors} slots (indices 0-15) so a redefinition rebuilds it.
     *
     * @return list<Color>
     */
    public static function palette256(): array
    {
        $key = self::slotFingerprint();

        if (self::$ansi256Palette !== null && self::$ansi256PaletteKey === $key) {
            return self::$ansi256Palette;
        }

        if (self::$ansi256Tail === null) {
            $tail = [];
            for ($index = 16; $index < 256; $index++) {
                $tail[] = Color::fromAnsi256Index($index);
            }

            self::$ansi256Tail = $tail;
        }

        $palette = StandardColors::all();
        foreach (self::$ansi256Tail as $entry) {
            $palette[] = $entry;
        }

        self::$ansi256Palette = $palette;
        self::$ansi256PaletteKey = $key;

        return $palette;
    }

    /**
     * L*a*b* of {@see self::palette256()}, memoized behind the same slot
     * fingerprint — the hot-loop guard that makes CIEDE2000 nearest matching
     * affordable while the ANSI-16 slots stay untouched.
     *
     * @return list<array{l: float, a: float, b: float}>
     */
    public static function palette256Lab(): array
    {
        $key = self::slotFingerprint();

        if (self::$ansi256PaletteLab !== null && self::$ansi256PaletteLabKey === $key) {
            return self::$ansi256PaletteLab;
        }

        $labs = [];
        foreach (self::palette256() as $entry) {
            $labs[] = ColorMath::toLab($entry->r, $entry->g, $entry->b);
        }

        self::$ansi256PaletteLab = $labs;
        self::$ansi256PaletteLabKey = $key;

        return $labs;
    }

    /**
     * L*a*b* of the ANSI-16 palette, memoized behind the slot fingerprint so a
     * {@see StandardColors} reassignment lands on the next call.
     *
     * @return list<array{l: float, a: float, b: float}>
     */
    public static function palette16Lab(): array
    {
        $key = self::slotFingerprint();

        if (self::$ansi16PaletteLab !== null && self::$ansi16PaletteLabKey === $key) {
            return self::$ansi16PaletteLab;
        }

        $labs = [];
        foreach (StandardColors::all() as $entry) {
            $labs[] = ColorMath::toLab($entry->r, $entry->g, $entry->b);
        }

        self::$ansi16PaletteLab = $labs;
        self::$ansi16PaletteLabKey = $key;

        return $labs;
    }

    /**
     * Fingerprint of the mutable ANSI-16 slots — the guard key every palette
     * memo is validated against. The 256-index decoder tail needs no key: it
     * is frozen by the xterm-256 definition.
     */
    private static function slotFingerprint(): string
    {
        return implode('|', array_map(
            static fn (Color $color): string => $color->toHex(),
            StandardColors::all(),
        ));
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
