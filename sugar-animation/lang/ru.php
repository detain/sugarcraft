<?php

/**
 * Russian translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation: файл не найден: {path}',
    'decoder.no_gd'              => 'sugar-animation: установите расширение GD (ext-gd) для декодирования GIF — воспроизведение .ansi-арта он не требует',
    'decoder.not_gif'            => 'sugar-animation: не GIF-файл',
    'decoder.unreadable'         => 'sugar-animation: не удаётся прочитать файл: {path}',
    'decoder.input_too_large'    => 'sugar-animation: файл слишком большой (максимум {max})',
    'decoder.not_a_file' => 'sugar-animation: это не файл: {path}',
    'decoder.too_many_frames' => 'sugar-animation: слишком много кадров (максимум {max})',
    'decoder.total_cells_too_large' => 'sugar-animation: общий лимит ячеек превышен (максимум {max} ячеек)',
    'decoder.grid_too_large'     => 'sugar-animation: сетка ячеек слишком большая (максимум {max} ячеек)',
    'decoder.grid_too_small'     => 'sugar-animation: размеры сетки ячеек должны быть положительными',
    'decoder.truncated'          => 'sugar-animation: усечённый GIF',
    'decoder.screen_too_large'   => 'sugar-animation: размеры экрана GIF превышают максимум ({max} пикселей)',
    'decoder.screen_too_small'   => 'sugar-animation: размеры экрана GIF должны быть положительными',
    'artlibrary.not_a_directory' => 'sugar-animation: это не каталог: {dir}',
    'artlibrary.cannot_list'     => 'sugar-animation: не удаётся получить список каталога: {dir}',
    'ansiart.cannot_read'        => 'sugar-animation: не удаётся прочитать файл арта: {path}',
    'ansiart.read_failed'        => 'sugar-animation: сбой чтения файла арта: {path}',
];
