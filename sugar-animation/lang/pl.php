<?php

/**
 * Polish translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation: nie znaleziono pliku: {path}',
    'decoder.no_gd'              => 'sugar-animation: zainstaluj rozszerzenie GD (ext-gd) aby dekodować GIF-y - odtwarzanie sztuki .ansi go nie wymaga',
    'decoder.not_gif'            => 'sugar-animation: to nie jest plik GIF',
    'decoder.unreadable'         => 'sugar-animation: nie można odczytać pliku: {path}',
    'decoder.input_too_large'    => 'sugar-animation: plik jest zbyt duży (maksymalnie {max})',
    'decoder.not_a_file' => 'sugar-animation: to nie jest plik: {path}',
    'decoder.too_many_frames' => 'sugar-animation: zbyt wiele klatek (maksymalnie {max})',
    'decoder.total_cells_too_large' => 'sugar-animation: łączny limit komórek przekroczony (maksymalnie {max} komórek)',
    'decoder.grid_too_large'     => 'sugar-animation: siatka komórek zbyt duża (maksymalnie {max} komórek)',
    'decoder.grid_too_small'     => 'sugar-animation: wymiary siatki komórek muszą być dodatnie',
    'decoder.truncated'          => 'sugar-animation: skrócony plik GIF',
    'decoder.screen_too_large'   => 'sugar-animation: wymiary ekranu GIF przekraczają maksimum ({max} pikseli)',
    'decoder.screen_too_small'   => 'sugar-animation: wymiary ekranu GIF muszą być dodatnie',
    'artlibrary.not_a_directory' => 'sugar-animation: to nie katalog: {dir}',
    'artlibrary.cannot_list'     => 'sugar-animation: nie można wyświetlić zawartości katalogu: {dir}',
    'ansiart.cannot_read'        => 'sugar-animation: nie można odczytać pliku sztuki: {path}',
    'ansiart.read_failed'        => 'sugar-animation: odczyt pliku sztuki nie powiódł się: {path}',
];
