<?php

/**
 * German translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: Datei nicht gefunden: {path}',
    'decoder.no_gd'          => 'candy-flip: ext-gd wird benötigt',
    'decoder.not_gif'        => 'candy-flip: keine GIF-Datei',
    'decoder.grid_too_large' => 'candy-flip: Zellraster-Produkt überschreitet Maximum ({max})',
    'decoder.grid_too_small' => 'candy-flip: Zellraster-Abmessungen müssen positiv sein',
    'decoder.truncated'      => 'candy-flip: unvollständige GIF-Datei',
    'cli.usage'              => 'Aufruf: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: composer autoload.php nicht gefunden',
];
