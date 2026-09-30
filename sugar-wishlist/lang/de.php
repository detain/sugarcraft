<?php

/**
 * German translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec nicht verfügbar; ext-pcntl erforderlich',
    'launcher.exec_failed'     => 'Ausführung von {bin} fehlgeschlagen',
    'config.not_found'         => 'Wishlist-Konfiguration nicht gefunden: {path}',
    'config.json_top_level'    => 'Wishlist-JSON: Oberster Wert muss ein Array sein',
    'config.json_entry_object' => 'Wishlist-JSON: Jeder Eintrag muss ein Objekt sein',
    'config.yaml_continuation' => "Wishlist-YAML: Fortsetzung vor jedem '- name:'-Block",
    'config.yaml_unparseable'  => 'Wishlist-YAML: nicht analysierbare Zeile: {line}',
    'config.entry_missing_field' => 'Wishlist-Eintrag mit fehlendem Pflichtfeld: name oder host',
    'config.field_not_scalar'  => 'wishlist-Eintrag: Feld [{field}] muss ein Skalar sein, erhalten {type}',

    // endpoint security
    'endpoint.option_injection' => 'wishlist-Endpunkt: Feld [{field}] enthält unsicheren Wert mit führendem Bindestrich [{value}]',
    'endpoint.port_invalid'     => 'wishlist-Endpunkt [{host}]: ungültiger Port [{port}] — muss eine Ganzzahl von 1 bis 65535 sein',


    // bin/wishlist
    'cli.usage'         => 'Aufruf: wishlist [--config <pfad>] [--ssh <ssh-binär>]',
    'cli.unknown_arg'   => 'wishlist: unbekanntes Argument: {arg}',
    'cli.no_config'     => 'wishlist: keine Konfiguration gefunden. Übergeben Sie --config <pfad>.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: abgebrochen.',
    'cli.ssh_not_executable' => 'wishlist: ssh-Binary nicht gefunden oder nicht ausführbar: {bin}',
];
