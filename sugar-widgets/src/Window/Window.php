<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Window;

/**
 * Pure windowing math for scrolled lists: which rows to paint so the
 * selected row stays visible. It holds no state; menus, pickers and lists
 * call {@see around()} on every render instead of keeping their own scroll
 * offset in step with the cursor.
 *
 * Original SugarCraft work: charmbracelet/bubbles has no windowing helper,
 * so there is no upstream Mirrors citation. The centering follows btop's
 * process-list follow mode (see candy-top `ProcPanel`), which puts the
 * selection at `intdiv(size - 1, 2)` within the window.
 */
final class Window
{
    private function __construct()
    {
    }

    /**
     * The window of `$size` rows out of `$count` that shows `$selected`,
     * centered on it as far as the list bounds allow: near either end the
     * window is pushed against that end and the selection moves off center.
     * For an even size the selection sits just above the middle (one more
     * row below it than above).
     *
     * Every input is clamped, never rejected: a negative count or size is
     * 0, a size larger than the count is the count, and a selection outside
     * `[0, count - 1]` is moved to the nearest end. With nothing to show
     * (count or size 0) the result is the empty slice `[0, 0)`.
     */
    public static function around(int $count, int $selected, int $size): WindowSlice
    {
        $count = max(0, $count);
        $size = min(max(0, $size), $count);
        if ($size === 0) {
            return new WindowSlice(0, 0);
        }

        $selected = min(max(0, $selected), $count - 1);
        $start = $selected - intdiv($size - 1, 2);
        $start = min(max(0, $start), $count - $size);

        return new WindowSlice($start, $start + $size);
    }
}
