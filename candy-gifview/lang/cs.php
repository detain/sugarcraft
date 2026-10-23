<?php

/**
 * Czech translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Použití: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: nelze najít composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: neznámá volba --{flag}',
    'cli.bad_value'   => 'candy-gifview: neplatná hodnota \'{value}\' pro {flag} (očekává se kladné číslo; --speed přijímá také Nfps nebo max)',
    'cli.too_many'    => 'candy-gifview: neočekávaný argument \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} nepřijímá hodnotu',
];
