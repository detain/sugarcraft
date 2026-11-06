<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Chart;

/**
 * Maps a price onto a chart row for the financial chart pair.
 *
 * Literal twin extracted from CandlestickChart and OHLC during the
 * per-family duplication follow-through: both copies (method AND docblock)
 * were byte-identical; both consumers are final and the helper reads
 * only the identical `?float $minPrice/$maxPrice` pair each class
 * maintains. Precedent: ChartGridGeometry (the same extraction precedent).
 *
 * The sibling helper innerSize() is NOT here — it is the Dash
 * Foundation Sizer/Item contract method with a different default pair
 * per chart class (65x15 vs 65x15 today, but Funnel/Partition/Waterfall
 * all answer other numerics, several data-derived); hoisting it into a
 * shared trait would freeze an accidental coincidence, not a rule.
 */
trait PriceAxisProjection
{
    /**
     * Convert a price to Y coordinate.
     */
    private function priceToY(float $price, int $height): int
    {
        $range = $this->maxPrice - $this->minPrice;
        if ($range == 0) {
            return intval($height / 2);
        }
        $normalized = ($price - $this->minPrice) / $range;
        return intval($normalized * ($height - 1));
    }
}
