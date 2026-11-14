<?php

declare(strict_types=1);

/**
 * Child-process fixture for PromptHeightClampTest (S-04): runs inside a
 * `script`-allocated pts with COLUMNS/LINES removed, sizes the terminal to
 * a known 12 rows, and reports the row budget the pump adopted for a
 * still-unbounded selection prompt.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\SelectionPrompt;

if (!stream_isatty(STDOUT)) {
    echo "NOTTY\n";
    exit(0);
}

putenv('COLUMNS');
putenv('LINES');
exec('stty cols 80 rows 12 < /dev/tty 2>/dev/null');

$sizes = [];
exec('stty size < /dev/tty 2>/dev/null', $sizes);
$parts = preg_split('/\s+/', trim($sizes[0] ?? '')) ?: [];
$rows = (int) ($parts[0] ?? 0);

if ($rows !== 12) {
    echo "NOROWS size=" . trim($sizes[0] ?? '') . "\n";
    exit(0);
}

$prompt = (new Readline())->run(
    SelectionPrompt::new('> ', array_map(static fn(int $i): string => "choice-{$i}", range(1, 30))),
    STDOUT
);

echo 'ROWS=' . var_export($prompt->viewportRows(), true) . "\n";
