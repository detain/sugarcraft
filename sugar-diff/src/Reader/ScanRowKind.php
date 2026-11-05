<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Reader;

/**
 * What kind of row a scanned line of a unified diff is — the discriminator
 * that lets a viewer tell real content from decoration instead of re-sniffing
 * the marker column, the fragile path a bare `(null, null)` tuple cannot
 * express: an `@@` row, out-of-hunk prose, and a `\ No newline` annotation
 * can all share that one shape.
 */
enum ScanRowKind
{
    /** A `' '` (or transitively stripped empty) row inside a hunk: exists in both files. */
    case Context;

    /** A `'+'` row inside a hunk: exists only in the new file. */
    case Insert;

    /** A `'-'` row inside a hunk: exists only in the old file. */
    case Delete;

    /** An `@@ -a,b +c,d @@` row opening (or re-arming) a hunk. */
    case HunkHeader;

    /** A row starting a new file: `diff `/`index `/out-of-hunk `--- `/`+++ `. */
    case FileHeader;

    /** A `\ No newline at end of file` annotation: occupies no line of either file. */
    case NoNewlineAnnotation;

    /** Anything the walk cannot attribute to a hunk: prose, footers, and rows past a hunk's declared end. */
    case OutsideHunk;

    /** Any row a pairing renderer may place in a run (vs decoration/corruption). */
    public function isContentRow(): bool
    {
        return $this === self::Context || $this === self::Insert || $this === self::Delete;
    }
}
