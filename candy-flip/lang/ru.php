<?php

/**
 * Russian translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'        => 'candy-flip: файл не найден: {path}',
    'decoder.no_gd'          => 'candy-flip: требуется ext-gd',
    'decoder.not_gif'        => 'candy-flip: не GIF-файл',
    'decoder.grid_too_large' => 'candy-flip: произведение сетки ячеек превышает максимум ({max})',
    'decoder.grid_too_small' => 'candy-flip: размеры сетки ячеек должны быть положительными',
    'decoder.truncated'      => 'candy-flip: усечённый GIF',
    'cli.usage'              => 'Использование: candy-flip <gif> [solid|density]',
    'cli.no_autoload'        => 'candy-flip: невозможно найти composer autoload.php',
];
