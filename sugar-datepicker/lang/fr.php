<?php

/**
 * French translations for sugar-datepicker.
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
    'day.0' => 'Lu',
    'day.1' => 'Ma',
    'day.2' => 'Me',
    'day.3' => 'Je',
    'day.4' => 'Ve',
    'day.5' => 'Sa',
    'day.6' => 'Di',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'janvier',
    'month.2'   => 'février',
    'month.3'   => 'mars',
    'month.4'   => 'avril',
    'month.5'   => 'mai',
    'month.6'   => 'juin',
    'month.7'   => 'juillet',
    'month.8'   => 'août',
    'month.9'   => 'septembre',
    'month.10'  => 'octobre',
    'month.11'  => 'novembre',
    'month.12'  => 'décembre',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
