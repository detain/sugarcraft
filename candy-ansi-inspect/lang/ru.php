<?php

/**
 * Russian translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Использование: candy-ansi-inspect [файл]\n  или:  cmd | candy-ansi-inspect\n  или:  candy-ansi-inspect [--help | -h]",
    'cli.not_a_file' => "candy-ansi-inspect: не удалось прочитать: {path}",
    'cli.wrapper_note' => "  (потоковые обёртки, такие как php:// или data://, не принимаются)",
    'cli.pty_unavailable' => "candy-ansi-inspect: не удалось запустить псевдотерминал: {reason}",
];
