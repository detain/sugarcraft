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
     *
     * @param int $month 1-12 (1=Jan … 12=Dec)
     * @param int $year  Gregorian year, >= 1
     *
     * @throws \InvalidArgumentException when $month is outside 1-12 or $year < 1.
     *         The range check MUST precede date construction: an unvalidated
     *         month silently rolls into an adjacent period rather than failing
     *         loudly — e.g. createFromFormat('!Y-m-d', '2026-13-01') yields
     *         2027-01-01 and "2026-00-01" yields 2025-12-01. Both produce a
     *         wrong-but-plausible date, so we reject out-of-range input up front.
     */
    public static function gridIndexToDate(int $gridIndex, int $month, int $year): ?\DateTimeImmutable
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException(
                \sprintf('Month must be in 1-12, got: %d', $month)
            );
        }
        if ($year < 1) {
            throw new \InvalidArgumentException(
                \sprintf('Year must be >= 1, got: %d', $year)
            );
        }

        // Leading "!" zeroes the time-of-day so returned dates are at 00:00:00,
        // matching DatePicker::firstOfViewMonth(). Month/year are range-checked
        // above, so createFromFormat cannot silently roll into a neighbour.
        $firstOfMonth = \DateTimeImmutable::createFromFormat(
            '!Y-m-d', \sprintf('%04d-%02d-01', $year, $month)
        );
        if ($firstOfMonth === false) {
            return null;
        }

        $daysInMonth = (int) $firstOfMonth->format('t');
        $firstDow    = (int) $firstOfMonth->format('w');
        $dayNum      = $gridIndex - $firstDow + 1;

        if ($dayNum < 1 || $dayNum > $daysInMonth) {
            return null;
        }
        return $firstOfMonth->modify('+' . ($dayNum - 1) . ' days');
    }
}
