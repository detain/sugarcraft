<?php

/**
 * Simplified Chinese translations for sugar-datepicker.
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
    'day.1' => '一',
    'day.2' => '二',
    'day.3' => '三',
    'day.4' => '四',
    'day.5' => '五',
    'day.6' => '六',

    // Month names (1=January, ..., 12=December)
    'month.1'   => '一月',
    'month.2'   => '二月',
    'month.3'   => '三月',
    'month.4'   => '四月',
    'month.5'   => '五月',
    'month.6'   => '六月',
    'month.7'   => '七月',
    'month.8'   => '八月',
    'month.9'   => '九月',
    'month.10'  => '十月',
    'month.11'  => '十一月',
    'month.12'  => '十二月',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{year}年{month}',
];
