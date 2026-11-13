<?php

/**
 * Czech translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'zaznamenáno {duration}s projektu {project} ({language})',
    'cli.usage' => "Použití: candy-codetime [--backend=file|sqlite] <command> [args]\n\nPříkazy:\n  dashboard              Spustí panel TUI (načítá vybraný backend)\n  push                   <project> <language> <file> [duration]  Zaznamenat heartbeat\n  milestone              add [name] [description] | list  Spravovat milníky (pouze sqlite)\n  export                 <csv|json|ics> [days]  Exportovat heartbeats (výchozí 7 dní)\n  gaps                   [days]  Zobrazit nesledované mezery alespoň 300 sekund (výchozí 7 dní)\n  backup                 Archivovat soubory starší než 30 dní (kopíruje; nikdy nemaže)\n  help, --help, -h       Zobrazit toto použití\n  --version, -v          Vytisknout verzi\n\nMožnosti:\n  --backend=file|sqlite  Backend úložiště pro push/export/gaps/dashboard (výchozí: file)\n  CANDY_CODETIME_DIR     Datový adresář (výchozí: \$XDG_DATA_HOME/candy-codetime, jinak \$HOME/.local/share/candy-codetime, nebo ./.local/share/candy-codetime když HOME není nastaven)",
    'cli.push_usage' => "Použití: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: chybí povinné argumenty (project, language, file)",
    'cli.push_ignored' => "push: {file} odpovídá .sugartrackignore — přeskočeno",
    'cli.push_ignored_wholesale' => 'push: {file} přeskočen plošným vzorem v {ignore_file} ({patterns} vzorů); projekt "{project}" nic nezaznamenává — soubor smažte nebo zúžte',
    'cli.push_invalid_duration' => 'push: doba trvání musí být celé číslo v sekundách, obdrženo "{value}"',
    'cli.invalid_days' => 'počet dní musí být celé číslo, obdrženo "{value}"',
    'cli.backend_invalid' => "neplatný backend (použijte file nebo sqlite)",
    'cli.backend_unavailable' => "backend sqlite není dostupný: rozšíření PHP sqlite3 není načteno",
    'cli.storage_error' => 'candy-codetime: chyba úložiště: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: dashboard potřebuje interaktivní terminál (stdin a stdout nejsou TTY). Pro výstup bez terminálu: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Použití: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: chybí povinný argument (name)",
    'cli.milestone_added' => "milník přidán: {name}",
    'cli.milestone_list_header' => "Milníky:",
    'cli.milestone_list_empty' => "Žádné milníky nebyly zapsány.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Použití: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: neplatný formát (použijte csv, json nebo ics)",
    'cli.gaps_no_data' => "Nebyly nalezeny žádné mezery.",
    'cli.gaps_header' => "Nesledované časové mezery:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} souborů archivováno",
    'cli.backup_done_one' => 'backup: {count} soubor archivován',
    'cli.backup_none' => "backup: nic nového k archivaci",
    'cli.backup_sqlite_notice' => 'backup: databáze SQLite {db} tímto příkazem NE archivuje se — pro bezpečnou kopici použijte sqlite3 <db> ".backup <dest>" nebo VACUUM INTO',
    'cli.unknown_command' => "candy-codetime: neznámý příkaz: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} je zastaralé; nastavte místo toho CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Nejaktivnější projekty',
    'ui.by_language' => 'Podle jazyka',
    'ui.help_row' => '  ←  předchozí den  ·  →  další den  ·  r  obnovit  ·  q/Esc  konec',
    'ui.total' => 'celkem:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(žádná aktivita)',
    'ui.more_suffix' => '+{count} dalších',
    'ui.earlier_suffix' => '+{count} dřívějších',
    'ui.activity_minutes_heading' => 'Denní aktivita (minuty)',
    'ui.activity_heading' => 'Denní aktivita',
    'ui.no_data' => '(žádná data)',
];
