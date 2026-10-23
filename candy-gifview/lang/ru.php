<?php

/**
 * Russian translations for candy-gifview.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage'       => 'Использование: candy-gifview <gif> [solid|density] [--fps=N] [--speed=N|Nfps|max] [--duration=SECONDS] [--watch] [--help]',
    'cli.no_autoload' => 'candy-gifview: невозможно найти composer autoload.php',
    'cli.bad_flag'    => 'candy-gifview: неизвестный параметр --{flag}',
    'cli.bad_value'   => 'candy-gifview: недопустимое значение \'{value}\' для {flag} (ожидается положительное число; --speed также принимает Nfps или max)',
    'cli.too_many'    => 'candy-gifview: неожиданный аргумент \'{arg}\'',
    'cli.unexpected_value' => 'candy-gifview: --{flag} не принимает значение',
];
