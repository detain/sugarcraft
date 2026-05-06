<?php

declare(strict_types=1);

namespace CandyCore\Lister;

/**
 * Generates a per-line prefix string for list rendering.
 *
 * The Prefixer is called once at the start of rendering (`initPrefixer`)
 * to compute the total width reserved for prefixes. Then `prefix()` is
 * called once per visible line to produce the actual prefix string.
 */
interface Prefixer
{
    /**
     * Called once per rendering pass to initialise state.
     *
     * @param \Stringable $value          The current item's value
     * @param int         $currentIndex   Index of the current item in the list
     * @param int         $cursorIndex    The currently selected item index
     * @param int         $lineOffset     How many lines to keep visible above/below cursor
     * @param int         $width          Viewport width in cells
     * @param int         $height         Viewport height in lines
     * @return int                         Width in cells consumed by the prefix
     */
    public function initPrefixer(
        \Stringable $value,
        int $currentIndex,
        int $cursorIndex,
        int $lineOffset,
        int $width,
        int $height,
    ): int;

    /**
     * Return the prefix string for a given line.
     *
     * @param int $currentLine  0-based line index within the current item
     * @param int $totalLines   Total number of lines this item spans
     */
    public function prefix(int $currentLine, int $totalLines): string;
}
