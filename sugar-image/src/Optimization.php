<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Output-optimization levels for the character-mosaic renderers (A-02).
 *
 * Mirrors chafa's ChafaOptimizations bit field (chafa/chafa-common.h:187-196)
 * — flag VALUES are the upstream bits verbatim so a chafa bug report maps
 * straight onto this vocabulary:
 *
 *  - ReuseAttributes = 1<<0 — print only the SGR channels that actually
 *    change between cells (chafa's emit_attributes_truecolor). In this port
 *    that behaviour is UNCONDITIONAL since A-01/S2.2: OutputOptimizer is a
 *    streaming pen-delta emitter for every mosaic renderer, so requesting
 *    this level changes nothing and None cannot switch it off (the byte-
 *    frozen mosaic goldens pin the delta stream as the default). The case
 *    exists so a caller can state the parity it depends on, and so a future
 *    literal-emitter mode has its negation to turn off against.
 *  - (1<<1) SKIP_CELLS is deliberately ABSENT. chafa itself reserves it —
 *    "Reserved for future use" (chafa-common.h:181 prose; the enumerator
 *    CHAFA_OPTIMIZATION_SKIP_CELLS = (1 << 1) sits at :190) — and no SugarCraft
 *    emitter skips cells. The gap in the enum mirrors the dead bit upstream
 *    instead of inventing a local meaning for it.
 *  - RepeatCells = 1<<2 — coalesce runs of an identical glyph (under an
 *    unchanged pen) into `ESC[count b` (REP). Only byte-saving when the
 *    literal cost beats the framing, so emission goes through
 *    {@see \SugarCraft\CellBuffer\Diff\RepeatGate::worth()} (one gate for
 *    the diff path and the mosaic path), and only when the terminal
 *    advertises REP via {@see Detect::supportsRepeat()}.
 *
 * Levels compose as a bit mask (chafa passes flags, not an exclusive mode):
 * {@see self::bits()} folds cases, {@see self::has()} tests one.
 */
enum Optimization: int
{
    /** Empty mask — no opt-in levels (the shipped default). */
    case None = 0;

    /** chafa REUSE_ATTRIBUTES (1<<0): pen-delta SGR emission. */
    case ReuseAttributes = 1;

    /** chafa REPEAT_CELLS (1<<2): byte-gated REP CSI#b runs. */
    case RepeatCells = 4;

    /**
     * Fold a set of levels into the int mask the emitters consume.
     *
     * None contributes nothing; combining it with real levels yields the
     * real levels' mask (chafa's CHAFA_OPTIMIZATION_NONE is a zero flag,
     * not a veto).
     */
    public static function bits(self ...$levels): int
    {
        $mask = 0;
        foreach ($levels as $level) {
            $mask |= $level->value;
        }

        return $mask;
    }

    /** Whether $mask carries every bit of $level (None answers false: zero has no flag). */
    public static function has(int $mask, self $level): bool
    {
        return $level->value !== 0 && ($mask & $level->value) === $level->value;
    }

    /** The full upstream ladder minus the reserved gap: ReuseAttributes|RepeatCells. */
    public static function all(): int
    {
        return self::bits(self::ReuseAttributes, self::RepeatCells);
    }
}
