<?php

/**
 * Brazilian Portuguese translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Uso: candy-ansi-inspect [arquivo]\n  ou:  cmd | candy-ansi-inspect\n  ou:  candy-ansi-inspect [--help | -h]",
    'cli.not_a_file' => "candy-ansi-inspect: não foi possível ler: {path}",
    'cli.wrapper_note' => "  (wrappers de stream como php:// ou data:// não são aceitos)",
    'cli.pty_unavailable' => "candy-ansi-inspect: não é possível iniciar um pseudoterminal: {reason}",
];
