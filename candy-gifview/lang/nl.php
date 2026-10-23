<?php

/**
 * Dutch translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Gebruik: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: kan composer autoload.php niet vinden',
    'cli.bad_flag'    => 'candy-gifview: onbekende optie --{flag}',
    'cli.bad_value'   => 'candy-gifview: ongeldige waarde \'{value}\' voor {flag} (positief getal verwacht; --speed accepteert ook Nfps of max)',
    'cli.too_many'    => 'candy-gifview: onverwacht argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} verwacht geen waarde',
];
