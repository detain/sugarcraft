<?php

declare(strict_types=1);

// Ignores SIGTERM so the BoundedShutdown ladder must escalate to SIGKILL to
// reap it. Reads stdin only to notice pipe close; 30s backstop for the case
// where the test runner dies before stop().

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
}

$born = microtime(true);
while (microtime(true) - $born < 30.0) {
    if (fgets(STDIN) === false) {
        // stdin EOF; stay alive anyway — a well-behaved exit on EOF would let
        // the graceful rung win and never prove the KILL escalation.
        sleep(1);
    }
    usleep(50000);
}

exit(0);
