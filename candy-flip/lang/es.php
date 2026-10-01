<?php

/**
 * Spanish translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: archivo no encontrado: {path}',
    'decoder.no_gd'          => 'candy-flip: se requiere ext-gd',
    'decoder.not_gif'        => 'candy-flip: no es un GIF',
    'decoder.grid_too_large' => 'candy-flip: el producto de la cuadrícula excede el máximo ({max})',
    'decoder.grid_too_small' => 'candy-flip: las dimensiones de la cuadrícula deben ser positivas',
    'decoder.truncated'      => 'candy-flip: GIF truncado',
    'cli.usage'              => 'uso: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: no se puede encontrar composer autoload.php',
];
