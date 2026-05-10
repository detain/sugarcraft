<?php

declare(strict_types=1);

/**
 * CandyLog panic handler demo — run with: php examples/panic-handler.php
 *
 * Demonstrates Log::installPanicHandler() which catches uncaught exceptions
 * and fatal errors, restoring the terminal and printing a styled panic report.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Log\Log;

// Capture output for display
$stderr = fopen('php://stdout', 'w');

// Install the panic handler BEFORE any code that might panic.
// This must be called early—before any risky operations.
Log::installPanicHandler();

echo "Panic handler installed. Triggering a fatal error...\n";

// When trigger_error is called with E_USER_ERROR, it causes a fatal-like
// error that the shutdown handler catches. The panic handler then:
//
// 1. Restores terminal from altscreen mode
// 2. Shows the cursor
// 3. Prints a styled panic banner with exception class + message
// 4. Prints the backtrace with file paths and line numbers
// 5. Collapses repeated stack frames
// 6. Appends a hint to run `caliber refresh`
//
// Expected output (stylized):
//
//   PANIC  ErrorException
//
//   test
//
//   /home/sites/sugarcraft/candy-log/examples/panic-handler.php:39
//
//     examples/panic-handler.php:39  trigger_error()
//
//   consider `caliber refresh` if this is config-related
//
trigger_error('test', E_USER_ERROR);

echo "This line is never reached.\n";
