<?php

/**
 * Italian translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'uso: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: impossibile trovare composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: opzione sconosciuta --{flag}',
    'cli.bad_value'   => 'candy-gifview: valore non valido \'{value}\' per {flag} (è previsto un numero positivo; --speed accetta anche Nfps o max)',
    'cli.too_many'    => 'candy-gifview: argomento imprevisto \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} non accetta valori',
];
