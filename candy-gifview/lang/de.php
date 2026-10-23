<?php

/**
 * German translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Aufruf: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: composer autoload.php nicht gefunden',
    'cli.bad_flag'    => 'candy-gifview: unbekannte Option --{flag}',
    'cli.bad_value'   => 'candy-gifview: ungültiger Wert \'{value}\' für {flag} (erwartet eine positive Zahl; --speed akzeptiert auch Nfps oder max)',
    'cli.too_many'    => 'candy-gifview: unerwartetes Argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} erwartet keinen Wert',
];
