<?php

/**
 * Spanish translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'uso: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: no se puede encontrar composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: opción desconocida --{flag}',
    'cli.bad_value'   => 'candy-gifview: valor no válido \'{value}\' para {flag} (se espera un número positivo; --speed también acepta Nfps o max)',
    'cli.too_many'    => 'candy-gifview: argumento inesperado \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} no admite valor',
];
