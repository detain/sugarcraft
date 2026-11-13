<?php

/**
 * English (default) translations for candy-ansi-inspect.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.usage' => "Usage: candy-ansi-inspect [file]\n  or:  cmd | candy-ansi-inspect",
    'cli.not_a_file' => "candy-ansi-inspect: cannot read: {path}",
    'cli.wrapper_note' => "  (stream wrappers such as php:// or data:// are not accepted)",
    'cli.pty_unavailable' => "candy-ansi-inspect: cannot start a pseudo-terminal: {reason}",
];
