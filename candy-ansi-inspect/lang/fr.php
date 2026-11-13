<?php

/**
 * French translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Usage : candy-ansi-inspect [fichier]\n  ou :  cmd | candy-ansi-inspect\n  ou :  candy-ansi-inspect [--help | -h]",
    'cli.not_a_file' => "candy-ansi-inspect : impossible de lire : {path}",
    'cli.wrapper_note' => "  (les wrappers de flux comme php:// ou data:// ne sont pas acceptés)",
    'cli.pty_unavailable' => "candy-ansi-inspect : impossible de démarrer un pseudo-terminal : {reason}",
];
