<?php

/**
 * German translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Eintrag wurde geschrieben, ist aber nicht mehr lesbar (abgelaufen, gelöscht oder beschädigt)',
    'store.cannot_read'         => 'Datei kann nicht gelesen werden: {path}',

    // bin/candy-kv
    'cli.usage_set'             => 'Aufruf: {bin} set <schlüssel> [wert] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Aufruf: {bin} get <schlüssel> [--sanitize]',
    'cli.usage_delete'          => 'Aufruf: {bin} delete <schlüssel>',
    'cli.usage_import'           => 'Aufruf: {bin} import <json|yaml|yml> <pfad> [--no-atomic] [--if-exists=MODE] [--max-bytes=N]',
    'cli.usage_export'            => 'Aufruf: {bin} export <json|yaml|yml> [db] [muster]',
    'cli.deleted_n'             => '{count} Einträge gelöscht.',
    'cli.unknown_command'       => 'Unbekannter Befehl: {cmd}',
    'cli.deleted_none'           => 'Nichts gelöscht: keine Einträge passen zu "{key}".',
    'cli.dryrun_none'            => 'Nichts würde gelöscht: keine Einträge passen zu "{key}".',
    'cli.pattern_not_supported'  => 'get erwartet einen exakten Schlüssel, kein Muster. Verwende "{bin} list <pattern>" für Glob-Muster.',
    'cli.db_deleted'             => 'Datenbank "{name}" gelöscht.',
    'cli.db_not_found'           => 'Datenbank nicht gefunden: {db}',
    'cli.db_did_you_mean'        => 'Meinst du die Datenbank \'{db}\'?',
    'store.did_you_mean'         => 'Schlüssel nicht gefunden; meinst du \'{key}\'?',
    'cli.key_not_found'          => 'Schlüssel nicht gefunden: {key}',
    'cli.deleted_one'            => '1 Eintrag gelöscht.',
    'cli.imported_one'           => '1 Eintrag importiert.',
    'cli.imported_n'             => '{count} Einträge importiert.',
    'cli.overwrote_one'          => '1 Eintrag überschrieben.',
    'cli.overwrote_n'            => '{count} Einträge überschrieben.',
    'cli.would_delete_one'       => '1 Eintrag würde gelöscht.',
    'cli.would_delete_n'         => '{count} Einträge würden gelöscht.',
    'cli.pruned_n'               => '{count} abgelaufene Einträge entfernt.',
    'cli.pruned_one'             => '1 abgelaufener Eintrag entfernt.',
    'cli.pruned_none'            => 'Keine abgelaufenen Einträge zum Entfernen.',
];
