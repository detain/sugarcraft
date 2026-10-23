<?php

declare(strict_types=1);

/**
 * Child-process fixture for ReadlineEofTest: drives the real run-loop with
 * an owned php://stdin pump. The idle-poll sleeper screams and exits 7 if
 * the loop ever reaches an idle sleep — a re-introduced EOF spin (the loop
 * mistaking a closed pump for a merely-empty one) makes that sleeper fire
 * within its first call, turning a hang into a loud, fast failure.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

$readline = new Readline(null, static function (): never {
    echo "SPUN\n";
    exit(7);
});

$prompt = $readline->run(TextPrompt::new('> '));

echo 'RETURNED submitted=' . var_export($prompt->isSubmitted(), true)
    . ' aborted=' . var_export($prompt->isAborted(), true) . "\n";
