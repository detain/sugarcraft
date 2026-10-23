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

// Install the panic handler BEFORE any code that might panic.
// This must be called early—before any risky operations.
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
// Real output (colors stripped; the report goes to STDERR, exit code 255):
//
//   Panic handler installed. Triggering a fatal error...
//   PHP Fatal error:  test in .../examples/panic-handler.php on line 52
//
//     PANIC   ErrorException
//
//     User error: test in .../examples/panic-handler.php on line 52
//
//     .../src/Log.php:122
//
//       ?:? SugarCraft\Log\Log::SugarCraft\Log\{closure}()
//
//   report rendered by sugar-log — include the trace above when filing an issue
//
// The banner's own file/line frame points at the shutdown closure inside
// Log.php (where the ErrorException is constructed); the ORIGINAL fatal's
// location rides inside the message text above it.
trigger_error('test', E_USER_ERROR);

echo "This line is never reached.\n";
