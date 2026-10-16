<?php

declare(strict_types=1);

// Emits valid JSON-RPC notifications forever and never answers a request:
// starves any per-read timeout that resets on arriving traffic. The client's
// single wall-clock handshake deadline is what bounds this exchange.
// Self-terminates after 30s as an orphan backstop.

$born = microtime(true);

while (microtime(true) - $born < 30.0) {
    echo json_encode([
        'jsonrpc' => '2.0',
        'method' => 'notifications/progress',
        'params' => ['progressToken' => 'tick', 'progress' => 1],
    ]), "\n";
    if (!fflush(STDOUT)) {
        exit(0); // reader is gone; nothing left to do
    }
    usleep(1000);
}

exit(0);
