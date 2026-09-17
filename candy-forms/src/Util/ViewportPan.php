<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Util;

/**
 * Shared window-pan math for cursor/offset pairs (E736 plan 3.7).
 *
 * Extracted verbatim from the byte-identical tails of
 * {@see \SugarCraft\Forms\ItemList\ItemList::moveCursor()} and
 * {@see \SugarCraft\Forms\FilePicker\FilePicker::moveCursor()}: pull the
 * offset back when the cursor leaves the top of the window, push it
 * forward when the cursor falls off the bottom, and never let it go
 * negative.
 *
 * Only the pan TAIL was a literal twin. The cursor-resolution HEADS stay
 * per-class and are deliberately NOT merged: ItemList counts
 * `visibleItems()` and may WRAP the index when infinite scrolling is on,
 * while FilePicker counts `entries` and always clamps. Unifying those
 * behind a flag parameter would merge divergent variants, not twins.
 */
final class ViewportPan
{
    private function __construct()
    {
    }

    /**
     * Offset of the first visible row after `cursor` must be on screen.
     *
     * @param int $cursor resolved (already clamped/wrapped) cursor index
     * @param int $offset current first-visible-row index
     * @param int $height window height in rows; <= 0 means unbounded
     */
    public static function offsetFor(int $cursor, int $offset, int $height): int
    {
        if ($cursor < $offset) {
            $offset = $cursor;
        }
        if ($height > 0 && $cursor >= $offset + $height) {
            $offset = $cursor - $height + 1;
        }
        return max(0, $offset);
    }
}
