<?php

/**
 * English (default) translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Entry set but not readable',
    'store.cannot_read'         => 'Cannot read file: {path}',

    // bin/candy-kv
    'cli.usage_set'             => 'Usage: {bin} set <key> [value] [--ttl SECONDS]',
    'cli.usage_get'             => 'Usage: {bin} get <key>',
    'cli.usage_delete'          => 'Usage: {bin} delete <key>',
    'cli.usage_import'          => 'Usage: {bin} import <json|yaml|yml> <path>',
    'cli.usage_export'          => 'Usage: {bin} export <json|yaml|yml> [db] [pattern]',
    'cli.deleted_n'             => 'Deleted {count} entries.',
    'cli.deleted_none'          => 'Nothing deleted: no entries match "{key}".',
    'cli.unknown_command'       => 'Unknown command: {cmd}',
    'cli.pattern_not_supported' => 'get takes an exact key, not a pattern. Use "{bin} list <pattern>" to match glob patterns.',
    'store.did_you_mean'        => 'key not found; did you mean \'{key}\'?',
    'database.entry_unreadable_after_set' => 'Entry was written but is no longer readable (expired, deleted, or corrupt): {key}',
];
