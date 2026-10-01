<?php

/**
 * Dutch translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: bestand niet gevonden: {path}',
    'decoder.no_gd'          => 'candy-flip: ext-gd vereist',
    'decoder.not_gif'        => 'candy-flip: geen GIF-bestand',
    'decoder.grid_too_large' => 'candy-flip: celraster-product overschrijdt maximum ({max})',
    'decoder.grid_too_small' => 'candy-flip: afmetingen van celraster moeten positief zijn',
    'decoder.truncated'      => 'candy-flip: afgebroken GIF',
    'cli.usage'              => 'Gebruik: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: kan composer autoload.php niet vinden',
];
