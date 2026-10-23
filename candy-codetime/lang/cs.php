<?php

/**
 * Czech translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "záznam uložen: {project} ({language}) +{duration}s",
    'cli.usage' => "Použití: candy-codetime [--backend=file|sqlite] <command> [args]\n\nPříkazy:\n  dashboard              Zaznamenat heartbeat\n  push                   <project> <language> <file> [duration]  Spravovat milníky (pouze sqlite)\n  milestone              <add|list> [name] [description]  Exportovat heartbeats (výchozí 7 dní)\n  export                 <csv|json|ics> [days]  Zobrazit nesledované mezery delší než 300 sekund (výchozí 7 dní)\n  gaps                   [days]  Archivovat soubory starší než 30 dní (kopíruje; nikdy nemaže)\n  backup                 Zobrazit toto použití\n  help, --help           Vytisknout verzi\n  --version, -v          výchozí: file\n\nMožnosti:\n  --backend=file|sqlite  Backend úložiště pro push/export/gaps/dashboard (výchozí: file)\n  CANDY_CODETIME_DIR     Datový adresář (výchozí: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Použití: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: chybí povinné argumenty (project, language, file)",
    'cli.push_ignored' => "push: {file} odpovídá .sugartrackignore — přeskočeno",
    'cli.push_invalid_duration' => "push: doba trvání musí být nezáporné celé číslo v sekundách, obdrženo \"{value}\"",
    'cli.backend_invalid' => "neplatný backend (použijte file nebo sqlite)",
    'cli.backend_unavailable' => "backend sqlite není dostupný: rozšíření PHP sqlite3 není načteno",
    'cli.milestone_usage' => "Použití: candy-codetime milestone <add|list> [name] [description]",
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
    'cli.backup_none' => "backup: nic nového k archivaci",
    'cli.unknown_command' => "candy-codetime: neznámý příkaz: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} je zastaralé; nastavte místo toho CANDY_CODETIME_DIR.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
