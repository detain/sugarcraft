<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Reader;

/**
 * One scanned row of an existing unified diff: the old-file and new-file line
 * numbers it lands on — either null when that side has no line there (`+`
 * rows have no old line, `-` rows no new line, headers neither) — whether
 * the row was read as a file header, and what KIND of row the walk decided
 * it was.
 *
 * The view half (fixed-width gutter prefixes, colour) deliberately stays
 * downstream; this is only the region model.
 *
 * The `$kind` default exists for hand-built fixtures; the scanner always
 * passes the derived kind, so a row scanned from real text never reports
 * the default it does not mean.
 *
 * The `[$old, $new, $isFileHeader]` shape mirrors what candy-code's
 * `Tui\DiffGutter.php` used to compute with its private `number()` — the
 * D1a fold deleted that copy, so the gutter consumes {@see UnifiedScan::lines()}
 * directly now; the row kind carries what the tuples never could (the
 * gutter adds no kind of its own, it reads these).
 */
final class ScannedLine
{
    public function __construct(
        public readonly ?int $old,
        public readonly ?int $new,
        public readonly bool $fileHeader,
        public readonly ScanRowKind $kind = ScanRowKind::OutsideHunk,
    ) {}
}
