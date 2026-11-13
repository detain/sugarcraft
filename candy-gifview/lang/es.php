<?php

/**
 * Spanish translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'uso: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--paused] [--help|-h] [--version|-v]',
    'cli.no_autoload' => 'candy-gifview: no se puede encontrar composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: opción desconocida --{flag}',
    'cli.empty_flag'  => 'candy-gifview: falta el nombre de opción tras --',
    'cli.bad_value'   => 'candy-gifview: valor no válido \'{value}\' para {flag} (se espera un número positivo)',
    'cli.too_many'    => 'candy-gifview: argumento inesperado \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} no admite valor',
    'cli.bad_preset' => 'candy-gifview: preset desconocido \'{preset}\' (espera solid o density)',
    'cli.no_frames' => 'candy-gifview: no hay fotogramas que reproducir en \'{path}\'',
    'cli.cannot_play' => 'candy-gifview: no se pudo reproducir \'{path}\': {reason}',
    'cli.reason_frames_over' => 'la animación supera el límite de {max} fotogramas; carga rechazada',
    'cli.reason_bytes_over' => 'el archivo supera el límite de {max} MiB',
    'cli.reason_cells_over' => 'la animación necesita más del presupuesto de {max} celdas para esta ventana',
    'cli.duration_static' => 'candy-gifview: --duration necesita al menos dos fotogramas; este archivo se decodifica a {count}',
    'cli.need_gd' => 'candy-gifview: ext-gd es obligatorio para decodificar GIF (instale php-gd)',
    'cli.decoding' => 'candy-gifview: decodificando \'{path}\'...',
    'cli.truncated' => 'candy-gifview: mostrando los primeros {max} fotogramas — este visor no lee animaciones más largas',
];
