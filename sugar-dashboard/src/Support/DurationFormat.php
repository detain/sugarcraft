<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Support;

/**
 * The one clock-face renderer for non-negative durations.
 *
 * Timer and Stopwatch both decompose seconds into hours/minutes/seconds
 * and paint either `H:MM:SS` (when there is at least one hour) or
 * `MM:SS` below an hour; Stopwatch may append a `.HH` hundredths tail.
 * That decomposition used to be hand-rolled in each render() - finding
 * sugar-dashboard-180: two copies of the same arithmetic drifting
 * behind two same-named setters carrying different units. Clock is NOT
 * a consumer: its face is wall time (hour of day, AM/PM, date leg),
 * not a duration, and shares no arithmetic with this file.
 */
final class DurationFormat
{
    /**
     * Paint $totalSeconds (>= 0) as `H:MM:SS`, or `MM:SS` below an hour.
     *
     * @param int|null $hundredths when given (0-99), appended as `.HH`
     */
    public static function hms(int $totalSeconds, ?int $hundredths = null): string
    {
        $hours = (int) floor($totalSeconds / 3600);
        $minutes = (int) floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            $face = sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        } else {
            $face = sprintf('%02d:%02d', $minutes, $seconds);
        }

        if ($hundredths !== null) {
            $face .= sprintf('.%02d', $hundredths);
        }

        return $face;
    }
}
