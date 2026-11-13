<?php

/**
 * Spanish translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Uso: candy-ansi-inspect [archivo]\n  o:  cmd | candy-ansi-inspect",
    'cli.not_a_file' => "candy-ansi-inspect: no se puede leer: {path}",
    'cli.wrapper_note' => "  (los wrappers de flujo como php:// o data:// no se aceptan)",
    'cli.pty_unavailable' => "candy-ansi-inspect: no se puede iniciar un seudoterminal: {reason}",
];
