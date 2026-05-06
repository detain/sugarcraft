<?php

declare(strict_types=1);

namespace CandyCore\Lister;

/**
 * Generates a per-line suffix string for list rendering.
 *
 * The Suffixer reserves width via `initSuffixer()` then produces the actual
 * suffix per line via `suffix()`. If the suffix is shorter than the reserved
 * width the remaining cells are padded with spaces for horizontal alignment.
 */
interface Suffixer
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
