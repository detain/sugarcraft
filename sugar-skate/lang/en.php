<?php

/**
 * English (default) translations for sugar-skate.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Entry set but not readable',
    'store.cannot_read'         => 'Cannot read file: {path}',

    // bin/skate
    'cli.usage_set'             => 'Usage: {bin} set <key> [value] [--ttl=SECONDS]',
    'cli.usage_get'             => 'Usage: {bin} get <key>',
    'cli.usage_delete'          => 'Usage: {bin} delete <key>',
    'cli.usage_import'          => 'Usage: {bin} import <json|yaml> <path>',
    'cli.usage_export'          => 'Usage: {bin} export <json|yaml> [db] [pattern]',
    'cli.deleted_n'             => 'Deleted {count} entries.',
    'cli.unknown_command'       => 'Unknown command: {cmd}',
    'cli.import_success'        => 'Imported {count} entries.',
    'cli.export_success'        => 'Export complete.',
];
