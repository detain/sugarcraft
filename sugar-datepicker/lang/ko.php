<?php

/**
 * Korean translations for sugar-datepicker.
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
    'day.0' => '일',
    'day.1' => '월',
    'day.2' => '화',
    'day.3' => '수',
    'day.4' => '목',
    'day.5' => '금',
    'day.6' => '토',

    // Month names (1=January, ..., 12=December)
    'month.1'   => '1월',
    'month.2'   => '2월',
    'month.3'   => '3월',
    'month.4'   => '4월',
    'month.5'   => '5월',
    'month.6'   => '6월',
    'month.7'   => '7월',
    'month.8'   => '8월',
    'month.9'   => '9월',
    'month.10'  => '10월',
    'month.11'  => '11월',
    'month.12'  => '12월',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{year}년 {month}',
];
