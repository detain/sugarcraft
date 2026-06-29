<?php

/**
 * English (default) translations for candy-flip.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'decoder.no_file'      => 'candy-flip: no such file: {path}',
    'decoder.no_gd'        => 'candy-flip: ext-gd is required',
    'decoder.not_gif'      => 'candy-flip: not a GIF',
    'decoder.grid_too_large' => 'candy-flip: cell grid product exceeds maximum ({max})',
    'cli.usage'            => 'usage: candy-flip <gif> [solid|density]',
    'cli.no_autoload'      => 'candy-flip: cannot find composer autoload.php',
];
