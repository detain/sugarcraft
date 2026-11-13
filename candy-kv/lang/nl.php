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
    'cli.usage_set'             => 'Gebruik: {bin} set <key> [waarde] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Gebruik: {bin} get <key> [--sanitize]',
    'cli.usage_delete'          => 'Gebruik: {bin} delete <key>',
    'cli.usage_import'           => 'Gebruik: {bin} import <json|yaml|yml> <pad> [--no-atomic] [--if-exists=MODE] [--max-bytes=N]',
    'cli.usage_export'            => 'Gebruik: {bin} export <json|yaml|yml> [db] [patroon]',
    'cli.deleted_n'             => '{count} items verwijderd.',
    'cli.unknown_command'       => 'Onbekend commando: {cmd}',
    'cli.deleted_none'           => 'Niets verwijderd: geen items komen overeen met "{key}".',
    'cli.dryrun_none'            => 'Er zou niets worden verwijderd: geen items komen overeen met "{key}".',
    'cli.pattern_not_supported'  => 'get verwacht een exacte sleutel, geen patroon. Gebruik "{bin} list <pattern>" voor glob-patronen.',
    'cli.db_deleted'             => 'Database "{name}" verwijderd.',
    'cli.db_not_found'           => 'Database niet gevonden: {db}',
    'cli.db_did_you_mean'        => 'Bedoelde je database \'{db}\'?',
    'store.did_you_mean'         => 'Sleutel niet gevonden; bedoelde je \'{key}\'?',
    'cli.key_not_found'          => 'Sleutel niet gevonden: {key}',
    'cli.deleted_one'            => '1 item verwijderd.',
    'cli.imported_one'           => '1 item geïmporteerd.',
    'cli.imported_n'             => '{count} items geïmporteerd.',
    'cli.overwrote_one'          => '1 item overschreven.',
    'cli.overwrote_n'            => '{count} items overschreven.',
    'cli.would_delete_one'       => '1 item zou worden verwijderd.',
    'cli.would_delete_n'         => '{count} items zouden worden verwijderd.',
    'cli.pruned_n'               => '{count} verlopen items verwijderd.',
    'cli.pruned_one'             => '1 verlopen item verwijderd.',
    'cli.pruned_none'            => 'Geen verlopen items om te verwijderen.',
];
