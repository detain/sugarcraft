<?php

/**
 * Spanish translations for sugar-markdown.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'theme.read_failed'            => 'no se pudo leer el archivo de tema: {path}',
    'theme.json_invalid'           => 'JSON no válido en el archivo de tema',
    'theme.json_object'            => 'el JSON del tema debe decodificar a un objeto',
    'theme.bad_color'              => 'especificación de color no válida: {spec}',
    'renderer.unknown_style'       => 'estilo estándar desconocido: {name}',
    'renderer.stream_invalid'      => 'write() requiere un recurso de flujo',
    'renderer.stream_not_writable' => 'write() requiere un flujo escribible; se obtuvo el modo "{mode}"',
    'renderer.write_failed'        => 'no se pudieron escribir {bytes} bytes en el flujo de salida',
    'renderer.chunk_invalid'       => 'los fragmentos de stream() deben ser cadenas',
    'sink.closed'                  => 'el sink está cerrado',
    'sink.parent_missing'          => 'el directorio de salida no existe: {dir}',
    'sink.path_unwritable'         => 'la ruta de salida no es escribible: {path}',
    'sink.open_failed'             => 'no se pudo abrir la ruta de salida: {path}',
    'sink.flush_failed'            => 'no se pudo vaciar el flujo de salida',
    'writer.feed_after_close'      => 'feed() llamado después de close() — el writer ha terminado',
];
