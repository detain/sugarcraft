<?php

declare(strict_types=1);

/**
 * Test fixture — drives `InProcessTransport::runChild()` from a
 * separate PHP process so the test runner can simulate the SSH
 * client side via the fixture's stdin / stdout pipes.
 *
 * Avoids pcntl_fork() inside PHPUnit (PHP runtime carries opcache,
 * mock state, FFI handles into the fork — flaky and dangerous).
 *
 * Usage:
 *   php runchild.php <cols> <rows> <cmd> [args...]
 *
 * stdin  → supervisor's stdin (forwarded to PTY master)
 * stdout → supervisor's stdout (drained from PTY master)
 * exit   → child's exit code
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

$cols = (int) ($argv[1] ?? 80);
$rows = (int) ($argv[2] ?? 24);
$cmd = \array_slice($argv, 3);
if ($cmd === []) {
    \fwrite(\STDERR, "usage: runchild.php <cols> <rows> <cmd> [args...]\n");
    exit(2);
}

$session = new Session(
    user: 'fixture', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
    serverPort: 22, term: 'xterm-256color', cols: $cols, rows: $rows,
    tty: null, command: null, lang: 'C.UTF-8',
);

try {
    $exit = (new InProcessTransport())->runChild($session, $cmd);
    exit($exit);
} catch (\Throwable $e) {
    \fwrite(\STDERR, "runchild fixture: " . $e::class . ": " . $e->getMessage() . "\n");
    exit(2);
}
