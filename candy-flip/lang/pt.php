<?php

/**
 * Portuguese translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: ficheiro não encontrado: {path}',
    'decoder.no_gd'          => 'candy-flip: ext-gd é necessário',
    'decoder.not_gif'        => 'candy-flip: não é um GIF',
    'decoder.grid_too_large' => 'candy-flip: o produto da grelha de células excede o máximo ({max})',
    'decoder.grid_too_small' => 'candy-flip: as dimensões da grelha devem ser positivas',
    'decoder.truncated'      => 'candy-flip: GIF truncado',
    'cli.usage'              => 'uso: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: não é possível localizar composer autoload.php',
];
