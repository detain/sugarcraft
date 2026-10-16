<?php

declare(strict_types=1);

// Round-trip fixture for audit MCP-4: a server that is sloppy with stdout. It
// prints a banner at boot and, before EVERY genuine answer, a log line, a blank
// keep-alive line and a whitespace-only line. The reference SDK clients skip
// unparseable lines; before the fix the first one ended the request as a
// failure even though the real reply was the very next line in the pipe.
// Self-terminates after 30s as an orphan backstop.

$born = microtime(true);

$write = static function (array $payload): void {
    echo "[noisy] about to answer\n", "\n", "   \n";
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

echo "noisy-server v0.0.1 listening on stdio\n";
fflush(STDOUT);

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
        continue; // notifications get no reply
    }

    $id = (string) $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'fixture-noisy', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'shout',
                    'description' => 'Answers through stdout noise',
                    'inputSchema' => ['type' => 'object'],
                ]],
            ]]);
            break;

        case 'tools/call':
            if (($msg['params']['name'] ?? null) === 'broken') {
                // OUR id, but neither result nor error: a broken answer, not
                // noise — the client must fail the call, not wait past it.
                $write(['jsonrpc' => '2.0', 'id' => $id]);
                break;
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => 'heard']],
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
