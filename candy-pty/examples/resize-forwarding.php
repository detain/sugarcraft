<?php

declare(strict_types=1);

/**
 * resize-forwarding — Wire SignalForwarder to deliver host-side
 * SIGWINCH into the child PTY's TIOCSWINSZ via the v1 contract API.
 *
 * Demo:
 *  1. Open a PTY at 80×24 via {@see \SugarCraft\Pty\PtySystemFactory}.
 *  2. Wire SignalForwarder::attachSigwinch with a size provider that
 *     reads cols/rows from a closure-captured pair of variables.
 *  3. Spawn a bash loop that logs `tput cols / tput lines` every
 *     150 ms for 1.5 seconds.
 *  4. Mid-loop, mutate the captured cols/rows and `posix_kill`
 *     SIGWINCH to ourselves so the child sees the new dims on its
 *     next tput.
 *
 * Usage:
 *   php examples/resize-forwarding.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\SignalForwarder;

if (!SignalForwarder::pcntlReady()) {
    fwrite(STDERR, "ext-pcntl is required for this example\n");
    exit(1);
}

$cols = 80;
$rows = 24;

$pair = PtySystemFactory::default()->open($cols, $rows);
$master = $pair->master();
$slave = $pair->slave();

try {
    $sizeProvider = static function () use (&$cols, &$rows): array {
        return ['cols' => $cols, 'rows' => $rows];
    };

    SignalForwarder::attachSigwinch($master, $sizeProvider);
    echo "SIGWINCH handler installed; starting at {$cols}×{$rows}\n";

    $tmp = tempnam(sys_get_temp_dir(), 'candy-pty-resize-');
    $script = "for i in 1 2 3 4 5 6 7 8 9 10; do " .
              "echo \"tick \$i: \$(tput cols)x\$(tput lines)\" >> {$tmp}; " .
              "sleep 0.15; done";

    $child = $slave->spawn(
        ['/bin/sh', '-c', $script],
        ['TERM' => 'xterm-256color', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        $cols,
        $rows,
    );

    // Halfway through the child's loop, switch to 132×40 and deliver
    // SIGWINCH. The handler picks up the new sizeProvider() values
    // and ioctls TIOCSWINSZ on the master fd.
    usleep(750_000);
    [$cols, $rows] = [132, 40];
    posix_kill(posix_getpid(), SIGWINCH);
    echo "  → resized to {$cols}×{$rows} at t≈0.75s\n";

    $child->wait();

    $log = (string) file_get_contents($tmp);
    @unlink($tmp);

    echo "── child saw ──\n{$log}";
} finally {
    if (!$master->isClosed()) {
        $master->close();
    }
}
