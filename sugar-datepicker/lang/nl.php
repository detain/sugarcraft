<?php

/**
 * Dutch translations for sugar-datepicker.
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
    'day.0' => 'Zo',
    'day.1' => 'Ma',
    'day.2' => 'Wo',
    'day.3' => 'Do',
    'day.4' => 'Vr',
    'day.5' => 'Za',
    'day.6' => 'Zo',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'januari',
    'month.2'   => 'februari',
    'month.3'   => 'maart',
    'month.4'   => 'april',
    'month.5'   => 'mei',
    'month.6'   => 'juni',
    'month.7'   => 'juli',
    'month.8'   => 'augustus',
    'month.9'   => 'september',
    'month.10'  => 'oktober',
    'month.11'  => 'november',
    'month.12'  => 'december',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
