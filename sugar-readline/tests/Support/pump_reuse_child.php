<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlinePumpReuseTest: drives the real run-loop
 * over OWNED php://stdin across TWO successive run() calls on ONE Readline
 * instance — the piped-REPL shape (printf 'one\ntwo\n' | php app.php).
 *
 * The defect this pins: the pump used to fopen php://stdin and build a
 * fresh StreamInputDriver on every run() call. An eager first read drained
 * the pipe into the first driver's event queue, and the second run() built
 * a driver over a stream already drained — later lines vanished. The fix
 * caches the owned stream + driver on the instance, so decoded-but-unconsumed
 * events (and the EOF latch) survive across runs.
 *
 * The idle sleeper exits 7 loudly: a mis-wired reuse that leaves the loop
 * waiting on an empty-but-open pump fails fast and visibly.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$readline = new Readline(null, static function (): never {
    echo "SPUN\n";
    exit(7);
});

$first = $readline->run(TextPrompt::new('a: '));
$second = $readline->run(TextPrompt::new('b: '));
$third = $readline->run(TextPrompt::new('c: '));

echo 'GOT|' . $first->value() . '|' . $second->value() . '|' . $third->value() . '|'
    . ($first->isSubmitted() && $second->isSubmitted() ? 'submitted=y' : 'submitted=n')
    . ($third->value() === '' ? ' drained=y' : ' drained=n') . "\n";
