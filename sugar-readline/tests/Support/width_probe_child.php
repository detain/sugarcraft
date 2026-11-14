<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlineWidthTest (S-01): runs inside a
 * `script`-allocated pts with COLUMNS removed, sizes the terminal to a
 * known 37 columns, and reports the width the pump adopted for a
 * still-unbounded prompt. The adoption must come from the TIOCGWINSZ
 * probe — no environment value exists to adopt.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

if (!stream_isatty(STDOUT)) {
    echo "NOTTY\n";
    exit(0);
}

putenv('COLUMNS');
exec('stty cols 37 rows 12 < /dev/tty 2>/dev/null');

$sizes = [];
exec('stty size 2>/dev/null', $sizes);
$reported = trim($sizes[0] ?? '');
$cols = (int) (explode(' ', $reported)[1] ?? 0);

if ($cols !== 37) {
    echo "NOCOLS size={$reported}\n";
    exit(0);
}

$prompt = (new Readline())->run(TextPrompt::new('> '), STDOUT);

echo 'WIDTH=' . var_export($prompt->displayWidth(), true) . "\n";
