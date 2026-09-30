<?php

/**
 * Polish translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec niedostępne; wymagane ext-pcntl',
    'launcher.exec_failed'     => 'wykonanie {bin} nie powiodło się',
    'config.not_found'         => 'Nie znaleziono konfiguracji wishlist: {path}',
    'config.json_top_level'    => 'wishlist json: wartość najwyższego poziomu musi być tablicą',
    'config.json_entry_object' => 'wishlist json: każdy wpis musi być obiektem',
    'config.yaml_continuation' => "wishlist yaml: Continuation przed dowolnym blokiem '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: nierozpoznawalna linia: {line}',
    'config.entry_missing_field' => 'wpis wishlist z brakującym wymaganym polem: name lub host',
    'config.field_not_scalar'  => 'wpis wishlist: pole [{field}] musi być skalarem, otrzymano {type}',

    // endpoint security
    'endpoint.option_injection' => 'punkt końcowy wishlist: pole [{field}] zawiera niebezpieczną wartość zaczynającą się od myślnika [{value}]',
    'endpoint.port_invalid'     => 'punkt końcowy wishlist [{host}]: nieprawidłowy port [{port}] — musi to być liczba całkowita od 1 do 65535',

    'cli.usage'         => 'Użycie: wishlist [--config <ścieżka>] [--ssh <ssh-binarny>]',
    'cli.unknown_arg'   => 'wishlist: nieznany argument: {arg}',
    'cli.no_config'     => 'wishlist: nie znaleziono konfiguracji. Podaj --config <ścieżka>.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: anulowano.',
    'cli.ssh_not_executable' => 'wishlist: plik binarny ssh nie znaleziony lub wykonywalny: {bin}',
];
