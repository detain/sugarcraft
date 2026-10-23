<?php

/**
 * English (default) translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'usage: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: cannot find composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: unknown option --{flag}',
    'cli.bad_value'   => 'candy-gifview: invalid value \'{value}\' for {flag} (want a positive number; --speed also accepts Nfps or max)',
    'cli.too_many'    => 'candy-gifview: unexpected argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} takes no value',
];
