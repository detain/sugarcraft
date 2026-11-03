<?php

declare(strict_types=1);

/**
 * One sibling connect attempt, for the cross-process contention pin.
 *
 * Usage: php ratelimit-child.php <state-path> <sibling-count> <ready-dir> <index>
 *
 * The child announces itself in <ready-dir>, then spins until every sibling
 * has announced. That barrier is the whole point: without it the children
 * start tens of milliseconds apart and each one's read happens after its
 * predecessor has already published, so a limiter with no working
 * serialisation still looks correct. Releasing them together puts all
 * siblings inside the read-decide-publish window at once — the situation a
 * burst of parallel SSH connects actually creates.
 *
 * Prints exactly one byte on stdout: 'A' when the connect was allowed,
 * 'R' when it was throttled.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\RateLimit;
use SugarCraft\SshServer\Session;

[$script, $statePath, $siblingsRaw, $readyDir, $indexRaw] = $argv;

$siblings = (int) $siblingsRaw;
$index = (int) $indexRaw;

if (!is_dir($readyDir)) {
    fwrite(STDERR, "ready directory missing: {$readyDir}\n");
    exit(2);
}

touch($readyDir . '/ready-' . $index);

// Barrier: wait until every sibling has announced itself. Bounded so a child
// that dies before its touch cannot wedge the suite — the parent treats a
// zero-byte report as a harness failure, not as a throttle.
$waited = 0.0;
while ($waited < 10.0) {
    $seen = 0;
    $handle = opendir($readyDir);
    if ($handle === false) {
        fwrite(STDERR, "cannot read ready directory\n");
        exit(2);
    }
    while (($entry = readdir($handle)) !== false) {
        if (str_starts_with($entry, 'ready-')) {
            $seen++;
        }
    }
    closedir($handle);

    if ($seen >= $siblings) {
        break;
    }

    usleep(1000);
    $waited += 0.001;
}

// Every sibling shares one bucket: same client host, same limiter state.
// rate 0.0 keeps the burst from refilling while the children contend.
$limiter = new RateLimit($statePath, 3, 0.0, fopen('php://memory', 'w+'));

$allowed = false;
$limiter->handle(
    Context::background(),
    new Session(
        user: 'alice', clientHost: '203.0.113.7', clientPort: 1, serverHost: '127.0.0.1',
        serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
        command: null, lang: 'C.UTF-8',
    ),
    function () use (&$allowed): void {
        $allowed = true;
    },
);

fwrite(STDOUT, $allowed ? 'A' : 'R');
exit(0);
