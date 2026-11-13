<?php

/**
 * German translations for sugar-datepicker.
 *
 * Contract: the day.0–day.6 keys are POSITIONAL, SUNDAY-FIRST (index equals
 * PHP date('w')), so index 0 is Sunday; the grid always begins on Sunday and
 * never remaps columns for local week conventions. day.* fit 3 display columns,
 * month.* fit 16; the header keeps both {month} and {year} tokens intact.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Day of week abbreviations (0=Sun, 1=Mon, ..., 6=Sat)
    'day.0' => 'So',
    'day.1' => 'Mo',
    'day.2' => 'Di',
    'day.3' => 'Mi',
    'day.4' => 'Do',
    'day.5' => 'Fr',
    'day.6' => 'Sa',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'Januar',
    'month.2'   => 'Februar',
    'month.3'   => 'März',
    'month.4'   => 'April',
    'month.5'   => 'Mai',
    'month.6'   => 'Juni',
    'month.7'   => 'Juli',
    'month.8'   => 'August',
    'month.9'   => 'September',
    'month.10'  => 'Oktober',
    'month.11'  => 'November',
    'month.12'  => 'Dezember',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
