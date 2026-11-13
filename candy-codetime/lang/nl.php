<?php

/**
 * Dutch translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => '{duration}s vastgelegd van {project} ({language})',
    'cli.usage' => "Gebruik: candy-codetime [--backend=file|sqlite] <command> [args]\n\nCommando's:\n  dashboard              Start het TUI-dashboard (leest het gekozen backend)\n  push                   [<project> <language>] <file> [duration] [tags] | --  Registreer een heartbeat\n  milestone              add [name] [description] | list  Beheer mijlpalen (alleen sqlite)\n  export                 <csv|json|ics> [days]  Exporteer heartbeats (standaard 7 dagen)\n  gaps                   [days]  Toon niet-vastgelegde gaten van 300 seconden of meer (standaard 7 dagen)\n  backup                 Archiveer bestanden ouder dan 30 dagen (kopiëert; verwijdert nooit)\n  help, --help, -h       Deze gebruik tonen\n  --version, -v          Versie tonen\n\nOpties:\n  --backend=file|sqlite  Opslag-backend voor push/export/gaps/dashboard (standaard: file)\n  --time=<unix>          push: heartbeat op deze unix-seconden vastleggen, niet nu\n  --sqlite               backup: de SQLite-database archiveren met VACUUM INTO (crashveilige kopie)\n  --theme=<name>         dashboard: schilderen met een genoemde palette (default, light, dracula, solarized, gruvbox, high-contrast)
  CANDY_CODETIME_THEME   dezelfde keuze als omgevingsvariabele (een vlag gaat voor)\n  CANDY_CODETIME_DIR     Datamap (standaard: \$XDG_DATA_HOME/candy-codetime, anders \$HOME/.local/share/candy-codetime, of ./.local/share/candy-codetime als HOME niet is ingesteld)",
    'cli.push_usage' => 'Gebruik: candy-codetime push [<project> <language>] <file> [duration] [tags] [--time=<unix>] | candy-codetime push -- (batch JSON-regels via stdin)',
    'cli.push_missing_args' => "candy-codetime: push: verplichte argumenten ontbreken (project, language, file)",
    'cli.push_ignored' => "push: {file} kwam overeen met .sugartrackignore — overgeslagen",
    'cli.push_ignored_wholesale' => 'candy-codetime: push: {file} overgeslagen door een algemeen patroon in {ignore_file} ({patterns} patronen); project "{project}" legt niets vast — verwijder dat bestand of beperk het',
    'cli.push_invalid_duration' => 'candy-codetime: push: duur moet een geheel getal in seconden zijn, ontvangen "{value}"',
    'cli.push_empty_field' => 'candy-codetime: push: het veld {field} mag niet leeg zijn',
    'cli.push_invalid_time' => 'candy-codetime: push: --time moet een geheel getal in unix-seconden zijn, ontvangen "{value}"',
    'cli.push_time_stdin_conflict' => 'candy-codetime: push: --time wordt niet geaccepteerd in batchmodus via stdin; geef elke regel zijn eigen tijd',
    'cli.push_stdin_malformed' => 'candy-codetime: push: stdin-regel {line} is geen geldige heartbeat-record — overgeslagen',
    'cli.push_stdin_done' => 'push: {count} hartenkloppen vastgelegd',
    'cli.push_stdin_done_one' => 'push: {count} hartenklop vastgelegd',
    'cli.invalid_days' => 'candy-codetime: aantal dagen moet een geheel getal zijn, ontvangen "{value}"',
    'cli.invalid_goal' => 'candy-codetime: CANDY_CODETIME_GOAL moet een heel aantal seconden zijn, of een heel aantal uren zoals 6h, ontvangen "{value}"',
    'cli.invalid_theme' => 'candy-codetime: thema moet een van {names} zijn - gekregen "{value}"',
    'cli.backend_invalid' => "candy-codetime: ongeldige backend (gebruik file of sqlite)",
    'cli.backend_unavailable' => "candy-codetime: sqlite-backend niet beschikbaar: de PHP-extensie sqlite3 is niet geladen",
    'cli.backend_other_hint' => 'let op: de {other}-backend bevat ook gegevens - kies hem met --backend={other}',
    'cli.storage_error' => 'candy-codetime: opslagfout: {message}',
    'cli.ignore_too_large' => 'candy-codetime: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: het dashboard heeft een interactieve terminal nodig (stdin en stdout zijn geen TTY). Voor uitvoer zonder terminal: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Gebruik: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "candy-codetime: milestone add: verplicht argument ontbreekt (name)",
    'cli.milestone_added' => "mijlpaal toegevoegd: {name}",
    'cli.milestone_list_header' => "Mijlpalen:",
    'cli.milestone_list_empty' => "Geen mijlpalen vastgelegd.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'candy-codetime: mijlpaal: {name} opgeslagen in de sqlite-backend - de bestandopslag bevat geen mijlpalen, de databank is hier aangemaakt; het dashboard toont ze met --backend=sqlite',

    'cli.export_usage' => "Gebruik: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "candy-codetime: export: ongeldig formaat (gebruik csv, json of ics)",
    'cli.gaps_no_data' => "Geen gaten gevonden.",
    'cli.gaps_header' => "Niet-vastgelegde tijdsgaten:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} bestanden gearchiveerd",
    'cli.backup_done_one' => 'backup: {count} bestand gearchiveerd',
    'cli.backup_none' => "backup: niets nieuws om te archiveren",
    'cli.backup_sqlite_notice' => 'candy-codetime: backup: dit commando archiveert alleen de dagsbestanden — voor de SQLite-database {db} is backup --sqlite nodig (crashveilige kopie)',
    'cli.backup_sqlite_done' => 'backup: database gearchiveerd naar {file}',
    'cli.unknown_command' => "candy-codetime: onbekend commando: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} is verouderd; stel CANDY_CODETIME_DIR in.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Topprojecten',
    'ui.by_language' => 'Per taal',
    'ui.by_tags' => 'Per tag',
    'ui.goal_today' => 'vandaag',
    'ui.help_row' => '  ←  vorige dag  ·  →  volgende dag  ·  r  herladen  ·  q/Esc  afsluiten',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'totaal:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(geen activiteit)',
    'ui.more_suffix' => '+{count} meer',
    'ui.earlier_suffix' => '+{count} eerder',
    'ui.activity_minutes_heading' => 'Dagelijkse activiteit (minuten)',
    'ui.activity_heading' => 'Dagelijkse activiteit',
    'ui.no_data' => '(geen gegevens)',
    'ui.diagnostics_dropped' => '{count} beschadigde regels uit dagsbestanden genegeerd',
    'ui.diagnostics_unreadable' => 'kon {count} dagsbestand(en) niet lezen',
];
