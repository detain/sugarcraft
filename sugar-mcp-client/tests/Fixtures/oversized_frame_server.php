<?php

declare(strict_types=1);

// Round-trip fixture for the review F6 pin: it answers NOTHING and streams
// more bytes than the client's 64MiB frame cap with no newline at all. The
// client must refuse the frame mid-accumulation (bounded memory AND, via the
// readLine offset scan, bounded CPU) and — the point of this fixture — reap
// the child it spawned when the refusal throws from inside the handshake.

$chunk = str_repeat('x', 65536);

for ($pass = 0; $pass < 1200; $pass++) { // ~75MiB > 64MiB cap
    if (@fwrite(STDOUT, $chunk) === false) {
        exit(0); // client closed the pipe; the refusal already happened
    }
    fflush(STDOUT);
}

// Never reached in a passing run: keep breathing so a MISSING reap is visible
// to the /proc census instead of a self-exiting fixture greening the test.
while (true) {
    sleep(1);
}
