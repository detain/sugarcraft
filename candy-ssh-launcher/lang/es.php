<?php

/**
 * Spanish translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'falló la ejecución de {bin}',
    'launcher.transport_not_executable' => 'binario de transporte no encontrado o no ejecutable: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: endpoint {endpoint}: el transporte personalizado omite {legs}',
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
    'config.too_large'          => 'la configuración de la lista supera el límite de {limit} bytes: {path}',
    'config.list_too_long'      => 'entrada de la lista: el campo [{field}] contiene más de los {limit} elementos que permite el cargador',
    'config.field_not_list'       => 'entrada de la lista: el campo [{field}] debe ser una lista de valores, no un mapa clave-valor',
    'config.encoding_utf16'       => '{path} está codificado en UTF-16 — vuelve a guardar la configuración como UTF-8',
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
    'cli.usage'         => "Uso: candy-ssh-launcher [--config <ruta>] [--ssh <ruta>] [--from-ssh-config] [--list] [-h|--help] [-v|--version] [--] [endpoint]\nTeclas: las flechas mueven (también j/k con el filtro vacío), escribir filtra, Enter conecta, Ctrl+U limpia la línea, Ctrl+W borra una palabra, Ctrl+H borra atrás, Esc cancela\nCódigos de salida: 0 lanzado o listado, 1 sin configuración, configuración rota o binario rechazado, 2 opción o argumento desconocido, o sin terminal, 3 endpoint rechazado al lanzar, 130 cancelado\n¿Sin terminal interactivo? --list imprime todos los endpoints, y un endpoint nombrado exactamente se lanza igual.\nUn -- a secas termina el análisis de opciones; lo que sigue se ignora — nombre el endpoint antes.",
    'cli.unknown_arg'   => 'candy-ssh-launcher: argumento desconocido: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: no se encontró configuración. Cree ~/.config/wishlist.yml (consulte el inicio rápido del README) o pase --config <ruta>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: cancelado.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binario ssh no encontrado o no ejecutable: {bin} — pase --ssh <ruta> si ssh está en otra ruta',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} requiere un valor',
    'cli.no_endpoints'   => 'candy-ssh-launcher: sin endpoints en {path}',
    'cli.not_a_terminal' => 'el selector necesita una terminal interactiva: stdin y stdout deben ser TTY. Usa --list para salida legible por máquina.',
    'cli.config_unowned'   => 'candy-ssh-launcher: configuración rechazada por pertenecer a otro usuario: {path} — la configuración es entrada de confianza; consulte Seguridad en el README.',
    'cli.config_writable'  => 'candy-ssh-launcher: configuración rechazada por ser escribible por el grupo o por todos: {path} — restrínjala a 0644 u 0600, o pásela explícitamente con --config.',
    'cli.project_local'    => 'candy-ssh-launcher: wishlist local del proyecto en uso: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: el endpoint solicita -o {option} — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => 'la verificación de claves de host está degradada: la clave del servidor puede quedar sin verificar',
    'cli.unsafe_option_effect_user_known_hosts_file' => 'la base de datos de confianza de hosts conocidos del usuario es redirigida',
    'cli.unsafe_option_effect_global_known_hosts_file' => 'la base de confianza del sistema es redirigida',
    'cli.unsafe_option_effect_log_level' => 'las advertencias y avisos de ssh quedan ocultos',
    'cli.unsafe_option_effect_identity_agent' => 'la autenticación se delega a otro socket de agente',
    'cli.unsafe_option_effect_certificate_file' => 'se ofrece un certificado de autenticación adicional',
    'cli.ssh_config_source'  => 'candy-ssh-launcher: importando endpoints desde la configuración de ssh: {path}',
    'cli.ssh_config_missing' => 'candy-ssh-launcher: no hay configuración de ssh en {path} — pase --config <path> con --from-ssh-config para indicar otro archivo',
    'cli.config_is_ssh_config' => 'candy-ssh-launcher: {path} parece una configuración de OpenSSH, no un archivo wishlist — impórtela con --from-ssh-config (opcionalmente con --config <path>)',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtro: ',
    'picker.no_matches'  => '  (sin coincidencias — Retroceso o Esc)',
    'picker.help'        => '↑/↓ seleccionar · Enter conectar · Esc salir · escribe para filtrar',
    'picker.help_keys'   => '⌫ borrar · Ctrl+U/W limpiar · RePag/AvPag página · Inicio/Fin saltar',
    'picker.count'       => '  ({shown} de {total})',
];
