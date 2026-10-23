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
    'cli.usage_set'             => 'Użycie: {bin} set <klucz> [wartość] [--ttl SECONDS]',
    'cli.usage_get'             => 'Użycie: {bin} get <klucz>',
    'cli.usage_delete'          => 'Użycie: {bin} delete <klucz>',
    'cli.usage_import'           => 'Użycie: {bin} import <json|yaml|yml> <ścieżka>',
    'cli.usage_export'            => 'Użycie: {bin} export <json|yaml|yml> [baza] [wzorzec]',
    'cli.deleted_n'             => 'Usunięto {count} wpisów.',
    'cli.unknown_command'       => 'Nieznane polecenie: {cmd}',
    'cli.deleted_none'           => 'Nic nie usunięto: żadne wpisy nie pasują do "{key}".',
    'cli.pattern_not_supported'  => 'get wymaga dokładnego klucza, nie wzorca. Użyj "{bin} list <pattern>", aby dopasować wzorce glob.',
    'store.did_you_mean'         => 'nie znaleziono klucza; czy chodziło o \'{key}\'?',
    'database.query_failed'      => 'Zapytanie do bazy danych nie powiodło się.',
];
