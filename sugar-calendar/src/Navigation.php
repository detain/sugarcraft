<?php

declare(strict_types=1);

namespace SugarCraft\Calendar;

/**
 * Keyboard navigation handler for date grids.
 */
final readonly class Navigation
{
    public const ROW_DOWN  = 7;
    public const ROW_UP   = -7;
    public const COL_RIGHT = 1;
    public const COL_LEFT  = -1;

    /** @param int $gridIndex 0-41 */
    public static function move(int $gridIndex, string $key): int
    {
        return match ($key) {
            'left'  => max(0, $gridIndex - 1),
            'right' => min(41, $gridIndex + 1),
            'up'    => max(0, $gridIndex - 7),
            'down'  => min(41, $gridIndex + 7),
            'home'  => 0,
            'end'   => 41,
            default => $gridIndex,
        };
    }

    /**
     * Convert grid index to \DateTimeImmutable for given month/year.
     *
     * Returns null when the index falls outside the valid day range of the
     * month (same semantics as DatePicker::dateAtCursor()).
     */
    public static function gridIndexToDate(int $gridIndex, int $month, int $year): ?\DateTimeImmutable
    {
        $firstOfMonth = new \DateTimeImmutable("$year-$month-01");
        $daysInMonth = (int) $firstOfMonth->format('t');
        $firstDow = (int) $firstOfMonth->format('w');
        $dayNum = $gridIndex - $firstDow + 1;

        if ($dayNum < 1 || $dayNum > $daysInMonth) {
            return null;
        }
        return $firstOfMonth->modify('+' . ($dayNum - 1) . ' days');
    }
}
