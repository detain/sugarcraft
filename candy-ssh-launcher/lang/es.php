<?php

/**
 * Spanish translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec no disponible; se requiere ext-pcntl',
    'launcher.exec_failed'     => 'falló la ejecución de {bin}',
    'config.not_found'         => 'Configuración de wishlist no encontrada: {path}',
    'config.json_top_level'    => 'wishlist json: el valor de nivel superior debe ser un array',
    'config.json_entry_object' => 'wishlist json: cada entrada debe ser un objeto',
    'config.yaml_continuation' => "wishlist yaml: continuación antes de cualquier bloque '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: línea no analizable: {line}',
    'config.entry_missing_field' => 'entrada wishlist sin campo requerido: name o host',
    'config.field_not_scalar'  => 'entrada de wishlist: el campo [{field}] debe ser un escalar, se obtuvo {type}',

    // endpoint security
    'endpoint.option_injection' => 'endpoint de wishlist: el campo [{field}] tiene un valor no seguro con guion inicial [{value}]',
    'endpoint.port_invalid'     => 'endpoint de wishlist [{host}]: puerto no válido [{port}] — debe ser un entero de 1 a 65535',


    // bin/candy-ssh-launcher
    'cli.usage'         => 'Uso: candy-ssh-launcher [--config <ruta>] [--ssh <binario-ssh>]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: argumento desconocido: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: no se encontró configuración. Pase --config <ruta>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: cancelado.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binario ssh no encontrado o no ejecutable: {bin}',
];
