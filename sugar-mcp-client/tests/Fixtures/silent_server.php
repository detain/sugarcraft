<?php

declare(strict_types=1);

// Reads stdin and never writes a byte: exercises the handshake give-up path
// when the server is alive but silent. Exits on stdin EOF (the client's
// close-pipes-first stop hands it exactly that).

while (($line = fgets(STDIN)) !== false) {
    // Deliberately silent. The 30s backstop keeps a killed runner's leftover
    // child bounded even if EOF never arrives.
    if (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) > 30.0) {
        exit(0);
    }
}

exit(0);
