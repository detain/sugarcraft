<?php

/**
 * Czech translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec není dostupný; vyžadováno ext-pcntl',
    'launcher.exec_failed'     => 'spuštění {bin} selhalo',
    'config.not_found'         => 'Konfigurace wishlist nenalezena: {path}',
    'config.json_top_level'    => 'wishlist json: hodnota nejvyšší úrovně musí být pole',
    'config.json_entry_object' => 'wishlist json: každá položka musí být objekt',
    'config.yaml_continuation' => "wishlist yaml: pokračování před jakýmkoliv blokem '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: neparsovatelný řádek: {line}',
    'config.entry_missing_field' => 'wishlist položka s chybějícím povinným polem: name nebo host',
    'config.field_not_scalar'  => 'položka wishlist: pole [{field}] musí být skalár, získáno {type}',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist: pole [{field}] má nebezpečnou hodnotu začínající pomlčkou [{value}]',
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}]: neplatný port [{port}] — musí to být celé číslo od 1 do 65535',

    'cli.usage'         => 'Použití: candy-ssh-launcher [--config <cesta>] [--ssh <ssh-binární>]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: neznámý argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: konfigurace nenalezena. Předejte --config <cesta>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: zrušeno.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binární soubor ssh nenalezen nebo není spustitelný: {bin}',
];
