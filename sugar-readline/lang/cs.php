<?php

/**
 * 'cs' translations for sugar-readline.
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
  'confirm.no' => 'Ne',
  'confirm.yes' => 'Ano',
  'multiselect.hint' => '[více položek] vybráno: {count}',
  'multiselect.hint_max' => '[více položek] vybráno: {count} (max {max})',
  'multiselect.hint_min' => '[více položek] vybráno: {count} (min {min})',
  'multiselect.hint_min_max' => '[více položek] vybráno: {count} (min {min}) (max {max})',
  'selection.filter_prefix' => '[filtr] ',
  'selection.matches_one' => ' ({count} shoda)',
  'selection.matches_other' => ' ({count} shod)',
  'selection.no_matches' => '(žádné shody)',
  'selection.page' => 'Strana {page}/{of}',
  'text.invalid_input' => 'Neplatný vstup',
  'text.search_line_forward' => '{label}(i-hledání)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(neúspěšné i-hledání)`{query}\': ',
  'text.search_line_reverse' => '{label}(zpětné-i-hledání)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(neúspěšné zpětné-i-hledání)`{query}\': ',
);
