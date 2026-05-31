<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

/**
 * Repeat the preceding character N times using REP sequence
 * \x1b[N b.
 *
 * REP repeats the last printed character.  It requires N >= 1.
 * It is emitted only when the run length justifies the overhead
 * (typically N >= 2) and the preceding cell in the output stream
 * is the same rune.
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
