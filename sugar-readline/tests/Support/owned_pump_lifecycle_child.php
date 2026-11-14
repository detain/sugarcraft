<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlineFrameContractTest: drives the real
 * run-loop with the lazily-owned php://stdin pump so the ownership rules
 * (finding 071) run against a genuine closed pipe, not a mock.
 *
 * Modes:
 *  - drained: stdin is an immediately-closed pipe. The run must exit on EOF
 *    and the finally arm must release the owned descriptor (OWNED|NULL).
 *  - queued: stdin carries 'first\nsecond\n'. The first run consumes one
 *    line and leaves the pump un-drained; the pump-reuse law must deliver
 *    the second line to the next run on the SAME instance, and the
 *    descriptor must be closed only after the run that actually hit EOF.
 *
 * The idle-poll sleeper is the hang tripwire from the EOF fixture family:
 * a loop mistaking a drained pump for a merely-empty one spins here.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$mode = $argv[1] ?? 'drained';

$readline = new Readline(null, static function (): never {
    echo "SPUN\n";
    exit(7);
});

if ($mode === 'queued') {
    $first = $readline->run(TextPrompt::new('A'), null);
    echo 'P1|' . $first->value() . "\n";
    $second = $readline->run(TextPrompt::new('B'), null);
    echo 'P2|' . $second->value() . "\n";
} else {
    $prompt = $readline->run(TextPrompt::new('A'), null);
    echo 'P1|' . $prompt->value() . "\n";
}

$property = new ReflectionProperty(Readline::class, 'ownedStream');
echo 'OWNED|' . ($property->getValue($readline) === null ? 'NULL' : 'OPEN') . "\n";
echo "END\n";
