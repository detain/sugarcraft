<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

/**
 * Generates a per-line prefix string for list rendering.
 *
 * `initPrefixer()` is called once PER ITEM of a rendering pass (not once per
 * pass) to (re)initialise state and report the width reserved for prefixes;
 * `prefix()` is then called once per line of that item to produce the actual
 * prefix string. Because every item's returned width feeds its own layout,
 * implementations must derive any padding from a pass-invariant value —
 * e.g. `$totalItems`, not `$currentIndex` — or columns render ragged
 * (audit M6 fix wave).
 */
interface Prefixer
{
    /**
     * Called once per item within a rendering pass to initialise state.
     *
     * @param \Stringable $value          The current item's value
     * @param int         $currentIndex   Index of the current item in the list
     * @param int         $cursorIndex    The currently selected item index
     * @param int         $lineOffset     How many lines to keep visible above/below cursor
     * @param int         $width          Viewport width in cells
     * @param int         $height         Viewport height in lines
     * @param int         $totalItems     Number of items in the (post-filter) list
     * @return int                         Width in cells consumed by the prefix
     */
    public function initPrefixer(
        \Stringable $value,
        int $currentIndex,
        int $cursorIndex,
        int $lineOffset,
        int $width,
        int $height,
        int $totalItems,
    ): int;

    /**
     * Return the prefix string for a given line.
     *
     * @param int $currentLine  0-based line index within the current item
     * @param int $totalLines   Total number of lines this item spans
     */
    public function prefix(int $currentLine, int $totalLines): string;
}
