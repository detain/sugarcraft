<?php

/**
 * 'it' translations for sugar-readline.
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
  'confirm.no' => 'No',
  'confirm.yes' => 'Sì',
  'multiselect.hint' => '[selezione multipla] selezionati: {count}',
  'multiselect.hint_max' => '[selezione multipla] selezionati: {count} (max {max})',
  'multiselect.hint_min' => '[selezione multipla] selezionati: {count} (min {min})',
  'multiselect.hint_min_max' => '[selezione multipla] selezionati: {count} (min {min}) (max {max})',
  'selection.filter_prefix' => '[filtro] ',
  'selection.matches_one' => ' ({count} corrispondenza)',
  'selection.matches_other' => ' ({count} corrispondenze)',
  'selection.no_matches' => '(nessuna corrispondenza)',
  'selection.page' => 'Pagina {page}/{of}',
  'text.invalid_input' => 'Input non valido',
  'text.search_line_forward' => '{label}(i-ricerca)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(fallita i-ricerca)`{query}\': ',
  'text.search_line_reverse' => '{label}(ricerca-i-invertita)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(fallita ricerca-i-invertita)`{query}\': ',
);
