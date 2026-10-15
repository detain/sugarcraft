<?php

declare(strict_types=1);

/**
 * Blocking Spinner — `Prompt\Spinner` schedules a worker callable,
 * animates a SugarBits Spinner on STDERR while it runs, then returns.
 * Mirrors huh's `huh.NewSpinner().Title(...).Action(fn).Run()`.
 *
 *   php examples/spinner.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Bits\Spinner\Style as SpinnerStyle;
use SugarCraft\Prompt\Spinner;

echo "Starting work...\n";

Spinner::new()
    ->withTitle('Crunching numbers')
    ->withStyle(SpinnerStyle::dot())
    ->withAction(static function (): void {
        // Simulate a long-running task. The Spinner animates while
        // this callable runs.
        usleep(750_000);
    })
    ->run();

echo "Done.\n";
