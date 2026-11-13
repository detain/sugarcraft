<?php

/**
 * Czech translations for sugar-datepicker.
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
    'day.0' => 'Ne',
    'day.1' => 'Po',
    'day.2' => 'Út',
    'day.3' => 'St',
    'day.4' => 'Čt',
    'day.5' => 'Pá',
    'day.6' => 'So',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'Leden',
    'month.2'   => 'Únor',
    'month.3'   => 'Březen',
    'month.4'   => 'Duben',
    'month.5'   => 'Květen',
    'month.6'   => 'Červen',
    'month.7'   => 'Červenec',
    'month.8'   => 'Srpen',
    'month.9'   => 'Září',
    'month.10'  => 'Říjen',
    'month.11'  => 'Listopad',
    'month.12'  => 'Prosinec',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
