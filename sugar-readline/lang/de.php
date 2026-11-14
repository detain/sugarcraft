<?php

/**
 * 'de' translations for sugar-readline.
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
  'confirm.no' => 'Nein',
  'confirm.yes' => 'Ja',
  'multiselect.hint' => '[Mehrfachauswahl] ausgewählt: {count}',
  'multiselect.hint_max' => '[Mehrfachauswahl] ausgewählt: {count} (max {max})',
  'multiselect.hint_min' => '[Mehrfachauswahl] ausgewählt: {count} (min {min})',
  'multiselect.hint_min_max' => '[Mehrfachauswahl] ausgewählt: {count} (min {min}) (max {max})',
  'selection.filter_prefix' => '[Filter] ',
  'selection.matches_one' => ' ({count} Übereinstimmung)',
  'selection.matches_other' => ' ({count} Übereinstimmungen)',
  'selection.no_matches' => '(keine Treffer)',
  'selection.page' => 'Seite {page}/{of}',
  'text.invalid_input' => 'Ungültige Eingabe',
  'text.search_line_forward' => '{label}(i-Suche)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(fehlgeschlagen i-Suche)`{query}\': ',
  'text.search_line_reverse' => '{label}(rückwärts-i-Suche)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(fehlgeschlagen rückwärts-i-Suche)`{query}\': ',
);
