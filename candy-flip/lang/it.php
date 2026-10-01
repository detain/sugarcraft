<?php

/**
 * Italian translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: file non trovato: {path}',
    'decoder.no_gd'          => 'candy-flip: ext-gd richiesto',
    'decoder.not_gif'        => 'candy-flip: non è un GIF',
    'decoder.grid_too_large' => 'candy-flip: il prodotto della griglia supera il massimo ({max})',
    'decoder.grid_too_small' => 'candy-flip: le dimensioni della griglia devono essere positive',
    'decoder.truncated'      => 'candy-flip: GIF troncato',
    'cli.usage'              => 'uso: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: impossibile trovare composer autoload.php',
];
