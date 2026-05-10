<?php

declare(strict_types=1);

/**
 * CandyLog terminal restore demo — run with: php examples/panic-restore.php
 *
 * Demonstrates Tty::restoreLast() for restoring terminal state after
 * raw mode was activated, outside of the normal Program loop.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Tty;

// Check if Tty is available (it comes from candy-core)
if (!class_exists(Tty::class)) {
    echo "Tty class not available (candy-core dependency missing)\n";
    exit(1);
}

// Capture output for display
$stderr = fopen('php://stdout', 'w');

echo "=== Tty::restoreLast() (standalone) ===\n";

// Tty::restoreLast() is a static convenience that saves/restores terminal
// state without needing a Tty instance. It's what Log::restoreTerminal()
// calls internally, but you can use it directly for custom scenarios.
//
// The first call saves the current terminal state.
// The second call restores it and clears the saved state.
//
// This is useful when:
// - You've entered raw mode via some other mechanism
// - You're building a custom TUI that doesn't use the Program loop
// - You need fine-grained control over when state is restored
//
// Note: In normal usage with Program, the loop handles restore automatically.
// This example shows standalone usage outside that loop.
Tty::restoreLast(); // First call: save state (idempotent no-op if already saved)

echo "Terminal state saved. Type some characters, then press Enter...\n";
echo "After you press Enter, we'll restore the terminal state.\n";

$input = fgets($stderr);
echo "You typed: " . trim((string) $input) . "\n";

// Second call: restore the saved state.
// This exits altscreen mode and shows the cursor.
Tty::restoreLast();

echo "Terminal state restored.\n";
