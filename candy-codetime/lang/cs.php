<?php

/**
 * Czech translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'zaznamenáno {duration}s projektu {project} ({language})',
    'cli.usage' => "Použití: candy-codetime [--backend=file|sqlite] <command> [args]\n\nPříkazy:\n  dashboard              Spustí panel TUI (načítá vybraný backend)\n  push                   [<project> <language>] <file> [duration] [tags] | --  Zaznamenat heartbeat\n  milestone              add [name] [description] | list  Spravovat milníky (pouze sqlite)\n  export                 <csv|json|ics> [days]  Exportovat heartbeats (výchozí 7 dní)\n  gaps                   [days]  Zobrazit nesledované mezery alespoň 300 sekund (výchozí 7 dní)\n  backup                 Archivovat soubory starší než 30 dní (kopíruje; nikdy nemaže)\n  help, --help, -h       Zobrazit toto použití\n  --version, -v          Vytisknout verzi\n\nMožnosti:\n  --backend=file|sqlite  Backend úložiště pro push/export/gaps/dashboard (výchozí: file)\n  --time=<unix>          push: zaznamenat tep v těchto unixových sekundách místo teď\n  --sqlite               backup: archivovat databázi SQLite pomocí VACUUM INTO (bezpečná kopie)\n  --theme=<name>         dashboard: malujte pojmenovanou paletou (default, light, dracula, solarized, gruvbox, high-contrast)
  CANDY_CODETIME_THEME   stejná volba jako proměnná prostředí (příkazová volba má přednost)\n  CANDY_CODETIME_DIR     Datový adresář (výchozí: \$XDG_DATA_HOME/candy-codetime, jinak \$HOME/.local/share/candy-codetime, nebo ./.local/share/candy-codetime když HOME není nastaven)",
    'cli.push_usage' => 'Použití: candy-codetime push [<project> <language>] <file> [duration] [tags] [--time=<unix>] | candy-codetime push -- (dávka JSON řádků ze stdin)',
    'cli.push_missing_args' => "push: chybí povinné argumenty (project, language, file)",
    'cli.push_ignored' => "push: {file} odpovídá .sugartrackignore — přeskočeno",
    'cli.push_ignored_wholesale' => 'push: {file} přeskočen plošným vzorem v {ignore_file} ({patterns} vzorů); projekt "{project}" nic nezaznamenává — soubor smažte nebo zúžte',
    'cli.push_invalid_duration' => 'push: doba trvání musí být celé číslo v sekundách, obdrženo "{value}"',
    'cli.push_empty_field' => 'push: pole {field} nesmí být prázdné',
    'cli.push_invalid_time' => 'push: --time musí být celé číslo unixových sekund, obdrženo "{value}"',
    'cli.push_time_stdin_conflict' => 'push: --time se v dávkovém režimu ze stdin nepoužívá; každý řádek má vlastní čas',
    'cli.push_stdin_malformed' => 'push: řádek stdin {line} není platný záznam tepu — přeskočeno',
    'cli.push_stdin_done' => 'push: zaznamenáno {count} tepů',
    'cli.push_stdin_done_one' => 'push: zaznamenán {count} tep',
    'cli.invalid_days' => 'počet dní musí být celé číslo, obdrženo "{value}"',
    'cli.invalid_goal' => 'CANDY_CODETIME_GOAL musí být celé číslo sekund nebo celý počet hodin jako 6h, získáno "{value}"',
    'cli.invalid_theme' => 'motiv musí být jedním z {names} - obdrženo "{value}"',
    'cli.backend_invalid' => "neplatný backend (použijte file nebo sqlite)",
    'cli.backend_unavailable' => "backend sqlite není dostupný: rozšíření PHP sqlite3 není načteno",
    'cli.backend_other_hint' => 'poznámka: backend {other} obsahuje také záznamy - vyber ho pomocí --backend={other}',
    'cli.storage_error' => 'candy-codetime: chyba úložiště: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: dashboard potřebuje interaktivní terminál (stdin a stdout nejsou TTY). Pro výstup bez terminálu: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Použití: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: chybí povinný argument (name)",
    'cli.milestone_added' => "milník přidán: {name}",
    'cli.milestone_list_header' => "Milníky:",
    'cli.milestone_list_empty' => "Žádné milníky nebyly zapsány.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.milestone_sqlite_notice' => 'milník: {name} uložen v backendu sqlite - souborový sklad nemůže obsahovat milníky, jeho databáze byla vytvořena zde; dashboard je zobrazí s --backend=sqlite',

    'cli.export_usage' => "Použití: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: neplatný formát (použijte csv, json nebo ics)",
    'cli.gaps_no_data' => "Nebyly nalezeny žádné mezery.",
    'cli.gaps_header' => "Nesledované časové mezery:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} souborů archivováno",
    'cli.backup_done_one' => 'backup: {count} soubor archivován',
    'cli.backup_none' => "backup: nic nového k archivaci",
    'cli.backup_sqlite_notice' => 'backup: tento příkaz archivuje pouze denní soubory — pro databázi SQLite {db} použijte backup --sqlite (bezpečná kopie)',
    'cli.backup_sqlite_done' => 'backup: databáze archivována do {file}',
    'cli.unknown_command' => "candy-codetime: neznámý příkaz: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} je zastaralé; nastavte místo toho CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
    'ui.top_projects' => 'Nejaktivnější projekty',
    'ui.by_language' => 'Podle jazyka',
    'ui.by_tags' => 'Podle štítku',
    'ui.goal_today' => 'dnes',
    'ui.help_row' => '  ←  předchozí den  ·  →  další den  ·  r  obnovit  ·  q/Esc  konec',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'celkem:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(žádná aktivita)',
    'ui.more_suffix' => '+{count} dalších',
    'ui.earlier_suffix' => '+{count} dřívějších',
    'ui.activity_minutes_heading' => 'Denní aktivita (minuty)',
    'ui.activity_heading' => 'Denní aktivita',
    'ui.no_data' => '(žádná data)',
    'ui.diagnostics_dropped' => 'vynecháno {count} poškozených řádků ze souborů dnů',
    'ui.diagnostics_unreadable' => 'nelze přečíst {count} soubor(ů) dne',
];
