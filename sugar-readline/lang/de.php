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
  'history.append_lock_failed' => 'Anhängesperre für {path} kann nicht erworben werden',
  'history.changed_under_us' => 'History-Pfad hat sich unter uns geändert (möglicher Symlink-Tausch): {path}',
  'history.chmod_failed' => '0600 kann für die History-Datei {path} nicht gesetzt werden',
  'history.clear_temp_failed' => 'Temporäre Datei zum Leeren der History kann in {dir} nicht erstellt werden',
  'history.create_failed' => 'History-Datei {path} kann nicht erstellt werden',
  'history.lock_acquire_failed' => 'Schreibsperre der History für {path} kann nicht erworben werden',
  'history.lock_changed' => 'History-Sperre hat sich unter uns geändert (möglicher Symlink-Tausch): {path}',
  'history.lock_open_failed' => 'History-Schreibsperre {path} kann nicht geöffnet werden',
  'history.not_regular' => 'History-Pfad ist keine reguläre Datei (über einen Tausch geöffnet?): {path}',
  'history.open_failed' => 'History-Datei {path} kann nicht geöffnet werden',
  'history.replace_failed' => 'History-Datei {path} kann nicht ersetzt werden',
  'history.symlink_refused' => 'Symbolischer Link für {what} verweigert: {path}',
  'history.temp_create_failed' => 'Temporäre Datei für History-Umschreiben kann in {dir} nicht erstellt werden',
  'history.temp_lock_failed' => 'Temporäre Datei für History-Umschreiben kann nicht gesperrt werden: {path}',
  'history.temp_open_failed' => 'Temporäre Datei für History-Umschreiben kann nicht geöffnet werden: {path}',
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
