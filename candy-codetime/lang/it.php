<?php

/**
 * Italian translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "voce registrata: {project} ({language}) +{duration}s",
    'cli.usage' => "Uso: candy-codetime [--backend=file|sqlite] <command> [args]\n\nComandi:\n  dashboard              Registra un heartbeat\n  push                   <project> <language> <file> [duration]  Gestisci i traguardi (solo sqlite)\n  milestone              <add|list> [name] [description]  Esporta gli heartbeat (7 giorni predefiniti)\n  export                 <csv|json|ics> [days]  Mostra intervalli non monitorati oltre 300 secondi (7 giorni predefiniti)\n  gaps                   [days]  Archivia i file dati più vecchi di 30 giorni (copia; non elimina mai)\n  backup                 Mostra questo uso\n  help, --help           Stampa la versione\n  --version, -v          predefinito: file\n\nOpzioni:\n  --backend=file|sqlite  Backend di archiviazione per push/export/gaps/dashboard (predefinito: file)\n  CANDY_CODETIME_DIR     Directory dati (predefinita: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Uso: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: argomenti obbligatori mancanti (project, language, file)",
    'cli.push_ignored' => "push: {file} corrispondeva a .sugartrackignore — saltato",
    'cli.push_invalid_duration' => "push: la durata deve essere un intero non negativo di secondi, ricevuto \"{value}\"",
    'cli.backend_invalid' => "backend non valido (usare file o sqlite)",
    'cli.backend_unavailable' => "backend sqlite non disponibile: l'estensione PHP sqlite3 non è caricata",
    'cli.milestone_usage' => "Uso: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: argomento obbligatorio mancante (name)",
    'cli.milestone_added' => "traguardo aggiunto: {name}",
    'cli.milestone_list_header' => "Traguardi:",
    'cli.milestone_list_empty' => "Nessun traguardo registrato.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Uso: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: formato non valido (usare csv, json o ics)",
    'cli.gaps_no_data' => "Nessun intervallo trovato.",
    'cli.gaps_header' => "Intervalli non monitorati:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} file archiviati",
    'cli.backup_none' => "backup: nulla di nuovo da archiviare",
    'cli.unknown_command' => "candy-codetime: comando sconosciuto: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} è deprecato; impostare CANDY_CODETIME_DIR in alternativa.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
