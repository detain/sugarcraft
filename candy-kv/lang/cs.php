<?php

/**
 * Czech translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Záznam byl zapsán, ale není již čitelný (vypršel, smazán nebo poškozen)',
    'store.cannot_read'         => 'Nelze číst soubor: {path}',
    'cli.usage_set'             => 'Použití: {bin} set <klíč> [hodnota] [--ttl SECONDS]',
    'cli.usage_get'             => 'Použití: {bin} get <klíč>',
    'cli.usage_delete'          => 'Použití: {bin} delete <klíč>',
    'cli.usage_import'           => 'Použití: {bin} import <json|yaml|yml> <cesta>',
    'cli.usage_export'            => 'Použití: {bin} export <json|yaml|yml> [databáze] [vzor]',
    'cli.deleted_n'             => 'Smazáno {count} položek.',
    'cli.unknown_command'       => 'Neznámý příkaz: {cmd}',
    'cli.deleted_none'           => 'Nic smazáno nebylo: žádné záznamy neodpovídají "{key}".',
    'cli.pattern_not_supported'  => 'get očekává přesný klíč, nikoli vzor. Pro glob vzory použijte "{bin} list <pattern>".',
    'store.did_you_mean'         => 'klíč nenalezen; mysleli jste \'{key}\'?',
    'database.query_failed'      => 'Dotaz do databáze selhal.',
];
