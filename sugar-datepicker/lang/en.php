<?php

/**
 * English (default) translations for sugar-datepicker.
 *
 * Contract (MT12): the day.0–day.6 keys are POSITIONAL, SUNDAY-FIRST weekday
 * abbreviations — the index equals PHP's date('w') weekday number, so index 0 is
 * Sunday and index 6 is Saturday. Every day cell renders at a FIXED 3-column
 * width (2 digit cells + 1 separator), so a translation must fit within that
 * budget. firstDayOfWeek is NOT configurable: the grid always begins on Sunday,
 * and the key domain bakes in that Sunday-first + index-weekday identity. Resolve
 * a week-start knob BEFORE shipping any locale whose convention is Monday-first,
 * or the day names will be attached to the wrong columns.
 *
 * Width budgets (dp-14, enforced by the render AND by LangCoverageTest):
 *  - day.*   ≤ 3 display columns (one 3-char cell; longer names are clipped
 *            per slot by SugarCraft\Core\Util\Width, so overlong values only
 *            ever degrade their own column).
 *  - month.* ≤ 16 display columns (the natural 21-wide floor minus the
 *            ' 2026'-style year slot; longer headers clip at the grid edge).
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Day of week abbreviations (0=Sun, 1=Mon, ..., 6=Sat)
    'day.0' => 'Su',
    'day.1' => 'Mo',
    'day.2' => 'Tu',
    'day.3' => 'We',
    'day.4' => 'Th',
    'day.5' => 'Fr',
    'day.6' => 'Sa',

    // Month names (1=January, ..., 12=December)
    'month.1'  => 'January',
    'month.2'  => 'February',
    'month.3'  => 'March',
    'month.4'  => 'April',
    'month.5'  => 'May',
    'month.6'  => 'June',
    'month.7'  => 'July',
    'month.8'  => 'August',
    'month.9'  => 'September',
    'month.10' => 'October',
    'month.11' => 'November',
    'month.12' => 'December',
];
