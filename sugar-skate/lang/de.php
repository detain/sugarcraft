<?php

/**
 * German translations for sugar-skate.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Eintrag gesetzt aber nicht lesbar',
    'store.cannot_read'         => 'Datei kann nicht gelesen werden: {path}',

    // bin/skate
    'cli.usage_set'             => 'Aufruf: {bin} set <schlüssel> [wert]',
    'cli.usage_get'             => 'Aufruf: {bin} get <schlüssel>',
    'cli.usage_delete'          => 'Aufruf: {bin} delete <schlüssel>',
    'cli.usage_import'           => 'Aufruf: {bin} import <json|yaml> <pfad>',
    'cli.usage_export'            => 'Aufruf: {bin} export <json|yaml> [db] [muster]',
    'cli.deleted_n'             => '{count} Einträge gelöscht.',
    'cli.unknown_command'       => 'Unbekannter Befehl: {cmd}',
];
