<?php

/**
 * English (default) translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => 'entry pushed: {project} ({language}) +{duration}s',
    'cli.usage' => "Usage: candy-codetime [--backend=file|sqlite] <command> [args]\n\nCommands:\n  dashboard              Start the TUI dashboard (reads the selected backend)\n  push                   <project> <language> <file> [duration]  Record a heartbeat\n  milestone              <add|list> [name] [description]  Manage milestones (sqlite only)\n  export                 <csv|json|ics> [days]  Export heartbeats (default 7 days)\n  gaps                   [days]  Show untracked gaps over 300 seconds (default 7 days)\n  backup                 Archive data files older than 30 days (copies; never deletes)\n  help, --help           Show this usage\n  --version, -v          Print the version\n\nOptions:\n  --backend=file|sqlite  Storage backend for push/export/gaps/dashboard (default: file)\n  CANDY_CODETIME_DIR     Data directory (default: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => 'Usage: candy-codetime push <project> <language> <file> [duration]',
    'cli.push_missing_args' => 'push: missing required arguments (project, language, file)',
    'cli.push_ignored' => 'push: {file} matched .sugartrackignore — skipped',
    'cli.push_invalid_duration' => 'push: duration must be a non-negative integer of seconds, got "{value}"',
    'cli.backend_invalid' => 'invalid backend (use file or sqlite)',
    'cli.backend_unavailable' => 'sqlite backend unavailable: the sqlite3 PHP extension is not loaded',
    'cli.milestone_usage' => 'Usage: candy-codetime milestone <add|list> [name] [description]',
    'cli.milestone_missing_args' => 'milestone add: missing required argument (name)',
    'cli.milestone_added' => 'milestone added: {name}',
    'cli.milestone_list_header' => 'Milestones:',
    'cli.milestone_list_empty' => 'No milestones recorded.',
    'cli.milestone_entry' => '  {date}  {name}  {description}',
    'cli.export_usage' => 'Usage: candy-codetime export <csv|json|ics> [days]',
    'cli.export_invalid_format' => 'export: invalid format (use csv, json, or ics)',
    'cli.gaps_no_data' => 'No gaps found.',
    'cli.gaps_header' => 'Untracked time gaps:',
    'cli.gaps_entry' => '  {start} – {end}  ({seconds}s untracked)',
    'cli.backup_done' => 'backup: archived {count} files',
    'cli.backup_none' => 'backup: nothing new to archive',
    'cli.unknown_command' => 'candy-codetime: unknown command: {command}',
    'cli.legacy_env_deprecated' => 'candy-codetime: {variable} is deprecated; set CANDY_CODETIME_DIR instead.',
    'cli.migration_notice' => 'candy-codetime: {notice}',
];
