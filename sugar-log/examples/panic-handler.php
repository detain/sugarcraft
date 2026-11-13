<?php

declare(strict_types=1);

/**
 * SugarLog panic handler demo — run with: php examples/panic-handler.php
 *
 * Demonstrates Log::installPanicHandler() which catches uncaught exceptions
 * and fatal errors, restoring the terminal and printing a styled panic report.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Log\Log;

// Install the panic handler early — before any code that might panic.
Log::installPanicHandler();

echo "Panic handler installed. Triggering a fatal error...\n";

// trigger_error with E_USER_ERROR is fatal in PHP 8: the script stops on
// the line below, and the registered shutdown function renders the panic
// report. The handler:
//
// 1. Restores the terminal from altscreen mode (TTY only)
// 2. Shows the cursor
// 3. Prints a styled PANIC banner with the throwable class + message
// 4. Prints the backtrace with file paths and line numbers
//    (internal frames render as '?:?')
// 5. Collapses repeated stack frames
// 6. Appends a neutral report footer with filing guidance
//
// The exact shape of the report is pinned by tests/ReadmeSnippetsTest
// (testPanicHandlerExampleDiesRenderingTheReport): the process exits 255,
// STDERR carries the PANIC banner and the sugar-log footer. The banner's
// own file/line frame points at the shutdown closure inside Log.php (the
// ErrorException's birthplace); the ORIGINAL fatal's location rides
// inside the message text.
trigger_error('test', E_USER_ERROR);

echo "This line is never reached.\n";
