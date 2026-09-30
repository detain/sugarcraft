<?php

declare(strict_types=1);

namespace SugarCraft\Diff;

/**
 * What a single diff row does: carry context, remove an old line, add a new one.
 *
 * The backing values are the source implementation's op tags so a reader
 * holding legacy `['eq'|'del'|'ins']` tuples maps onto them without a table.
 *
 * Mirrors the op vocabulary of sugar-crush/src/Tools/Concerns/BuildsUnifiedDiff.php.
 */
enum LineKind: string
{
    /** Line present on both sides, unchanged. */
    case Equal = 'eq';

    /** Line present only on the old side — a removal. */
    case Delete = 'del';

    /** Line present only on the new side — an addition. */
    case Insert = 'ins';

    /** The unified-format column-0 marker for this kind (`' '`, `'-'`, `'+'`). */
    public function marker(): string
    {
        return match ($this) {
            self::Equal => ' ',
            self::Delete => '-',
            self::Insert => '+',
        };
    }
}
