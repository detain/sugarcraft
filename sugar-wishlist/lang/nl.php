<?php

/**
 * Dutch translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec niet beschikbaar; ext-pcntl vereist',
    'launcher.exec_failed'     => 'uitvoeren van {bin} mislukt',
    'config.not_found'         => 'wishlist-configuratie niet gevonden: {path}',
    'config.json_top_level'    => 'wishlist json: bovenste waarde moet een array zijn',
    'config.json_entry_object' => 'wishlist json: elke entry moet een object zijn',
    'config.yaml_continuation' => "wishlist yaml: Continuation voor elk '- name:'-blok",
    'config.yaml_unparseable'  => 'wishlist yaml: onleesbare regel: {line}',
    'config.entry_missing_field' => 'wishlist-entry met ontbrekend verplicht veld: name of host',
    'config.field_not_scalar'  => 'wishlist-invoer: veld [{field}] moet een scalair zijn, ontvangen {type}',

    // endpoint security
    'endpoint.option_injection' => 'wishlist-endpoint: veld [{field}] heeft een onveilige waarde met een koppelteken aan het begin [{value}]',
    'endpoint.port_invalid'     => 'wishlist-endpoint [{host}]: ongeldige poort [{port}] — moet een geheel getal zijn van 1 tot 65535',

    'cli.usage'         => 'Gebruik: wishlist [--config <pad>] [--ssh <ssh-binair>]',
    'cli.unknown_arg'   => 'wishlist: onbekend argument: {arg}',
    'cli.no_config'     => 'wishlist: geen configuratie gevonden. Geef --config <pad> door.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: geannuleerd.',
    'cli.ssh_not_executable' => 'wishlist: ssh-binary niet gevonden of niet uitvoerbaar: {bin}',
];
