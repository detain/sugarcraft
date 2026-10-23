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
    'cli.usage_set'             => 'Aufruf: {bin} set <schlüssel> [wert] [--ttl SECONDS]',
    'cli.usage_get'             => 'Aufruf: {bin} get <schlüssel>',
    'cli.usage_delete'          => 'Aufruf: {bin} delete <schlüssel>',
    'cli.usage_import'           => 'Aufruf: {bin} import <json|yaml|yml> <pfad>',
    'cli.usage_export'            => 'Aufruf: {bin} export <json|yaml|yml> [db] [muster]',
    'cli.deleted_n'             => '{count} Einträge gelöscht.',
    'cli.unknown_command'       => 'Unbekannter Befehl: {cmd}',
    'cli.deleted_none'           => 'Nichts gelöscht: keine Einträge passen zu "{key}".',
    'cli.pattern_not_supported'  => 'get erwartet einen exakten Schlüssel, kein Muster. Verwende "{bin} list <pattern>" für Glob-Muster.',
    'store.did_you_mean'         => 'Schlüssel nicht gefunden; meinst du \'{key}\'?',
    'database.query_failed'      => 'Die Datenbankabfrage ist fehlgeschlagen.',
];
