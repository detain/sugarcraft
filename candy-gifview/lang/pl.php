<?php

/**
 * Polish translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Użycie: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: nie można znaleźć composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: nieznana opcja --{flag}',
    'cli.bad_value'   => 'candy-gifview: nieprawidłowa wartość \'{value}\' dla {flag} (oczekiwano liczby dodatniej; --speed akceptuje też Nfps lub max)',
    'cli.too_many'    => 'candy-gifview: nieoczekiwany argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} nie przyjmuje wartości',
];
