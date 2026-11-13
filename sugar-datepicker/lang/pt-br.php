<?php

/**
 * Brazilian Portuguese translations for sugar-datepicker.
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
    'day.0' => 'Dom',
    'day.1' => 'Seg',
    'day.2' => 'Ter',
    'day.3' => 'Qua',
    'day.4' => 'Qui',
    'day.5' => 'Sex',
    'day.6' => 'Sáb',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'janeiro',
    'month.2'   => 'fevereiro',
    'month.3'   => 'março',
    'month.4'   => 'abril',
    'month.5'   => 'maio',
    'month.6'   => 'junho',
    'month.7'   => 'julho',
    'month.8'   => 'agosto',
    'month.9'   => 'setembro',
    'month.10'  => 'outubro',
    'month.11'  => 'novembro',
    'month.12'  => 'dezembro',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
