<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Support;

use SugarCraft\Core\Util\Width;

/**
 * Combined fit-then-pad for the render loops (finding-040).
 *
 * The naive pairing — `Width::truncateAnsi` then `Width::pad*` — walks a
 * line three times per frame: truncateAnsi rebuilds the line even when
 * it fits (it has no fast path), and the pad helpers re-measure what
 * was just measured. `fit()` measures ONCE (core's Width::string is
 * memoized) and only pays the truncation walk on overflow; `pad()`
 * takes the width it needs as an argument, so a fitting line costs one
 * memo lookup and one str_repeat.
 *
 * Byte identity with the naive core pairing IS the acceptance contract
 * — tests/Support/CellPadTest.php keeps the two paths pinned against
 * each other over the styled/CJK/wide corpus, so this class can never
 * drift into a second truth of layout arithmetic.
 */
final class CellPad
{
    private function __construct()
    {
    }

    /**
     * The line, truncated to at most $cells display cells, plus its
     * EXACT cell width — the one measurement callers should ever make.
     *
     * @return array{0: string, 1: int}
     */
    public static function fit(string $line, int $cells): array
    {
        $width = Width::string($line);
        if ($width <= $cells) {
            return [$line, $width];
        }
        $truncated = Width::truncateAnsi($line, $cells);
        return [$truncated, Width::string($truncated)];
    }

    /**
     * Pad a line of KNOWN width to exactly $cells on $side without
     * re-measuring. Distribution matches core's padCenter to the space:
     * an odd gap sends the surplus to the right.
     */
    public static function pad(string $line, int $knownWidth, int $cells, string $side): string
    {
        $gap = $cells - $knownWidth;
        if ($gap <= 0) {
            return $line;
        }
        return match ($side) {
            'right'  => $line . \str_repeat(' ', $gap),
            'left'   => \str_repeat(' ', $gap) . $line,
            'center' => \str_repeat(' ', intdiv($gap, 2))
                . $line
                . \str_repeat(' ', $gap - intdiv($gap, 2)),
            default  => throw new \InvalidArgumentException(
                "CellPad::pad() side must be 'left', 'right' or 'center'; got '{$side}'."
            ),
        };
    }
}
