<?php

declare(strict_types=1);

namespace SugarCraft\Diff;

/**
 * One `@@ -oldStart,oldLen +newStart,newLen @@` hunk: the display anchor pair
 * plus the context/change rows it covers.
 *
 * Header printing follows GNU `diff` exactly (oracle-verified against
 * /usr/bin/diff -U0 and pinned by tests/GnuHunkHeaderParityTest.php):
 *  - a single-line range drops its count: `-2`, not `-2,1` (`range()` below);
 *  - a zero-length side is anchored at the line the hunk sits AFTER (0 before
 *    the first line) by the producer in {@see Diff::assemble()} and keeps
 *    its `,0` — GNU never drops a zero count.
 *  - git's optional function heading after the closing `@@` has no analogue
 *    here: this engine diffs content and has no enclosing-function notion, so
 *    {@see header()} ends at `@@` (a reader must likewise ignore everything
 *    after the closing `@@`, which {@see \SugarCraft\Diff\Reader\UnifiedScan}
 *    does).
 *
 * This replaced the ported candy-code shape (zero sides anchored at 0, counts
 * always printed) from `BuildsUnifiedDiff::buildHunks()`; the group-window walk
 * itself still mirrors it.
 */
final class Hunk
{
    /** @param list<Line> $lines */
    public function __construct(
        public readonly int $oldStart,
        public readonly int $oldLines,
        public readonly int $newStart,
        public readonly int $newLines,
        public readonly array $lines,
    ) {}

    /** The `@@ -a[,b] +c[,d] @@` header line, without its trailing newline. */
    public function header(): string
    {
        return '@@ -' . self::range($this->oldStart, $this->oldLines)
            . ' +' . self::range($this->newStart, $this->newLines) . ' @@';
    }

    /**
     * GNU range syntax: a count of exactly 1 is elided (`-N` covers one line),
     * every other count — including 0 — prints as `-N,C`.
     */
    private static function range(int $start, int $count): string
    {
        return $count === 1 ? (string) $start : "$start,$count";
    }

    /** @return list<Line> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** How many `-` rows this hunk carries (deletions only — context excluded). */
    public function removedCount(): int
    {
        return array_reduce(
            $this->lines,
            static fn (int $carry, Line $line): int => $carry + ($line->kind === LineKind::Delete ? 1 : 0),
            0,
        );
    }

    /** How many `+` rows this hunk carries (additions only — context excluded). */
    public function addedCount(): int
    {
        return array_reduce(
            $this->lines,
            static fn (int $carry, Line $line): int => $carry + ($line->kind === LineKind::Insert ? 1 : 0),
            0,
        );
    }
}
