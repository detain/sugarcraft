<?php

declare(strict_types=1);

namespace SugarCraft\Diff;

/**
 * One `@@ -oldStart,oldLen +newStart,newLen @@` hunk: the display anchor pair
 * plus the context/change rows it covers.
 *
 * The starts follow the ported `diff -u` convention of
 * sugar-crush/src/Tools/Concerns/BuildsUnifiedDiff.php: a side whose hunk
 * length is 0 anchors at line 0, because an empty range has no real line to
 * point at. (GNU itself anchors a zero-length *mid-file* range at the previous
 * line instead; the ported engine only reaches oldLen/newLen 0 at a file
 * boundary or with a zero context window, where the two shapes agree often
 * enough that the faithful simpler rule is kept.)
 *
 * Mirrors the group-window walk of `BuildsUnifiedDiff::buildHunks()`.
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

    /** The `@@ -a,b +c,d @@` header line, without its trailing newline. */
    public function header(): string
    {
        return "@@ -{$this->oldStart},{$this->oldLines} +{$this->newStart},{$this->newLines} @@";
    }

    /** @return list<Line> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** How many old-side rows this hunk carries (context + removals). */
    public function removedCount(): int
    {
        return array_reduce(
            $this->lines,
            static fn (int $carry, Line $line): int => $carry + ($line->kind === LineKind::Delete ? 1 : 0),
            0,
        );
    }

    /** How many new-side rows this hunk carries (context + additions). */
    public function addedCount(): int
    {
        return array_reduce(
            $this->lines,
            static fn (int $carry, Line $line): int => $carry + ($line->kind === LineKind::Insert ? 1 : 0),
            0,
        );
    }
}
