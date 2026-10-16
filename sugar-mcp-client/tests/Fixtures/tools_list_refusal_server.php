<?php

declare(strict_types=1);

// Handshake-gate fixture: initialize succeeds, then tools/list is where the
// server balks — the leg start() used to wave through as "up, 0 tools".
//   (default)       tools/list answers a JSON-RPC error (-32601), the shape
//                   session- and capability-gated servers really send
//   --no-reply      tools/list is never answered (the budget expires)
//   --close-stdin   stdin is closed BEFORE the initialize reply goes out, so
//                   the client's notifications/initialized write meets a
//                   pipe nobody reads — a dropped notification, on demand
// Writes a stderr line first so the refusal's diagnostics can be pinned.
// Bounded: exits on stdin EOF or after 30s, so a broken test cannot leak it.

$noReply = in_array('--no-reply', $argv, true);
$closeStdin = in_array('--close-stdin', $argv, true);
$born = microtime(true);

fwrite(STDERR, "refusal-fixture: booted\n");

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
        continue;
    }

    $id = (string) $msg['id'];

    if ($msg['method'] === 'initialize') {
        if ($closeStdin) {
            fclose(STDIN);
        }

        $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new stdClass(),
            'serverInfo' => ['name' => 'fixture-refusal', 'version' => '0.0.1'],
        ]]);

        if ($closeStdin) {
            // Stay alive (stop() reaps us) so the failure is the dropped
            // write, not a dead child.
            sleep(20);
            exit(0);
        }

        continue;
    }

    if ($msg['method'] === 'tools/list') {
        if ($noReply) {
            continue;
        }

        $write(['jsonrpc' => '2.0', 'id' => $id,
            'error' => ['code' => -32601, 'message' => 'tools capability not enabled for this session']]);
        continue;
    }

    $write(['jsonrpc' => '2.0', 'id' => $id,
        'error' => ['code' => -32601, 'message' => 'Method not found']]);
}

exit(0);
