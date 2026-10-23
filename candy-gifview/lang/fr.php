<?php

/**
 * French translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'usage : candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview : impossible de trouver composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview : option inconnue --{flag}',
    'cli.bad_value'   => 'candy-gifview : valeur invalide \'{value}\' pour {flag} (attendu : un nombre positif ; --speed accepte aussi Nfps ou max)',
    'cli.too_many'    => 'candy-gifview : argument inattendu \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview : --{flag} n\'attend aucune valeur',
];
