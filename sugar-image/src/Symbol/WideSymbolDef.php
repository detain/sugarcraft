<?php

declare(strict_types=1);

namespace SugarCraft\Image\Symbol;

/**
 * A-09: one candidate WIDE glyph of the coverage-class corpus — chafa's
 * ChafaSymbol2: a 16x8-pixel cell carried as TWO 8x8 bitmaps (sym[0] the
 * left half, sym[1] the right half), each packed row-major MSB-first like
 * {@see SymbolDef::$bits}, scored jointly as hd(left) + hd(right) against
 * the two canvas cells it would replace (chafa/internal/chafa-symbols.c
 * init_symbol_array_wide: left = def_to_symbol offset 0 rowstride 16,
 * right = offset 8).
 *
 * A wide glyph occupies two terminal columns; the cell under its right
 * half becomes a continuation the printer skips (chafa-canvas-printer.c
 * `if (cell->c == 0) continue;`). The pairing bookkeeping lives in the
 * renderer's lookback pass (see the A-09 note in {@see SymbolMap}); this
 * class is only the packed datum.
 *
 * The weight is the joint popcount over both halves (chafa calc_weights
 * runs per half; the pair weight is their sum), so bgWeight() counts the
 * coverage-0 pixels of the full 128-pixel cell pair.
 *
 * Mirrors chafa.ChafaSymbol2 (chafa/internal/chafa-symbols.h).
 */
final class WideSymbolDef
{
    /**
     * @param string $char      the glyph itself (single UTF-8 codepoint, width 2)
     * @param int    $bitsLeft  left 8x8 coverage bitmap, row-major MSB-first
     * @param int    $bitsRight right 8x8 coverage bitmap, row-major MSB-first
     * @param int    $tags      SymbolTags bitmask (informational in v1)
     * @param int    $weight    number of set pixels across both halves (0..128)
     */
    private function __construct(
        public readonly string $char,
        public readonly int $bitsLeft,
        public readonly int $bitsRight,
        public readonly int $tags,
        public readonly int $weight,
    ) {
    }

    public static function new(string $char, int $bitsLeft, int $bitsRight, int $tags): self
    {
        return new self($char, $bitsLeft, $bitsRight, $tags, self::popcount($bitsLeft) + self::popcount($bitsRight));
    }

    /**
     * Decode one 16x8 outline row-string ('|'-joined rows of 16 'X'/space
     * characters, {@see SymbolSetKana::OUTLINES} shape) into the packed
     * half bitmaps — chafa's def_to_symbol with rowstride 16.
     */
    public static function fromOutline16x8(string $char, string $outline, int $tags): self
    {
        $rows = explode('|', $outline);
        if (\count($rows) !== 8) {
            throw new \InvalidArgumentException('wide outline must hold exactly 8 rows');
        }

        $left = 0;
        $right = 0;
        foreach ($rows as $row) {
            if (\strlen($row) !== 16 || preg_match('/[^ X]/', $row) === 1) {
                throw new \InvalidArgumentException('wide outline rows must be exactly 16 spaces/X characters');
            }
            for ($u = 0; $u < 8; $u++) {
                $left = ($left << 1) | ($row[$u] === 'X' ? 1 : 0);
            }
            for ($u = 8; $u < 16; $u++) {
                $right = ($right << 1) | ($row[$u] === 'X' ? 1 : 0);
            }
        }

        return self::new($char, $left, $right, $tags);
    }

    /** Number of coverage-0 pixels the background pen paints across both halves. */
    public function bgWeight(): int
    {
        return 128 - $this->weight;
    }

    private static function popcount(int $x): int
    {
        // Sign bit handled by test-then-mask, same law as SymbolMap::popcount:
        // x & (x-1) at PHP_INT_MIN overflows to float and wedges the loop.
        $n = ($x >> 63) & 1;
        $x &= PHP_INT_MAX;
        while ($x !== 0) {
            $x &= $x - 1;
            $n++;
        }

        return $n;
    }
}
