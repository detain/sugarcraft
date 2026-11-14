<?php

/**
 * 'ar' translations for sugar-readline.
 *
 * Keys ride lang/en.php verbatim; %d placeholders are contract.
 * '[y/n]' is key notation and stays untranslated; the English match-suffix
 * %s leg is dropped here (extra sprintf arguments are ignored) until S-10
 * replaces the morphology with full-string plural keys.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.yes' => 'نعم',
    'confirm.no' => 'لا',
    'confirm.hint' => '[y/n]',
    'selection.filter_prefix' => '[مرشح] ',
    'selection.match_count' => ' (%d مطابقة)',
    'selection.no_matches' => '(لا مطابقات)',
    'selection.page' => 'صفحة %d/%d',
    'multiselect.selected' => '[تحديد متعدد] محدد: %d',
    'multiselect.min' => ' (أدنى %d)',
    'multiselect.max' => ' (أقصى %d)',
    'text.invalid_input' => 'إدخال غير صالح',
    'text.search_reverse' => 'بحث-i-عكسي',
    'text.search_forward' => 'i-بحث',
    'text.search_failed' => 'فشل ',
];
