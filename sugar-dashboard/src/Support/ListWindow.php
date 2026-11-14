<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Support;

/**
 * Scroll-window arithmetic for vertically listed items.
 *
 * One definition of "which slice of a list is visible" shared by every
 * component that receives an allocation smaller than its item count
 * (finding sugar-dashboard-051: Select and ComboBox stored the height and
 * then painted all options, overflowing the box the layout gave them).
 * The canonical window keeps the active row visible and centres it when
 * possible, falling back to top/bottom clamps at the edges.
 */
final class ListWindow
{
    /**
     * First visible index for the given active row, visible height, and
     * total item count.
     *
     * The formula is ListComponent's long-pinned behaviour, lifted intact
     * so the calendar list, Select, and ComboBox agree on one rule.
     */
    public static function scrollTop(int $selected, int $height, int $total): int
    {
        if ($height >= $total) {
            return 0;
        }

        // Keep selected item centered when possible
        $idealTop = (int) floor($selected - ($height / 2));

        // Clamp to valid range
        return max(0, min($idealTop, $total - $height));
    }
}
