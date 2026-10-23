<?php

/**
 * Dutch translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "item vastgelegd: {project} ({language}) +{duration}s",
    'cli.usage' => "Gebruik: candy-codetime [--backend=file|sqlite] <command> [args]\n\nCommando's:\n  dashboard              Registreer een heartbeat\n  push                   <project> <language> <file> [duration]  Beheer mijlpalen (alleen sqlite)\n  milestone              <add|list> [name] [description]  Exporteer heartbeats (standaard 7 dagen)\n  export                 <csv|json|ics> [days]  Toon niet-vastgelegde gaten van meer dan 300 seconden (standaard 7 dagen)\n  gaps                   [days]  Archiveer bestanden ouder dan 30 dagen (kopiëert; verwijdert nooit)\n  backup                 Deze gebruik tonen\n  help, --help           Versie tonen\n  --version, -v          standaard: file\n\nOpties:\n  --backend=file|sqlite  Opslag-backend voor push/export/gaps/dashboard (standaard: file)\n  CANDY_CODETIME_DIR     Datamap (standaard: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Gebruik: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: verplichte argumenten ontbreken (project, language, file)",
    'cli.push_ignored' => "push: {file} kwam overeen met .sugartrackignore — overgeslagen",
    'cli.push_invalid_duration' => "push: duur moet een niet-negatief geheel getal in seconden zijn, ontvangen \"{value}\"",
    'cli.backend_invalid' => "ongeldige backend (gebruik file of sqlite)",
    'cli.backend_unavailable' => "sqlite-backend niet beschikbaar: de PHP-extensie sqlite3 is niet geladen",
    'cli.milestone_usage' => "Gebruik: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: verplicht argument ontbreekt (name)",
    'cli.milestone_added' => "mijlpaal toegevoegd: {name}",
    'cli.milestone_list_header' => "Mijlpalen:",
    'cli.milestone_list_empty' => "Geen mijlpalen vastgelegd.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Gebruik: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: ongeldig formaat (gebruik csv, json of ics)",
    'cli.gaps_no_data' => "Geen gaten gevonden.",
    'cli.gaps_header' => "Niet-vastgelegde tijdsgaten:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} bestanden gearchiveerd",
    'cli.backup_none' => "backup: niets nieuws om te archiveren",
    'cli.unknown_command' => "candy-codetime: onbekend commando: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} is verouderd; stel CANDY_CODETIME_DIR in.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
