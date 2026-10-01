<?php

/**
 * Czech translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: soubor nenalezen: {path}',
    'decoder.no_gd'          => 'candy-flip: vyžadováno ext-gd',
    'decoder.not_gif'        => 'candy-flip: není soubor GIF',
    'decoder.grid_too_large' => 'candy-flip: součin mřížky buněk překračuje maximum ({max})',
    'decoder.grid_too_small' => 'candy-flip: rozměry mřížky buněk musí být kladné',
    'decoder.truncated'      => 'candy-flip: zkrácený soubor GIF',
    'cli.usage'              => 'Použití: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: nelze najít composer autoload.php',
];
