<?php

/**
 * Spanish translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'falló la ejecución de {bin}',
    'config.not_found'         => 'Configuración de wishlist no encontrada: {path}',
    'config.json_top_level'    => 'wishlist json: el valor de nivel superior debe ser un array',
    'config.json_entry_object' => 'wishlist json: cada entrada debe ser un objeto',
    'config.yaml_continuation' => "wishlist yaml: continuación antes de cualquier bloque '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: línea no analizable {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: mapeo de bloque bajo `{field}:` en la línea {line}: {found}; este campo debe ser una lista de elementos `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml: comilla sin cerrar en el valor: {found}',
    'config.entry_missing_field' => 'entrada de la wishlist #{index}: falta un campo obligatorio: {field}',
    'config.unknown_key' => 'campo desconocido `{key}` ignorado — no es una clave documentada',
    'config.unknown_key_nearmiss' => 'campo desconocido `{key}` ignorado — ¿quisiste decir `{field}`?',
    'config.duplicate_name' => 'nombre de endpoint duplicado `{name}` — todas las filas se cargan; los nombres son identificadores',
    'config.field_not_scalar'  => 'entrada de wishlist: el campo [{field}] debe ser un escalar, se obtuvo {type}',
    'config.unreadable'       => 'no se pudo leer la configuración de wishlist: {path}',
    'config.home_unset'       => 'no se puede expandir ~ sin $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: colección de flujo en línea no admitida: {value} (use una lista anidada)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint de wishlist: el campo [{field}] tiene un valor no seguro con guion inicial [{value}]',
    'endpoint.host_empty' => 'el endpoint `{name}` tiene un host vacío — una fila seleccionable necesita un nombre de host',
    'endpoint.name_empty' => 'el endpoint tiene un nombre vacío — una fila seleccionable necesita un nombre',
    'endpoint.option_empty' => 'el endpoint `{name}` tiene una entrada de opción vacía — un valor -o en blanco se rechaza',
    'endpoint.port_invalid'     => 'endpoint de wishlist [{host}]: puerto no válido [{port}] — debe ser un entero de 1 a 65535',


    // bin/candy-ssh-launcher
    'cli.usage'         => 'Uso: candy-ssh-launcher [--config <ruta>] [--ssh <binario-ssh>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: argumento desconocido: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: no se encontró configuración. Cree ~/.config/wishlist.yml (consulte el inicio rápido del README) o pase --config <ruta>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: cancelado.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binario ssh no encontrado o no ejecutable: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} requiere un valor',
    'cli.no_endpoints'   => 'candy-ssh-launcher: sin endpoints en {path}',
    'cli.not_a_terminal' => 'el selector necesita una terminal interactiva: stdin y stdout deben ser TTY. Usa --list para salida legible por máquina.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtro: ',
    'picker.no_matches'  => '  (sin coincidencias — Retroceso o Esc)',
    'picker.help'        => '↑/↓ seleccionar · Enter conectar · Esc salir · escribe para filtrar',
    'picker.help_keys'   => '⌫ borrar · Ctrl+U/W limpiar · RePag/AvPag página · Inicio/Fin saltar',
    'picker.count'       => '  ({shown} de {total})',
];
