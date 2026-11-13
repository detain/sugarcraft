<?php

/**
 * Arabic translations for sugar-datepicker.
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
    'day.0' => 'أحد',
    'day.1' => 'اثن',
    'day.2' => 'ثلا',
    'day.3' => 'أرب',
    'day.4' => 'خمي',
    'day.5' => 'جمع',
    'day.6' => 'سبت',

    // Month names (1=January, ..., 12=December)
    'month.1'   => 'يناير',
    'month.2'   => 'فبراير',
    'month.3'   => 'مارس',
    'month.4'   => 'أبريل',
    'month.5'   => 'مايو',
    'month.6'   => 'يونيو',
    'month.7'   => 'يوليو',
    'month.8'   => 'أغسطس',
    'month.9'   => 'سبتمبر',
    'month.10'  => 'أكتوبر',
    'month.11'  => 'نوفمبر',
    'month.12'  => 'ديسمبر',

    // Month-grid header template (dp-15): {month} and {year} are PROTECTED
    // placeholder tokens — a locale may reorder them but never drop or rename them.
    'header' => '{month} {year}',
];
