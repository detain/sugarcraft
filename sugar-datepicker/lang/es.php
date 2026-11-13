<?php

/**
 * Spanish translations for sugar-datepicker.
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
    'day.0' => 'Do',
    'day.1' => 'Lu',
    'day.2' => 'Ma',
    'day.3' => 'Mi',
    'day.4' => 'Ju',
    'day.5' => 'Vi',
    'day.6' => 'Sá',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'enero',
    'month.2'   => 'febrero',
    'month.3'   => 'marzo',
    'month.4'   => 'abril',
    'month.5'   => 'mayo',
    'month.6'   => 'junio',
    'month.7'   => 'julio',
    'month.8'   => 'agosto',
    'month.9'   => 'septiembre',
    'month.10'  => 'octubre',
    'month.11'  => 'noviembre',
    'month.12'  => 'diciembre',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
