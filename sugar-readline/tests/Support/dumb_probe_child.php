<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlineDumbTerminalTest: runs inside a
 * `script`-allocated pts with a chosen TERM value and drives the real pump
 * painting to STDOUT. The parent inspects the session file for escape
 * bytes — on a dumb (or unknown) terminal the frames must arrive as plain
 * text, with no mode pushes and no cursor escapes at all.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

if (!stream_isatty(STDOUT)) {
    echo "NOTTY\n";
    exit(0);
}

$prompt = (new Readline())->run(TextPrompt::new('> '), STDOUT)->prompt;

echo 'FINAL submitted=' . var_export($prompt->isSubmitted(), true) . "\n";
