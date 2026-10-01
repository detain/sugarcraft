<?php

/**
 * Brazilian Portuguese translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'          => 'candy-flip: arquivo não encontrado: {path}',
    'decoder.no_gd'            => 'candy-flip: ext-gd é necessária',
    'decoder.not_gif'          => 'candy-flip: não é um GIF',
    'decoder.grid_too_large'   => 'candy-flip: o produto da grade de células excede o máximo ({max})',
    'decoder.grid_too_small'   => 'candy-flip: as dimensões da grade devem ser positivas',
    'decoder.truncated'        => 'candy-flip: GIF truncado',
    'decoder.screen_too_large' => 'candy-flip: as dimensões da tela do GIF excedem o máximo ({max} pixels)',
    'cli.usage'                => 'uso: candy-flip <gif> [solid|density]',
    'cli.no_autoload'          => 'candy-flip: não é possível localizar composer autoload.php',
];
