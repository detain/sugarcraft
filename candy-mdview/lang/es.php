<?php

/**
 * Spanish translations for candy-mdview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'render.unknown_theme' => 'tema desconocido: {name}',
    'render.theme_config_unreadable' => 'configuración del tema no legible: {path}',
    'render.theme_config_too_large' => 'la configuración del tema supera los {limit} bytes: {path}',
    'render.theme_config_unrecognized' => 'la configuración del tema no coincide con el esquema plano ni con el de glamour: {path}',
    'glamour.json_invalid' => 'la configuración del tema no es un JSON válido',
    'glamour.json_object' => 'la configuración del tema debe ser un objeto JSON',
    'glamour.decoration_unsafe' => 'el campo {slot} del tema solo puede contener saltos de línea, tabulaciones y espacios',
    'glamour.value_too_large' => 'la clave {key} del tema supera el máximo de {limit}',
    'render.no_input_tty' => 'sin entrada: indique un archivo, envíe Markdown por la entrada estándar o ejecute con --help',
    'render.no_input_empty' => 'sin entrada: la entrada estándar está vacía',
    'render.pager_needs_tty' => 'el paginador necesita una terminal; renderizad a la salida estándar sin -p',
    'render.file_missing' => 'no se puede abrir {path}: el archivo no existe',
    'render.file_is_directory' => 'no se puede abrir {path}: es un directorio',
    'render.file_unreadable' => 'no se puede abrir {path}: no se puede leer',
    'render.binary_input' => 'se rechaza renderizar {path}: no es un archivo de texto',
    'render.input_too_large' => 'la entrada supera los {limit} bytes: {path}',
    'render.fetch_failed' => 'no se puede obtener {url}: {reason}',
    'render.directory_empty' => 'no hay documento Markdown en {path}: se buscó README.md, README.markdown, index.md o un único *.md',
    'render.directory_ambiguous' => 'no hay un único documento en {path}: se encontró {found} — indique uno explícitamente',
    'render.directory_chosen' => 'renderizando {chosen} del argumento de directorio {path}',
    'render.width_invalid' => 'valor de --width no válido: {value} (se esperaba un entero entre 0 y 9999; 0 desactiva el ajuste de línea)',
];
