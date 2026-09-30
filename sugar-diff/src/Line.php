<?php

declare(strict_types=1);

namespace SugarCraft\Diff;

/**
 * One row of a computed diff: its kind, the verbatim line text, and the
 * old-file / new-file line numbers it lands on — either of which is null when
 * that side has no line there (a `del` row has no new line, an `ins` row no
 * old line).
 *
 * Rows are what {@see Diff::lines()} hands back for line-mode walking; hunks
 * are the same rows grouped with context by {@see Hunk}.
 *
 * Mirrors the `list<array{0:'eq'|'del'|'ins',1:string}>` ops plus the
 * per-op `[$type, $line, $oldLine, $newLine]` annotation of
 * sugar-crush/src/Tools/Concerns/BuildsUnifiedDiff.php, turned into a value
 * object.
 */
final class Line
{
    public function __construct(
        public readonly LineKind $kind,
        public readonly string $text,
        public readonly ?int $oldNumber,
        public readonly ?int $newNumber,
    ) {}

    /** The unified-format column-0 marker for this row. */
    public function marker(): string
    {
        return $this->kind->marker();
    }

    /** The row exactly as `diff -u` writes it: marker, text, newline. */
    public function toUnifiedRow(): string
    {
        return $this->marker() . $this->text . "\n";
    }
}
