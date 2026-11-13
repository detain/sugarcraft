<?php

/**
 * English (default) translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'            => 'sugar-animation: no such file: {path}',
    'decoder.no_gd'              => 'sugar-animation: install the GD image extension (ext-gd) to decode GIFs - .ansi art playback does not need it',
    'decoder.not_gif'            => 'sugar-animation: not a GIF',
    'decoder.unreadable'         => 'sugar-animation: cannot read file: {path}',
    'decoder.input_too_large'    => 'sugar-animation: file is too large (maximum {max})',
    'decoder.not_a_file' => 'sugar-animation: not a file: {path}',
    'decoder.too_many_frames' => 'sugar-animation: too many frames (maximum {max})',
    'decoder.total_cells_too_large' => 'sugar-animation: total cell budget exceeded (maximum {max} cells)',
    'decoder.grid_too_large'     => 'sugar-animation: cell grid too large (maximum {max} cells)',
    'decoder.grid_too_small'     => 'sugar-animation: cell grid dimensions must be positive',
    'decoder.truncated'          => 'sugar-animation: truncated GIF',
    'decoder.screen_too_large'   => 'sugar-animation: GIF screen dimensions exceed maximum ({max} pixels)',
    'decoder.screen_too_small'   => 'sugar-animation: GIF screen dimensions must be positive',
    'artlibrary.not_a_directory' => 'sugar-animation: not a directory: {dir}',
    'artlibrary.cannot_list'     => 'sugar-animation: cannot list directory: {dir}',
    'ansiart.cannot_read'        => 'sugar-animation: cannot read art file: {path}',
    'ansiart.read_failed'        => 'sugar-animation: failed to read art file: {path}',
];
