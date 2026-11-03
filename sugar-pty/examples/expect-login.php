<?php

declare(strict_types=1);

/**
 * expect-login — Script a session with {@see \SugarCraft\Pty\Expect}:
 * spawn a PTY child, send it a token, and wait until the token comes
 * back through the master. The bounded ExpectTimeoutException proves
 * the automation budget is real where a bare read loop could hang
 * forever.
 *
 * Usage:
 *   php examples/expect-login.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Expect;
use SugarCraft\Pty\PtySystemFactory;

if (PHP_OS_FAMILY === 'Windows' || !\is_writable('/dev/ptmx')) {
    echo "expect-skip — no POSIX PTY layer here\n";
    exit(0);
}

try {
    $system = PtySystemFactory::new();
    $pair   = $system->open(80, 24);
} catch (\Throwable $e) {
    echo 'expect-skip — ' . $e->getMessage() . "\n";
    exit(0);
}

$master = $pair->master();
$child  = null;

try {
    $token = 'ping-' . \uniqid('', true);
    $child = $pair->slave()->spawn(
        ['/bin/cat'],
        env: [...(array) getenv(), 'TERM' => 'xterm-256color'],
    );

    $expect = Expect::on($master);
    $expect->send($token . "\r");
    // Cooked mode echoes keystrokes back, and /bin/cat returns them
    // too — the token reaches the needle either way. The needle itself
    // carries no bare \n (wire rules: ONLCR rewrites those).
    $seen = $expect->expectAny([$token], 2.0);

    echo 'expect-ok — matched after ' . \strlen((string) $seen->before) . " bytes\n";
    exit(0);
} catch (\Throwable $e) {
    echo 'expect-fail — ' . $e->getMessage() . "\n";
    exit(1);
} finally {
    if ($child !== null && !$child->exited()) {
        $child->kill(Child::SIGTERM);
        $child->wait();
    }
    if (!$master->isClosed()) {
        $master->close();
    }
}
