<?php

declare(strict_types=1);

/**
 * Child fixture for ReadlineInteractiveTest: runs under a `script`-allocated
 * pts fed one Ctrl+C byte. Two modes:
 *
 *  - interactive: Readline::interactive() flips the pts raw, Ctrl+C arrives
 *    as an ordinary byte, the prompt aborts, the finally restores canonical
 *    mode; the verdict lines PROBE/CHORD/CANONICAL_AFTER prove all three.
 *  - cooked: plain run() leaves the discipline canonical — SIGINT kills the
 *    process before any verdict can print (the documented adoption cliff).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$mode = $argv[1] ?? 'interactive';

if (!stream_isatty(STDIN)) {
    echo "NOTTY\n";
    exit(0);
}

$readline = Readline::fromStdin()
    ->onKey('ctrl_c', static function (): void {
        echo "CHORD\n";
    });

if ($mode === 'cooked') {
    $readline->run(new TextPrompt('> '), STDOUT);
    echo "UNREACHABLE\n";
    exit(0);
}

$prompt = Readline::interactive(new TextPrompt('> '), STDOUT, $readline);

echo 'PROBE submitted=' . var_export($prompt->isSubmitted(), true)
    . ' aborted=' . var_export($prompt->isAborted(), true) . "\n";

$after = trim((string) shell_exec('stty -a 2>/dev/null'));
$canonical = str_contains($after, 'icanon') && !str_contains($after, '-icanon');
echo 'CANONICAL_AFTER=' . var_export($canonical, true) . "\n";
