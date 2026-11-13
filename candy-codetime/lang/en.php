<?php

/**
 * English (default) translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'recorded {duration}s of {project} ({language})',
    'cli.usage' => "Usage: candy-codetime [--backend=file|sqlite] <command> [args]\n\nCommands:\n  dashboard              Start the TUI dashboard (reads the selected backend)\n  push                   <project> <language> <file> [duration] | --  Record a heartbeat\n  milestone              add [name] [description] | list  Manage milestones (sqlite only)\n  export                 <csv|json|ics> [days]  Export heartbeats (default 7 days)\n  gaps                   [days]  Show untracked gaps at least 300 seconds (default 7 days)\n  backup                 Archive data files older than 30 days (copies; never deletes)\n  help, --help, -h       Show this usage\n  --version, -v          Print the version\n\nOptions:\n  --backend=file|sqlite  Storage backend for push/export/gaps/dashboard (default: file)\n  --time=<unix>          push: record the heartbeat at these unix seconds instead of now\n  --sqlite               backup: archive the SQLite database with VACUUM INTO (crash-safe snapshot)\n  CANDY_CODETIME_DIR     Data directory (default: \$XDG_DATA_HOME/candy-codetime, else \$HOME/.local/share/candy-codetime, or ./.local/share/candy-codetime when HOME is unset)",
    'cli.push_usage' => 'Usage: candy-codetime push <project> <language> <file> [duration] [--time=<unix>] | candy-codetime push -- (batch JSON-lines on stdin)',
    'cli.push_missing_args' => 'push: missing required arguments (project, language, file)',
    'cli.push_ignored' => 'push: {file} matched .sugartrackignore — skipped',
    'cli.push_ignored_wholesale' => 'push: {file} skipped by a wholesale pattern in {ignore_file} ({patterns} patterns); project "{project}" records nothing — delete or narrow that file',
    'cli.push_invalid_duration' => 'push: duration must be a whole number of seconds, got "{value}"',
    'cli.push_empty_field' => 'push: the {field} must not be empty',
    'cli.push_invalid_time' => 'push: --time must be a whole number of unix seconds, got "{value}"',
    'cli.push_time_stdin_conflict' => 'push: --time is not accepted with the stdin batch mode; give each line its own time',
    'cli.push_stdin_malformed' => 'push: stdin line {line} is not a valid heartbeat record — skipped',
    'cli.push_stdin_done' => 'push: recorded {count} heartbeats',
    'cli.push_stdin_done_one' => 'push: recorded {count} heartbeat',
    'cli.invalid_days' => 'days must be a whole number, got "{value}"',
    'cli.backend_invalid' => 'invalid backend (use file or sqlite)',
    'cli.backend_unavailable' => 'sqlite backend unavailable: the sqlite3 PHP extension is not loaded',
    'cli.backend_other_hint' => 'note: the {other} backend also has records - select it with --backend={other}',
    'cli.storage_error' => 'candy-codetime: storage error: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: the dashboard needs an interactive terminal (stdin and stdout are not both TTYs). For headless output use: candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => 'Usage: candy-codetime milestone add [name] [description] | list',
    'cli.milestone_missing_args' => 'milestone add: missing required argument (name)',
    'cli.milestone_added' => 'milestone added: {name}',
    'cli.milestone_list_header' => 'Milestones:',
    'cli.milestone_list_empty' => 'No milestones recorded.',
    'cli.milestone_entry' => '  {date}  {name}  {description}',
    'cli.milestone_sqlite_notice' => 'milestone: {name} saved in the sqlite backend - the file store cannot hold milestones, so its database was created here; the dashboard shows them with --backend=sqlite',

    'cli.export_usage' => 'Usage: candy-codetime export <csv|json|ics> [days]',
    'cli.export_invalid_format' => 'export: invalid format (use csv, json, or ics)',
    'cli.gaps_no_data' => 'No gaps found.',
    'cli.gaps_header' => 'Untracked time gaps:',
    'cli.gaps_entry' => '  {start} – {end}  ({seconds}s untracked)',
    'cli.backup_done' => 'backup: archived {count} files',
    'cli.backup_done_one' => 'backup: archived {count} file',
    'cli.backup_none' => 'backup: nothing new to archive',
    'cli.backup_sqlite_notice' => 'backup: this command archives only the day files — the SQLite database {db} needs backup --sqlite for a crash-safe copy',
    'cli.backup_sqlite_done' => 'backup: archived the database to {file}',
    'cli.unknown_command' => 'candy-codetime: unknown command: {command}',
    'cli.legacy_env_deprecated' => 'candy-codetime: {variable} is deprecated; set CANDY_CODETIME_DIR instead.',
    'cli.migration_notice' => 'candy-codetime: {notice}',
    'ui.top_projects' => 'Top projects',
    'ui.by_language' => 'By language',
    'ui.help_row' => '  ←  prev day  ·  →  next day  ·  r  reload  ·  q/Esc  quit',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'total:',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(no activity)',
    'ui.more_suffix' => '+{count} more',
    'ui.earlier_suffix' => '+{count} earlier',
    'ui.activity_minutes_heading' => 'Daily activity (minutes)',
    'ui.activity_heading' => 'Daily activity',
    'ui.no_data' => '(no data)',
    'ui.diagnostics_dropped' => 'dropped {count} corrupt line(s) from the day files',
    'ui.diagnostics_unreadable' => 'could not read {count} day file(s)',
];
