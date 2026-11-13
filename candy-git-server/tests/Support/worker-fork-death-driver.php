<?php

declare(strict_types=1);

// Death-driver for WorkerForkTest::testKilledPoolHostLeavesNoOrphanWorkers.
// Boots a pool, spawns ONE forever-worker, prints its pid, and then just
// stays alive until the test SIGKILLs this process — the worker's exit is
// the measurement. Never terminateAll here: surviving a dirty parent death
// is precisely what is under test.

require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\GitServer\Support\WorkerFork;

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pair === false) {
    fwrite(STDERR, "driver: socketpair failed\n");
    exit(1);
}
[$near, $far] = $pair;

$pool = new WorkerFork();
// fork() does not surface the child pid; the handler records it through
// the connection before looping forever.
$spawned = $pool->fork(static function ($client): bool {
    \fwrite($client, 'WORKER ' . \getmypid() . "\n");

    return true; // own nothing, end nothing — parent-death must end it
}, $far);
if (!$spawned) {
    fwrite(STDERR, "driver: fork refused\n");
    exit(1);
}
fclose($far);

// Pump the reported pid out to the test through OUR stdout.
stream_set_blocking($near, false);
$line = '';
$until = microtime(true) + 5.0;
while (microtime(true) < $until) {
    $chunk = fread($near, 1024);
    if ($chunk === false || ($chunk === '' && feof($near))) {
        break;
    }
    $line .= $chunk;
    if (str_contains($line, "\n")) {
        break;
    }
    usleep(20_000);
}
if (!preg_match('/WORKER (\d+)/', $line, $m)) {
    fwrite(STDERR, "driver: no worker pid within 5 s (got: {$line})\n");
    exit(1);
}
fwrite(STDOUT, 'WORKER ' . $m[1] . "\n");
fflush(STDOUT);

// Stay alive until SIGKILL — sleep out the test's polling window.
sleep(30);
exit(0);
