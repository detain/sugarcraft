<?php

/**
 * Polish translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "wpis zapisany: {project} ({language}) +{duration}s",
    'cli.usage' => "Użycie: candy-codetime [--backend=file|sqlite] <command> [args]\n\nPolecenia:\n  dashboard              Zapisz serce bicia\n  push                   <project> <language> <file> [duration]  Zarządzaj kamieniami milowymi (tylko sqlite)\n  milestone              <add|list> [name] [description]  Wyeksportuj bicia serca (domyślnie 7 dni)\n  export                 <csv|json|ics> [days]  Pokaż niesledzone przerwy dłuższe niż 300 sekund (domyślnie 7 dni)\n  gaps                   [days]  Archiwizuj pliki starsze niż 30 dni (kopiuje; nigdy nie usuwa)\n  backup                 Pokaż to użycie\n  help, --help           Wypisz wersję\n  --version, -v          domyślnie: file\n\nOpcje:\n  --backend=file|sqlite  Backend przechowywania dla push/export/gaps/dashboard (domyślnie: file)\n  CANDY_CODETIME_DIR     Katalog danych (domyślnie: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Użycie: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: brakuje wymaganych argumentów (project, language, file)",
    'cli.push_ignored' => "push: {file} pasował do .sugartrackignore — pominięto",
    'cli.push_invalid_duration' => "push: czas trwania musi być nieujemną liczbą całkowitą sekund, otrzymano \"{value}\"",
    'cli.backend_invalid' => "nieprawidłowy backend (użyj file lub sqlite)",
    'cli.backend_unavailable' => "backend sqlite niedostępny: rozszerzenie PHP sqlite3 nie jest załadowane",
    'cli.milestone_usage' => "Użycie: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: brakuje wymaganego argumentu (name)",
    'cli.milestone_added' => "kamień milowy dodany: {name}",
    'cli.milestone_list_header' => "Kamienie milowe:",
    'cli.milestone_list_empty' => "Brak zapisanych kamieni milowych.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Użycie: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: nieprawidłowy format (użyj csv, json lub ics)",
    'cli.gaps_no_data' => "Nie znaleziono przerw.",
    'cli.gaps_header' => "Niesledzone przerwy w czasie:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: zarchiwizowano {count} plików",
    'cli.backup_none' => "backup: nic nowego do zarchiwizowania",
    'cli.unknown_command' => "candy-codetime: nieznane polecenie: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} jest przestarzałe; ustaw zamiast tego CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
