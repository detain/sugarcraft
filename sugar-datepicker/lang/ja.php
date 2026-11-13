<?php

/**
 * Japanese translations for sugar-datepicker.
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
    'day.0' => '日',
    'day.1' => '月',
    'day.2' => '火',
    'day.3' => '水',
    'day.4' => '木',
    'day.5' => '金',
    'day.6' => '土',

    // Month names (1=January, ..., 12=December)
    'month.1'   => '1月',
    'month.2'   => '2月',
    'month.3'   => '3月',
    'month.4'   => '4月',
    'month.5'   => '5月',
    'month.6'   => '6月',
    'month.7'   => '7月',
    'month.8'   => '8月',
    'month.9'   => '9月',
    'month.10'  => '10月',
    'month.11'  => '11月',
    'month.12'  => '12月',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{year}年{month}',
];
