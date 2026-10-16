<?php

declare(strict_types=1);

// Failure-gates fixture for FIX #4: a server that HANDSHAKES CORRECTLY and
// only then misbehaves — on the first tools/call it streams just past the
// client's 64MiB frame cap with no newline, and after that stays alive and
// silent while still reading stdin. This pins the mid-session shape the
// handshake-path oversized_frame_server.php cannot reach: the refusal must
// land as a callTool error payload, not a throw escaping callTool(), and the
// connection must survive to answer (or deadline) a later exchange.

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

$poisoned = false;

while (($line = fgets(STDIN)) !== false) {
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
        continue; // notifications/initialized and any later silence-keeper
    }

    $id = (string) $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'fixture-oversized-reply', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'echo',
                    'description' => 'Echoes its input (until it does not)',
                    'inputSchema' => ['type' => 'object', 'properties' => ['raw' => []]],
                ]],
            ]]);
            break;

        case 'tools/call':
            if ($poisoned) {
                break; // later calls: alive, listening, never answering
            }
            $poisoned = true;
            // 64MiB + 64KiB of 'x' with no newline: the client trips its cap
            // ~one 8KiB chunk past 64MiB, so a bounded remainder stays queued
            // for the next exchange to drain under the cap.
            $chunk = str_repeat('x', 65536);
            for ($pass = 0; $pass < 1025; $pass++) { // 67,108,864 + 65,536
                if (@fwrite(STDOUT, $chunk) === false) {
                    break; // client gave up reading; stay alive below
                }
                fflush(STDOUT);
            }
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
