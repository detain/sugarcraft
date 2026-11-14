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
  'history.append_lock_failed' => 'nie można uzyskać blokady dopisywania w {path}',
  'history.changed_under_us' => 'ścieżka historii zmieniła się pod naszymi stopami (możliwa zamiana dowiązania): {path}',
  'history.chmod_failed' => 'nie można ustawić 0600 na pliku historii {path}',
  'history.clear_temp_failed' => 'nie można utworzyć pliku tymczasowego do czyszczenia historii w {dir}',
  'history.create_failed' => 'nie można utworzyć pliku historii {path}',
  'history.lock_acquire_failed' => 'nie można uzyskać blokady zapisu historii {path}',
  'history.lock_changed' => 'blokada historii zmieniła się pod naszymi stopami (możliwa zamiana dowiązania): {path}',
  'history.lock_open_failed' => 'nie można otworzyć blokady zapisu historii {path}',
  'history.not_regular' => 'ścieżka historii nie jest zwykłym plikiem (otwarta przez zamianę?): {path}',
  'history.open_failed' => 'nie można otworzyć pliku historii {path}',
  'history.replace_failed' => 'nie można zastąpić pliku historii {path}',
  'history.symlink_refused' => 'odmowa {what} będącego dowiązaniem: {path}',
  'history.temp_create_failed' => 'nie można utworzyć pliku tymczasowego do przepisania historii w {dir}',
  'history.temp_lock_failed' => 'nie można zablokować pliku tymczasowego przepisywania historii: {path}',
  'history.temp_open_failed' => 'nie można otworzyć pliku tymczasowego przepisywania historii: {path}',
  'multiselect.hint' => '[wybór wielokrotny] zaznaczono: {count}',
  'multiselect.hint_max' => '[wybór wielokrotny] zaznaczono: {count} (max {max})',
  'multiselect.hint_min' => '[wybór wielokrotny] zaznaczono: {count} (min {min})',
  'multiselect.hint_min_max' => '[wybór wielokrotny] zaznaczono: {count} (min {min}) (max {max})',
  'multiselect.notice_unmarked' => 'odznaczono {item}, aby przestrzec limitu {max}',
  'selection.filter_prefix' => '[filtr] ',
  'selection.matches_one' => ' ({count} dopasowanie)',
  'selection.matches_other' => ' ({count} dopasowań)',
  'selection.no_matches' => '(brak dopasowań)',
  'selection.page' => 'Strona {page}/{of}',
  'textarea.notice_max_lines' => 'nie można dodać wiersza: limit {max} osiągnięty',
  'text.invalid_input' => 'Nieprawidłowe dane wejściowe',
  'text.notice_no_history' => 'historia jest pusta',
  'text.search_line_forward' => '{label}(i-wyszukiwanie)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(nieudane i-wyszukiwanie)`{query}\': ',
  'text.search_line_reverse' => '{label}(wyszukiwanie-i-wsteczne)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(nieudane wyszukiwanie-i-wsteczne)`{query}\': ',
);
