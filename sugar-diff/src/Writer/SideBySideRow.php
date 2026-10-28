<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Writer;

use SugarCraft\Diff\Line;

/**
 * One visual row of a side-by-side (split) diff: the old-side line and the
 * new-side line standing at the same screen row — either null when that half
 * has no line there (a paired replacement carries both, a leftover removal
 * only `left`, a lone addition only `right`).
 *
 * Each side is a plain {@see Line}, so it already carries the verbatim text,
 * the line kind, and its side's line number — a renderer paints `left` in the
 * old column (numbering by `oldNumber`) and `right` in the new column
 * (numbering by `newNumber`) and needs no other model. Context rows pair the
 * identical row with itself: the same immutable Line instance on both sides.
 *
 * Pure data by construction: no widths, no truncation, no colour. The row
 * pairing rule produced here is the canonical one the candy-code TUI split
 * renderer and the candy-code-web DiffView split mode both consume, so the
 * two surfaces show the same pairing for the same patch.
 */
final class SideBySideRow
{
    public function __construct(
        public readonly ?Line $left,
        public readonly ?Line $right,
    ) {}

    /** Whether both halves carry a line (a context row or a paired edit). */
    public function isPaired(): bool
    {
        return $this->left !== null && $this->right !== null;
    }
}
