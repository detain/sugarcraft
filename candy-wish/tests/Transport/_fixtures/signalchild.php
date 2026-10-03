<?php

declare(strict_types=1);

/**
 * Test fixture — exercises `InProcessTransport::signalChild()` in a
 * separate PHP process so the caller can launch it with
 * `-d disable_functions=posix_kill` and prove delivery does not depend
 * on ext-posix.
 *
 * Starts `/bin/sleep 30`, points the transport's tracked child PID at
 * it, forwards SIGTERM (15) through signalChild(), then prints
 * `terminated` when the sleeper died within 3s or `alive` otherwise
 * (and reaps it with SIGKILL so nothing outlives the fixture).
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Wish\Transport\InProcessTransport;

$proc = \proc_open(['/bin/sleep', '30'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
if (!\is_resource($proc)) {
    \fwrite(\STDERR, "signalchild fixture: proc_open failed\n");
    exit(2);
}
$pid = \proc_get_status($proc)['pid'];

$transport = new InProcessTransport();
$prop = new \ReflectionProperty(InProcessTransport::class, 'childPid');
$prop->setValue($transport, $pid);

$transport->signalChild(15);

$verdict = 'alive';
$deadline = \microtime(true) + 3.0;
while (\microtime(true) < $deadline) {
    if (\proc_get_status($proc)['running'] === false) {
        $verdict = 'terminated';
        break;
    }
    \usleep(20_000);
}
if ($verdict === 'alive') {
    \SugarCraft\Pty\Libc::kill($pid, 9);
}
\proc_close($proc);

echo $verdict, "\n";
exit(0);
