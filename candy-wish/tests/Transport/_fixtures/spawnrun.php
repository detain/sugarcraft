<?php

declare(strict_types=1);

/**
 * Test fixture — drives the FULL MEDIUM-2 status chain from a
 * separate PHP process: `InProcessTransport::run()` with the Spawn
 * middleware, then exits the script with the int run() returned.
 *
 * Unlike `_fixtures/runchild.php` (which calls runChild directly and
 * proves the pump returns the child code), this fixture pins the
 * transport-slot handoff: Spawn -> runChild -> lastChildStatus ->
 * run() return value -> server-script exit code, AND simultaneously
 * proves the typed TransportAware injection fires (Spawn throws
 * "no transport" without it).
 *
 * Usage:
 *   php spawnrun.php <cmd> [args...]
 *
 * stdin  → supervisor's stdin (closed immediately: no interactive I/O)
 * exit   → whatever run() returned
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Spawn;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

$cmd = \array_slice($argv, 1);
if ($cmd === []) {
    \fwrite(\STDERR, "usage: spawnrun.php <cmd> [args...]\n");
    exit(2);
}

$session = new Session(
    user: 'fixture', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
    serverPort: 22, term: 'xterm-256color', cols: 80, rows: 24,
    tty: null, command: null, lang: 'C.UTF-8',
);

try {
    $exit = (new InProcessTransport())->run(
        Context::background(),
        $session,
        [new Spawn(static fn (): array => ['cmd' => $cmd])],
    );
    exit($exit);
} catch (\Throwable $e) {
    \fwrite(\STDERR, "spawnrun fixture: " . $e::class . ": " . $e->getMessage() . "\n");
    exit(2);
}
