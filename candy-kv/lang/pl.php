<?php

/**
 * Polish translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => 'Wpis został zapisany, ale nie jest już czytelny (wygasły, usunięty lub uszkodzony)',
    'store.cannot_read'         => 'Nie można odczytać pliku: {path}',
    'cli.usage_set'             => 'Użycie: {bin} set <klucz> [wartość] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => 'Użycie: {bin} get <klucz> [--sanitize]',
    'cli.usage_delete'          => 'Użycie: {bin} delete <klucz>',
    'cli.usage_import'           => 'Użycie: {bin} import <json|yaml|yml> <ścieżka> [--no-atomic]',
    'cli.usage_export'            => 'Użycie: {bin} export <json|yaml|yml> [baza] [wzorzec]',
    'cli.deleted_n'             => 'Usunięto {count} wpisów.',
    'cli.unknown_command'       => 'Nieznane polecenie: {cmd}',
    'cli.deleted_none'           => 'Nic nie usunięto: żadne wpisy nie pasują do "{key}".',
    'cli.pattern_not_supported'  => 'get wymaga dokładnego klucza, nie wzorca. Użyj "{bin} list <pattern>", aby dopasować wzorce glob.',
    'store.did_you_mean'         => 'nie znaleziono klucza; czy chodziło o \'{key}\'?',
    'database.query_failed'      => 'Zapytanie do bazy danych nie powiodło się.',
    'cli.key_not_found'          => 'nie znaleziono klucza: {key}',
    'cli.deleted_one'            => 'Usunięto 1 wpis.',
    'cli.imported_one'           => 'Zaimportowano 1 wpis.',
    'cli.imported_n'             => 'Zaimportowano {count} wpisów.',
    'cli.would_delete_one'       => '1 wpis zostałby usunięty.',
    'cli.would_delete_n'         => '{count} wpisów zostałoby usuniętych.',
    'cli.pruned_n'               => 'Usunięto {count} wygasłych wpisów.',
    'cli.pruned_one'             => 'Usunięto 1 wygasły wpis.',
    'cli.pruned_none'            => 'Brak wygasłych wpisów do usunięcia.',
];
