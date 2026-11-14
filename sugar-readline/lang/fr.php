<?php

/**
 * 'fr' translations for sugar-readline.
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
  'confirm.no' => 'Non',
  'confirm.yes' => 'Oui',
  'multiselect.hint' => '[multi-sélection] sélectionnées : {count}',
  'multiselect.hint_max' => '[multi-sélection] sélectionnées : {count} (max {max})',
  'multiselect.hint_min' => '[multi-sélection] sélectionnées : {count} (min {min})',
  'multiselect.hint_min_max' => '[multi-sélection] sélectionnées : {count} (min {min}) (max {max})',
  'selection.filter_prefix' => '[filtre] ',
  'selection.matches_one' => ' ({count} correspondance)',
  'selection.matches_other' => ' ({count} correspondances)',
  'selection.no_matches' => '(aucune correspondance)',
  'selection.page' => 'Page {page}/{of}',
  'text.invalid_input' => 'Saisie invalide',
  'text.search_line_forward' => '{label}(i-recherche)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(échec i-recherche)`{query}\': ',
  'text.search_line_reverse' => '{label}(recherche-i-inverse)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(échec recherche-i-inverse)`{query}\': ',
);
