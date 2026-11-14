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
  'history.append_lock_failed' => 'kan de append-lock op {path} niet verwerven',
  'history.changed_under_us' => 'geschiedenispad is onder ons veranderd (mogelijke symlink-wissel): {path}',
  'history.chmod_failed' => 'kan 0600 niet instellen op geschiedenisbestand {path}',
  'history.clear_temp_failed' => 'kan tijdelijk bestand voor wissen geschiedenis niet aanmaken in {dir}',
  'history.create_failed' => 'kan geschiedenisbestand {path} niet aanmaken',
  'history.lock_acquire_failed' => 'kan schrijfloek van geschiedenis {path} niet verwerven',
  'history.lock_changed' => 'geschiedenisluk is onder ons veranderd (mogelijke symlink-wissel): {path}',
  'history.lock_open_failed' => 'kan schrijfloek van geschiedenis {path} niet openen',
  'history.not_regular' => 'geschiedenispad is geen regulier bestand (geopend via een wissel?): {path}',
  'history.open_failed' => 'kan geschiedenisbestand {path} niet openen',
  'history.replace_failed' => 'kan geschiedenisbestand {path} niet vervangen',
  'history.symlink_refused' => 'symlink geweigerd voor {what}: {path}',
  'history.temp_create_failed' => 'kan tijdelijk bestand voor herschrijven geschiedenis niet aanmaken in {dir}',
  'history.temp_lock_failed' => 'kan tijdelijk bestand voor herschrijven geschiedenis niet vergrendelen: {path}',
  'history.temp_open_failed' => 'kan tijdelijk bestand voor herschrijven geschiedenis niet openen: {path}',
  'multiselect.hint' => '[multi-select] geselecteerd: {count}',
  'multiselect.hint_max' => '[multi-select] geselecteerd: {count} (max {max})',
  'multiselect.hint_min' => '[multi-select] geselecteerd: {count} (min {min})',
  'multiselect.hint_min_max' => '[multi-select] geselecteerd: {count} (min {min}) (max {max})',
  'multiselect.notice_unmarked' => 'markering van {item} verwijderd om de limiet van {max} te halen',
  'multiselect.nothing_to_submit' => 'geen enkele optie komt overeen met het filter',
  'selection.filter_prefix' => '[filter] ',
  'selection.matches_one' => ' ({count} overeenkomst)',
  'selection.matches_other' => ' ({count} overeenkomsten)',
  'selection.no_matches' => '(geen overeenkomsten)',
  'selection.page' => 'Pagina {page}/{of}',
  'textarea.notice_max_lines' => 'kan geen regel toevoegen: maximum {max} bereikt',
  'text.invalid_input' => 'Ongeldige invoer',
  'text.notice_no_history' => 'de geschiedenis is leeg',
  'text.search_line_forward' => '{label}(i-zoeken)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(mislukt i-zoeken)`{query}\': ',
  'text.search_line_reverse' => '{label}(achterwaarts-i-zoeken)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(mislukt achterwaarts-i-zoeken)`{query}\': ',
);
