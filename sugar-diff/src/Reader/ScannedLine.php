<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Reader;

/**
 * One scanned row of an existing unified diff: the old-file and new-file line
 * numbers it lands on — either null when that side has no line there (`+`
 * rows have no old line, `-` rows no new line, headers neither) — plus whether
 * the row was read as a file header.
 *
 * The view half (fixed-width gutter prefixes, colour) deliberately stays
 * downstream; this is only the region model.
 *
 * Mirrors the `[$old, $new, $isFileHeader]` tuples computed by
 * sugar-crush/src/Tui/DiffGutter.php `number()`.
 */
final class ScannedLine
{
    public function __construct(
        public readonly ?int $old,
        public readonly ?int $new,
        public readonly bool $fileHeader,
    ) {}
}
