<?php

/**
 * English (default) translations for sugar-animation.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'          => 'sugar-animation: no such file: {path}',
    'decoder.no_gd'            => 'sugar-animation: ext-gd is required',
    'decoder.not_gif'          => 'sugar-animation: not a GIF',
    'decoder.grid_too_large'   => 'sugar-animation: cell grid product exceeds maximum ({max})',
    'decoder.grid_too_small'   => 'sugar-animation: cell grid dimensions must be positive',
    'decoder.truncated'        => 'sugar-animation: truncated GIF',
    'decoder.screen_too_large' => 'sugar-animation: GIF screen dimensions exceed maximum ({max} pixels)',
    'decoder.screen_too_small' => 'sugar-animation: GIF screen dimensions must be positive',
];
