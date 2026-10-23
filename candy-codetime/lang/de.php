<?php

/**
 * German translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "Eintrag übernommen: {project} ({language}) +{duration}s",
    'cli.usage' => "Verwendung: candy-codetime [--backend=file|sqlite] <command> [args]\n\nBefehle:\n  dashboard              Heartbeat erfassen\n  push                   <project> <language> <file> [duration]  Meilensteine verwalten (nur sqlite)\n  milestone              <add|list> [name] [description]  Heartbeats exportieren (Standard 7 Tage)\n  export                 <csv|json|ics> [days]  Nicht erfasste Lücken über 300 Sekunden zeigen (Standard 7 Tage)\n  gaps                   [days]  Datendateien älter als 30 Tage archivieren (kopiert; löscht nie)\n  backup                 Diese Verwendung zeigen\n  help, --help           Version ausgeben\n  --version, -v          Standard: file\n\nOptionen:\n  --backend=file|sqlite  Speicher-Backend für push/export/gaps/dashboard (Standard: file)\n  CANDY_CODETIME_DIR     Datenverzeichnis (Standard: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Verwendung: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: fehlende Pflichtargumente (project, language, file)",
    'cli.push_ignored' => "push: {file} entsprach .sugartrackignore — übersprungen",
    'cli.push_invalid_duration' => "push: die Dauer muss eine nicht negative Ganzzahl in Sekunden sein, erhalten: \"{value}\"",
    'cli.backend_invalid' => "ungültiges Backend (file oder sqlite verwenden)",
    'cli.backend_unavailable' => "sqlite-Backend nicht verfügbar: die PHP-Erweiterung sqlite3 ist nicht geladen",
    'cli.milestone_usage' => "Verwendung: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: fehlendes Pflichtargument (name)",
    'cli.milestone_added' => "Meilenstein hinzugefügt: {name}",
    'cli.milestone_list_header' => "Meilensteine:",
    'cli.milestone_list_empty' => "Keine Meilensteine erfasst.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Verwendung: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: ungültiges Format (csv, json oder ics verwenden)",
    'cli.gaps_no_data' => "Keine Lücken gefunden.",
    'cli.gaps_header' => "Nicht erfasste Zeitlücken:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} Dateien archiviert",
    'cli.backup_none' => "backup: nichts Neues zu archivieren",
    'cli.unknown_command' => "candy-codetime: unbekannter Befehl: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} ist veraltet; setzen Sie stattdessen CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
