<?php

declare(strict_types=1);

/**
 * SugarLog terminal restore demo — run with: php examples/panic-restore.php
 *
 * Demonstrates Tty::restoreLast() for restoring terminal state after
 * raw mode was activated, outside of the normal Program loop.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Tty;

// Check if Tty is available (it comes from sugar-core)
if (!class_exists(Tty::class)) {
    echo "Tty class not available (sugar-core dependency missing)\n";
    exit(1);
}

echo "=== Tty::restoreLast() (standalone) ===\n";

// Tty::restoreLast() is a static convenience that saves the terminal state
// on the first call and restores (clearing the save) on the second. It is
// what Log::restoreTerminal() calls internally; the Program loop normally
// handles restore automatically, this shows standalone usage outside it.

Tty::restoreLast(); // First call: save state (idempotent no-op if already saved)

// The demo used to block on fgets(STDIN) waiting for a keypress,
// which hung any non-interactive run (CI, pipes, docs guards). The save /
// restore pair is what is being demonstrated, and it needs no input: show
// an intermediate step, then restore.
echo "Terminal state saved; doing intermediate work...\n";

// Second call: restore the saved state.
// This exits altscreen mode and shows the cursor.
Tty::restoreLast();

echo "Terminal state restored.\n";
