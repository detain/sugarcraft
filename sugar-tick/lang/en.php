<?php

/**
 * English (default) translations for sugar-tick.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.tick_pushed' => 'tick pushed: {project} ({language}) +{duration}s',
    'cli.usage' => "Usage: sugar-tick <command> [args]\n\nCommands:\n  dashboard   Start the TUI dashboard\n  push        <project> <language> <file> [duration]  Record a heartbeat\n  export      <csv|json|ics> [days]                   Export heartbeats\n  gaps        [days]                                   Show untracked time gaps\n  backup                                       Rotate backups\n  help, --help   Show this usage",
    'cli.push_usage' => 'Usage: sugar-tick push <project> <language> <file> [duration]',
    'cli.push_missing_args' => 'push: missing required arguments (project, language, file)',
    'cli.export_usage' => 'Usage: sugar-tick export <csv|json|ics> [days]',
    'cli.export_invalid_format' => 'export: invalid format (use csv, json, or ics)',
    'cli.gaps_usage' => 'Usage: sugar-tick gaps [days]',
    'cli.gaps_no_data' => 'No gaps found.',
    'cli.gaps_header' => 'Untracked time gaps:',
    'cli.gaps_entry' => '  {start} – {end}  ({seconds}s untracked)',
    'cli.backup_done' => 'backup: rotated {count} files',
    'cli.backup_none' => 'backup: nothing to rotate',
];
