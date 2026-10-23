<?php

/**
 * Brazilian Portuguese translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'uso: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: não é possível localizar composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: opção desconhecida --{flag}',
    'cli.bad_value'   => 'candy-gifview: valor inválido \'{value}\' para {flag} (espera-se um número positivo; --speed também aceita Nfps ou max)',
    'cli.too_many'    => 'candy-gifview: argumento inesperado \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} não aceita valor',
];
