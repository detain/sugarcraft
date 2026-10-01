<?php

/**
 * Polish translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'          => 'candy-flip: nie znaleziono pliku: {path}',
    'decoder.no_gd'            => 'candy-flip: wymagane ext-gd',
    'decoder.not_gif'          => 'candy-flip: to nie jest plik GIF',
    'decoder.grid_too_large'   => 'candy-flip: iloczyn siatki komórek przekracza maksimum ({max})',
    'decoder.grid_too_small'   => 'candy-flip: wymiary siatki komórek muszą być dodatnie',
    'decoder.truncated'        => 'candy-flip: skrócony plik GIF',
    'decoder.screen_too_large' => 'candy-flip: wymiary ekranu GIF przekraczają maksimum ({max} pikseli)',
    'cli.usage'                => 'Użycie: candy-flip <gif> [solid|density]',
    'cli.no_autoload'          => 'candy-flip: nie można znaleźć composer autoload.php',
];
