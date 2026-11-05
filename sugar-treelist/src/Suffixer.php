<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

/**
 * Generates a per-line suffix string for list rendering.
 *
 * `initSuffixer()` is called once PER ITEM of a rendering pass (not once per
 * pass) to (re)initialise state and report the width reserved for suffixes;
 * `suffix()` is then called once per line of that item to produce the actual
 * suffix string. The reserved width is subtracted from the content budget on
 * every line. On lines where `suffix()` returns a non-empty string the
 * content is right-padded with spaces toward the suffix, so the marker lands
 * right-aligned at the reserved column; an empty return adds no padding.
 * A return wider than the reservation is not guarded - that line overruns
 * past the reserved column.
 */
interface Suffixer
{
    /**
     * Called once per item within a rendering pass to initialise state.
     *
     * @param \Stringable $value          The current item's value
     * @param int         $currentIndex   Index of the current item in the list
     * @param int         $cursorIndex    The currently selected item index
     * @param int         $lineOffset     Pass-through context value for relative markers; viewport tracking lives in the Model (cursorOffset/width/height), not here - the default hooks ignore it
     * @param int         $width          Viewport width in cells
     * @param int         $height         Viewport height in lines
     * @return int                         Width in cells consumed by the suffix
     */
    public function initSuffixer(
        \Stringable $value,
        int $currentIndex,
        int $cursorIndex,
        int $lineOffset,
        int $width,
        int $height,
    ): int;

    /**
     * Return the suffix string for a given line.
     *
     * @param int $currentLine  0-based line index within the current item
     * @param int $totalLines   Total number of lines this item spans
     */
    public function suffix(int $currentLine, int $totalLines): string;
}
