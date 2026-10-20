<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

/**
 * Repeat the preceding character N times using REP sequence
 * \x1b[N b.
 *
 * REP repeats the last printed character.  It requires N >= 1.
 * Buffer::diff() emits it for every qualifying run (run length >= 2);
 * whether the encoded stream carries the ESC[#b sequence or literal
 * repeats is decided at encode time by {@see RepeatGate::worth()}
 * (A-02, chafa printer.c:56-92 byte arithmetic).
 *
 * @readonly
 */
final class RepeatRunOp extends DiffOp
{
    public function __construct(
        public readonly string $rune,
        public readonly int $count,
        /** Display width of $rune in cells (1 or 2; 0 treated as 1). */
        public readonly int $width = 1,
    ) {}
}
