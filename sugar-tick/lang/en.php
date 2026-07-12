<?php

/**
 * English (default) translations for sugar-tick.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.tick_pushed' => 'tick pushed: {project} ({language}) +{duration}s',
    'cli.usage' => "Usage: sugar-tick [--backend=file|sqlite] <command> [args]\n\nCommands:\n  dashboard   Start the TUI dashboard\n  push        <project> <language> <file> [duration]  Record a heartbeat\n  milestone   <add|list> [name] [description]         Manage milestones (sqlite)\n  export      <csv|json|ics> [days]                   Export heartbeats\n  gaps        [days]                                   Show untracked time gaps\n  backup                                       Rotate backups\n  help, --help   Show this usage\n\nOptions:\n  --backend=file|sqlite  Storage backend for push/export/gaps (default: file)",
    'cli.push_usage' => 'Usage: sugar-tick push <project> <language> <file> [duration]',
    'cli.push_missing_args' => 'push: missing required arguments (project, language, file)',
    'cli.push_ignored' => 'push: {file} matched .sugartrackignore — skipped',
    'cli.backend_invalid' => 'invalid backend (use file or sqlite)',
    'cli.backend_unavailable' => 'sqlite backend unavailable: the sqlite3 PHP extension is not loaded',
    'cli.milestone_usage' => 'Usage: sugar-tick milestone <add|list> [name] [description]',
    'cli.milestone_missing_args' => 'milestone add: missing required argument (name)',
    'cli.milestone_added' => 'milestone added: {name}',
    'cli.milestone_list_header' => 'Milestones:',
    'cli.milestone_list_empty' => 'No milestones recorded.',
    'cli.milestone_entry' => '  {date}  {name}  {description}',
    'cli.export_usage' => 'Usage: sugar-tick export <csv|json|ics> [days]',
    'cli.export_invalid_format' => 'export: invalid format (use csv, json, or ics)',
    'cli.gaps_usage' => 'Usage: sugar-tick gaps [days]',
    'cli.gaps_no_data' => 'No gaps found.',
    'cli.gaps_header' => 'Untracked time gaps:',
    'cli.gaps_entry' => '  {start} – {end}  ({seconds}s untracked)',
    'cli.backup_done' => 'backup: rotated {count} files',
    'cli.backup_none' => 'backup: nothing to rotate',
];
