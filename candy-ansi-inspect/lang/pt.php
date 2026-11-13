<?php

/**
 * Portuguese translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Uso: candy-ansi-inspect [ficheiro]\n  ou:  cmd | candy-ansi-inspect\n  ou:  candy-ansi-inspect [--help | -h]",
    'cli.not_a_file' => "candy-ansi-inspect: não é possível ler: {path}",
    'cli.wrapper_note' => "  (invólucros de fluxo como php:// ou data:// não são aceites)",
    'cli.pty_unavailable' => "candy-ansi-inspect: não é possível iniciar um pseudo-terminal: {reason}",
];
