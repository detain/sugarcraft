<?php

/**
 * 'pl' translations for sugar-readline.
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
  'confirm.no' => 'Nie',
  'confirm.yes' => 'Tak',
  'multiselect.hint' => '[wybór wielokrotny] zaznaczono: {count}',
  'multiselect.hint_max' => '[wybór wielokrotny] zaznaczono: {count} (max {max})',
  'multiselect.hint_min' => '[wybór wielokrotny] zaznaczono: {count} (min {min})',
  'multiselect.hint_min_max' => '[wybór wielokrotny] zaznaczono: {count} (min {min}) (max {max})',
  'selection.filter_prefix' => '[filtr] ',
  'selection.matches_one' => ' ({count} dopasowanie)',
  'selection.matches_other' => ' ({count} dopasowań)',
  'selection.no_matches' => '(brak dopasowań)',
  'selection.page' => 'Strona {page}/{of}',
  'text.invalid_input' => 'Nieprawidłowe dane wejściowe',
  'text.search_line_forward' => '{label}(i-wyszukiwanie)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(nieudane i-wyszukiwanie)`{query}\': ',
  'text.search_line_reverse' => '{label}(wyszukiwanie-i-wsteczne)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(nieudane wyszukiwanie-i-wsteczne)`{query}\': ',
);
