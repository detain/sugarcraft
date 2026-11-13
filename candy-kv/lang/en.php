<?php

/**
 * English (default) translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Entry was written but is no longer readable (expired, deleted, or corrupt)',
    'store.cannot_read'         => 'Cannot read file: {path}',

    // bin/candy-kv
    'cli.usage_set'             => 'Usage: {bin} set <key> [value] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Usage: {bin} get <key> [--sanitize]',
    'cli.usage_delete'          => 'Usage: {bin} delete <key>',
    'cli.usage_import'          => 'Usage: {bin} import <json|yaml|yml> <path> [--no-atomic] [--if-exists=MODE] [--max-bytes=N]',
    'cli.usage_export'          => 'Usage: {bin} export <json|yaml|yml> [db] [pattern]',
    'cli.deleted_n'             => 'Deleted {count} entries.',
    'cli.deleted_none'          => 'Nothing deleted: no entries match "{key}".',
    'cli.dryrun_none'            => 'Nothing would be deleted: no entries match "{key}".',
    'cli.unknown_command'       => 'Unknown command: {cmd}',
    'cli.pattern_not_supported' => 'get takes an exact key, not a pattern. Use "{bin} list <pattern>" to match glob patterns.',
    'cli.db_deleted'            => 'Deleted database "{name}".',
    'cli.db_not_found'          => 'Database not found: {db}',
    'cli.db_did_you_mean'       => 'Did you mean database \'{db}\'?',
    'store.did_you_mean'        => 'Key not found; did you mean \'{key}\'?',
    'cli.key_not_found'          => 'Key not found: {key}',
    'cli.deleted_one'            => 'Deleted 1 entry.',
    'cli.imported_one'           => 'Imported 1 entry.',
    'cli.imported_n'             => 'Imported {count} entries.',
    'cli.overwrote_one'          => 'Overwrote 1 entry.',
    'cli.overwrote_n'            => 'Overwrote {count} entries.',
    'cli.would_delete_one'       => 'Would delete 1 entry.',
    'cli.would_delete_n'         => 'Would delete {count} entries.',
    'cli.pruned_n'               => 'Removed {count} expired entries.',
    'cli.pruned_one'             => 'Removed 1 expired entry.',
    'cli.pruned_none'            => 'No expired entries to remove.',
];
