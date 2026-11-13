<?php

/**
 * Polish translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Użycie: candy-ansi-inspect [plik]\n  lub:  cmd | candy-ansi-inspect",
    'cli.not_a_file' => "candy-ansi-inspect: nie można odczytać: {path}\n  (opakowania strumienia, takie jak php:// czy data://, nie są akceptowane)",
    'cli.pty_unavailable' => "candy-ansi-inspect: nie można uruchomić pseudoterminela: {reason}",
];
