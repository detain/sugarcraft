<?php

/**
 * Russian translations for sugar-datepicker.
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
    'day.0' => 'Вс',
    'day.1' => 'Пн',
    'day.2' => 'Вт',
    'day.3' => 'Ср',
    'day.4' => 'Чт',
    'day.5' => 'Пт',
    'day.6' => 'Сб',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'Январь',
    'month.2'   => 'Февраль',
    'month.3'   => 'Март',
    'month.4'   => 'Апрель',
    'month.5'   => 'Май',
    'month.6'   => 'Июнь',
    'month.7'   => 'Июль',
    'month.8'   => 'Август',
    'month.9'   => 'Сентябрь',
    'month.10'  => 'Октябрь',
    'month.11'  => 'Ноябрь',
    'month.12'  => 'Декабрь',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
