<?php

/**
 * Polish translations for sugar-datepicker.
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
    'day.0' => 'Nd',
    'day.1' => 'Pn',
    'day.2' => 'Wt',
    'day.3' => 'Śr',
    'day.4' => 'Cz',
    'day.5' => 'Pt',
    'day.6' => 'So',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'styczeń',
    'month.2'   => 'luty',
    'month.3'   => 'marzec',
    'month.4'   => 'kwiecień',
    'month.5'   => 'maj',
    'month.6'   => 'czerwiec',
    'month.7'   => 'lipiec',
    'month.8'   => 'sierpień',
    'month.9'   => 'wrzesień',
    'month.10'  => 'październik',
    'month.11'  => 'listopad',
    'month.12'  => 'grudzień',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
