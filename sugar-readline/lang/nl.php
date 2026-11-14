<?php

/**
 * 'nl' translations for sugar-readline.
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
  'confirm.no' => 'Nee',
  'confirm.yes' => 'Ja',
  'multiselect.hint' => '[multi-select] geselecteerd: {count}',
  'multiselect.hint_max' => '[multi-select] geselecteerd: {count} (max {max})',
  'multiselect.hint_min' => '[multi-select] geselecteerd: {count} (min {min})',
  'multiselect.hint_min_max' => '[multi-select] geselecteerd: {count} (min {min}) (max {max})',
  'selection.filter_prefix' => '[filter] ',
  'selection.matches_one' => ' ({count} overeenkomst)',
  'selection.matches_other' => ' ({count} overeenkomsten)',
  'selection.no_matches' => '(geen overeenkomsten)',
  'selection.page' => 'Pagina {page}/{of}',
  'text.invalid_input' => 'Ongeldige invoer',
  'text.search_line_forward' => '{label}(i-zoeken)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(mislukt i-zoeken)`{query}\': ',
  'text.search_line_reverse' => '{label}(achterwaarts-i-zoeken)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(mislukt achterwaarts-i-zoeken)`{query}\': ',
);
