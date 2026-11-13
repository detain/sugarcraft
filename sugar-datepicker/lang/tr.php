<?php

/**
 * Turkish translations for sugar-datepicker.
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
    'day.0' => 'Paz',
    'day.1' => 'Pzt',
    'day.2' => 'Sal',
    'day.3' => 'Çar',
    'day.4' => 'Per',
    'day.5' => 'Cum',
    'day.6' => 'Cmt',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'Ocak',
    'month.2'   => 'Şubat',
    'month.3'   => 'Mart',
    'month.4'   => 'Nisan',
    'month.5'   => 'Mayıs',
    'month.6'   => 'Haziran',
    'month.7'   => 'Temmuz',
    'month.8'   => 'Ağustos',
    'month.9'   => 'Eylül',
    'month.10'  => 'Ekim',
    'month.11'  => 'Kasım',
    'month.12'  => 'Aralık',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
