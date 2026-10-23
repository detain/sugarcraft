<?php

/**
 * Dutch translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Item is geschreven maar niet meer leesbaar (verlopen, verwijderd of corrupt)',
    'store.cannot_read'         => 'Kan bestand niet lezen: {path}',
    'cli.usage_set'             => 'Gebruik: {bin} set <key> [waarde] [--ttl SECONDS]',
    'cli.usage_get'             => 'Gebruik: {bin} get <key>',
    'cli.usage_delete'          => 'Gebruik: {bin} delete <key>',
    'cli.usage_import'           => 'Gebruik: {bin} import <json|yaml|yml> <pad>',
    'cli.usage_export'            => 'Gebruik: {bin} export <json|yaml|yml> [db] [patroon]',
    'cli.deleted_n'             => '{count} entries verwijderd.',
    'cli.unknown_command'       => 'Onbekend commando: {cmd}',
    'cli.deleted_none'           => 'Niets verwijderd: geen items komen overeen met "{key}".',
    'cli.pattern_not_supported'  => 'get verwacht een exacte sleutel, geen patroon. Gebruik "{bin} list <pattern>" voor glob-patronen.',
    'store.did_you_mean'         => 'sleutel niet gevonden; bedoelde je \'{key}\'?',
    'database.query_failed'      => 'De databasequery is mislukt.',
];
