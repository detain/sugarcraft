<?php

declare(strict_types=1);

namespace SugarCraft\Image\Symbol;

/**
 * One candidate glyph of the coverage-class corpus: its character, the
 * 8x8 coverage it paints packed row-major MSB-first into a 64-bit
 * bitmap (pixel (x,y) = bit 63-(y*8+x)), the family tags, and the
 * precomputed foreground weight (popcount) — chafa stores exactly this
 * on every ChafaSymbol and the scoring path divides the foreground
 * group by it without re-counting (chafa/internal/chafa-symbols.c
 * calc_weights).
 *
 * Mirrors chafa.ChafaSymbol (chafa/internal/chafa-symbols.c).
 */
final class SymbolDef
{
    /**
     * @param string   $char  the glyph itself (single UTF-8 codepoint)
     * @param int      $bits  8x8 coverage bitmap, row-major MSB-first
     * @param int      $tags  SymbolTags bitmask (informational in v1)
     * @param int      $weight number of set bits (foreground pixel count)
     */
    private function __construct(
        public readonly string $char,
        public readonly int $bits,
        public readonly int $tags,
        public readonly int $weight,
    ) {
    }

    public static function new(string $char, int $bits, int $tags): self
    {
        $weight = 0;
        for ($i = 0; $i < 64; $i++) {
            $weight += ($bits >> $i) & 1;
        }

        return new self($char, $bits, $tags, $weight);
    }

    /** Number of coverage-0 pixels the background pen paints (chafa bg_weight). */
    public function bgWeight(): int
    {
        return 64 - $this->weight;
    }
}
