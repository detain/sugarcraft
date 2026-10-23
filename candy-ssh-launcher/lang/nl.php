<?php

/**
 * Dutch translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'uitvoeren van {bin} mislukt',
    'config.not_found'         => 'wishlist-configuratie niet gevonden: {path}',
    'config.json_top_level'    => 'wishlist json: bovenste waarde moet een array zijn',
    'config.json_entry_object' => 'wishlist json: elke entry moet een object zijn',
    'config.yaml_continuation' => "wishlist yaml: Continuation voor elk '- name:'-blok",
    'config.yaml_unparseable'  => 'wishlist yaml: onleesbare regel: {line}',
    'config.entry_missing_field' => 'wishlist-entry met ontbrekend verplicht veld: name of host',
    'config.field_not_scalar'  => 'wishlist-invoer: veld [{field}] moet een scalair zijn, ontvangen {type}',
    'config.unreadable'       => 'wishlist-configuratie kon niet worden gelezen: {path}',
    'config.home_unset'       => 'kan ~ niet uitvouwen zonder $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: inline flow-verzameling niet ondersteund: {value} (gebruik een geneste lijst)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist-endpoint: veld [{field}] heeft een onveilige waarde met een koppelteken aan het begin [{value}]',
    'endpoint.port_invalid'     => 'wishlist-endpoint [{host}]: ongeldige poort [{port}] — moet een geheel getal zijn van 1 tot 65535',

    'cli.usage'         => 'Gebruik: candy-ssh-launcher [--config <pad>] [--ssh <ssh-binair>] [--list] [-h|--help]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: onbekend argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: geen configuratie gevonden. Geef --config <pad> door.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: geannuleerd.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh-binary niet gevonden of niet uitvoerbaar: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} vereist een waarde',
    'cli.no_endpoints'   => 'candy-ssh-launcher: geen endpoints in {path}',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filter: ',
    'picker.no_matches'  => '  (geen treffers)',
    'picker.help'        => '  ↑/↓ kiezen · Enter verbinden · Esc afsluiten · typ om te filteren',
    'picker.count'       => '  ({shown} van {total})',
];
