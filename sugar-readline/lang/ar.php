<?php

/**
 * 'ar' translations for sugar-readline.
 *
 * Keys ride lang/en.php verbatim; the {placeholder} tokens are contract
 * (LOCALES.md named-token law — values may reorder them, never rename
 * them). '[y/n]' is key notation and stays untranslated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return array (
  'confirm.hint' => '[y/n]',
  'confirm.no' => 'لا',
  'confirm.yes' => 'نعم',
  'multiselect.hint' => '[تحديد متعدد] محدد: {count}',
  'multiselect.hint_max' => '[تحديد متعدد] محدد: {count} (أقصى {max})',
  'multiselect.hint_min' => '[تحديد متعدد] محدد: {count} (أدنى {min})',
  'multiselect.hint_min_max' => '[تحديد متعدد] محدد: {count} (أدنى {min}) (أقصى {max})',
  'selection.filter_prefix' => '[مرشح] ',
  'selection.matches_one' => ' ({count} مطابقة)',
  'selection.matches_other' => ' ({count} مطابقة)',
  'selection.no_matches' => '(لا مطابقات)',
  'selection.page' => 'صفحة {page}/{of}',
  'text.invalid_input' => 'إدخال غير صالح',
  'text.search_line_forward' => '{label}(i-بحث)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(فشل i-بحث)`{query}\': ',
  'text.search_line_reverse' => '{label}(بحث-i-عكسي)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(فشل بحث-i-عكسي)`{query}\': ',
);
