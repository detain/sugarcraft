<?php

/**
 * Polish translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'wykonanie {bin} nie powiodło się',
    'config.not_found'         => 'Nie znaleziono konfiguracji wishlist: {path}',
    'config.json_top_level'    => 'wishlist json: wartość najwyższego poziomu musi być tablicą',
    'config.json_entry_object' => 'wishlist json: każdy wpis musi być obiektem',
    'config.yaml_continuation' => "wishlist yaml: Continuation przed dowolnym blokiem '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: nierozpoznawalna linia: {line}',
    'config.entry_missing_field' => 'wpis wishlist z brakującym wymaganym polem: name lub host',
    'config.field_not_scalar'  => 'wpis wishlist: pole [{field}] musi być skalarem, otrzymano {type}',
    'config.unreadable'       => 'nie można odczytać konfiguracji wishlist: {path}',
    'config.home_unset'       => 'nie można rozwinąć ~ bez $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: kolekcja flow w linii nie jest obsługiwana: {value} (użyj listy zagnieżdżonej)',

    // endpoint security
    'endpoint.option_injection' => 'punkt końcowy wishlist: pole [{field}] zawiera niebezpieczną wartość zaczynającą się od myślnika [{value}]',
    'endpoint.port_invalid'     => 'punkt końcowy wishlist [{host}]: nieprawidłowy port [{port}] — musi to być liczba całkowita od 1 do 65535',

    'cli.usage'         => 'Użycie: candy-ssh-launcher [--config <ścieżka>] [--ssh <ssh-binarny>] [--list] [-h|--help]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: nieznany argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: nie znaleziono konfiguracji. Podaj --config <ścieżka>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: anulowano.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: plik binarny ssh nie znaleziony lub wykonywalny: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} wymaga wartości',
    'cli.no_endpoints'   => 'candy-ssh-launcher: brak endpointów w {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtr: ',
    'picker.no_matches'  => '  (brak dopasowań)',
    'picker.help'        => '  ↑/↓ wybierz · Enter połącz · Esc wyjdź · pisz, aby filtrować',
    'picker.count'       => '  ({shown} z {total})',
];
