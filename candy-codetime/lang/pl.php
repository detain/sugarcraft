<?php

/**
 * Polish translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'zapisano {duration}s projektu {project} ({language})',
    'cli.usage' => "Użycie: candy-codetime [--backend=file|sqlite] <command> [args]\n\nPolecenia:\n  dashboard              Uruchamia pulpit TUI (odczytuje wybrany backend)\n  push                   <project> <language> <file> [duration] [tags] | --  Zapisz serce bicia\n  milestone              add [name] [description] | list  Zarządzaj kamieniami milowymi (tylko sqlite)\n  export                 <csv|json|ics> [days]  Wyeksportuj bicia serca (domyślnie 7 dni)\n  gaps                   [days]  Pokaż niesledzone przerwy nie krótsze niż 300 sekund (domyślnie 7 dni)\n  backup                 Archiwizuj pliki starsze niż 30 dni (kopiuje; nigdy nie usuwa)\n  help, --help, -h       Pokaż to użycie\n  --version, -v          Wypisz wersję\n\nOpcje:\n  --backend=file|sqlite  Backend przechowywania dla push/export/gaps/dashboard (domyślnie: file)\n  --time=<unix>          push: zapis bicie serca w tych sekundach uniksa, nie teraz\n  --sqlite               backup: archiwizuj bazę SQLite poleceniem VACUUM INTO (kopia odporna na awarię)\n  CANDY_CODETIME_DIR     Katalog danych (domyślnie: \$XDG_DATA_HOME/candy-codetime, inaczej \$HOME/.local/share/candy-codetime, a gdy HOME nie jest ustawione — ./.local/share/candy-codetime)",
    'cli.push_usage' => 'Użycie: candy-codetime push <project> <language> <file> [duration] [tags] [--time=<unix>] | candy-codetime push -- (partia linii JSON ze stdin)',
    'cli.push_missing_args' => "push: brakuje wymaganych argumentów (project, language, file)",
    'cli.push_ignored' => "push: {file} pasował do .sugartrackignore — pominięto",
    'cli.push_ignored_wholesale' => 'push: {file} pominięty przez wzorzec całkowity w {ignore_file} ({patterns} wzorców); projekt "{project}" nic nie zapisuje — usuń ten plik lub go zawęź',
    'cli.push_invalid_duration' => 'push: czas trwania musi być liczbą całkowitą sekund, otrzymano "{value}"',
    'cli.push_empty_field' => 'push: pole {field} nie może być puste',
    'cli.push_invalid_time' => 'push: --time musi być liczbą całkowitą sekund uniksa, otrzymano "{value}"',
    'cli.push_time_stdin_conflict' => 'push: --time nie jest akceptowany w trybie wsadowym ze stdin; każda linia ma własny czas',
    'cli.push_stdin_malformed' => 'push: wiersz {line} ze stdin nie jest prawidłowym rekordem bicia serca — pominięto',
    'cli.push_stdin_done' => 'push: zapisano {count} sercobić',
    'cli.push_stdin_done_one' => 'push: zapisano {count} sercobicie',
    'cli.invalid_days' => 'liczba dni musi być liczbą całkowitą, otrzymano "{value}"',
    'cli.backend_invalid' => "nieprawidłowy backend (użyj file lub sqlite)",
    'cli.backend_unavailable' => "backend sqlite niedostępny: rozszerzenie PHP sqlite3 nie jest załadowane",
    'cli.backend_other_hint' => 'uwaga: backend {other} też zawiera rekordy - wybierz go przez --backend={other}',
    'cli.storage_error' => 'candy-codetime: błąd przechowywania: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: panel wymaga interaktywnego terminala (stdin i stdout nie są TTY). Dla wyjścia bez terminala: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Użycie: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: brakuje wymaganego argumentu (name)",
    'cli.milestone_added' => "kamień milowy dodany: {name}",
    'cli.milestone_list_header' => "Kamienie milowe:",
    'cli.milestone_list_empty' => "Brak zapisanych kamieni milowych.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'kamień milowy: {name} zapisany w backendzie sqlite - magazyn plików nie obsługuje kamieni milowych, jego baza danych została tutaj utworzona; panel wyświetla je z --backend=sqlite',

    'cli.export_usage' => "Użycie: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: nieprawidłowy format (użyj csv, json lub ics)",
    'cli.gaps_no_data' => "Nie znaleziono przerw.",
    'cli.gaps_header' => "Niesledzone przerwy w czasie:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: zarchiwizowano {count} plików",
    'cli.backup_done_one' => 'backup: zarchiwizowano {count} plik',
    'cli.backup_none' => "backup: nic nowego do zarchiwizowania",
    'cli.backup_sqlite_notice' => 'backup: to polecenie archiwizuje tylko pliki dzienne — dla bazy SQLite {db} użyj backup --sqlite (kopia odporna na awarię)',
    'cli.backup_sqlite_done' => 'backup: zarchiwizowano bazę do {file}',
    'cli.unknown_command' => "candy-codetime: nieznane polecenie: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} jest przestarzałe; ustaw zamiast tego CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Najlepsze projekty',
    'ui.by_language' => 'Wg języka',
    'ui.by_tags' => 'Według tagu',
    'ui.help_row' => '  ←  poprzedni dzień  ·  →  następny dzień  ·  r  przeładuj  ·  q/Esc  zakończ',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'łącznie:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(brak aktywności)',
    'ui.more_suffix' => '+{count} więcej',
    'ui.earlier_suffix' => '+{count} wcześniejszych',
    'ui.activity_minutes_heading' => 'Dzienna aktywność (minuty)',
    'ui.activity_heading' => 'Dzienna aktywność',
    'ui.no_data' => '(brak danych)',
    'ui.diagnostics_dropped' => 'pominięto {count} uszkodzonych linii z plików dziennych',
    'ui.diagnostics_unreadable' => 'nie można odczytać {count} plików dziennych',
];
