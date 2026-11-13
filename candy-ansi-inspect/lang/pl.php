<?php

/**
 * Polish translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Użycie: candy-ansi-inspect [plik]\n  lub:  cmd | candy-ansi-inspect\n  lub:  candy-ansi-inspect [--help | -h]",
    'cli.not_a_file' => "candy-ansi-inspect: nie można odczytać: {path}",
    'cli.wrapper_note' => "  (opakowania strumienia, takie jak php:// czy data://, nie są akceptowane)",
    'cli.pty_unavailable' => "candy-ansi-inspect: nie można uruchomić pseudoterminela: {reason}",
];
