<?php

/**
 * Italian translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Uso: candy-ansi-inspect [file]\n  oppure:  cmd | candy-ansi-inspect",
    'cli.not_a_file' => "candy-ansi-inspect: impossibile leggere: {path}",
    'cli.wrapper_note' => "  (i wrapper di flusso come php:// o data:// non sono accettati)",
    'cli.pty_unavailable' => "candy-ansi-inspect: impossibile avviare un pseudo terminale: {reason}",
];
