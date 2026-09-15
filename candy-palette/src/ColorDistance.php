<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * Distance-metric strategy for nearest-color matching.
 *
 * `EUCLIDEAN` is the legacy behavior: raw Euclidean distance in sRGB space,
 * cheap but perceptually skewed (it mis-ranks blues/greens and near-greys).
 * The CIE strategies convert to L*a*b* via {@see ColorMath} and are opt-in —
 * pair them with {@see NearestColor}'s memoized palette Lab values to keep
 * per-frame matching affordable.
 */
enum ColorDistance: string
{
    /** Euclidean distance in 0-255 sRGB space — the default, cheapest option. */
    case Euclidean = 'euclidean';

    /** Euclidean distance in L*a*b* (CIE 1976). */
    case Cie76 = 'cie76';

    /** CIE 1994, graphic-arts parametrisation (kL:kC:kH = 1:1:1). */
    case Cie94 = 'cie94';

    /** CIEDE2000 — the most accurate, the most expensive. */
    case Cie2000 = 'cie2000';

    /**
     * Distance between two colors under this metric.
     */
    public function between(Color $a, Color $b): float
    {
        if ($this === self::Euclidean) {
            $dr = $a->r - $b->r;
            $dg = $a->g - $b->g;
            $db = $a->b - $b->b;
            return sqrt($dr * $dr + $dg * $dg + $db * $db);
        }

        return $this->betweenLab(
            ColorMath::toLab($a->r, $a->g, $a->b),
            ColorMath::toLab($b->r, $b->g, $b->b),
        );
    }

    /**
     * Distance between pre-computed Lab triples — the hot-loop entry point,
     * letting callers amortize the sRGB -> Lab conversion over a memoized
     * palette. EUCLIDEAN is an RGB-space metric with no meaning here: it
     * throws instead of silently substituting Lab-space magnitudes, so mixing
     * the two scales fails loudly.
     *
     * @param array{l: float, a: float, b: float} $labA
     * @param array{l: float, a: float, b: float} $labB
     *
     * @throws \LogicException when this case is EUCLIDEAN (use {@see self::between()})
     */
    public function betweenLab(array $labA, array $labB): float
    {
        return match ($this) {
            self::Euclidean => throw new \LogicException(Lang::t('distance.euclidean_needs_rgb')),
            self::Cie76 => DeltaE::cie76($labA, $labB),
            self::Cie94 => DeltaE::cie94($labA, $labB),
            self::Cie2000 => DeltaE::cie2000($labA, $labB),
        };
    }
}
