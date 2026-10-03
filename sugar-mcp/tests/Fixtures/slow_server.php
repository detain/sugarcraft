<?php

declare(strict_types=1);

// Slow-tool fixture: answers the MCP handshake and advertises one tool,
// "sleep", whose tools/call sleeps arguments.seconds before answering with
// "slept <n>". Single-threaded on purpose — a request that arrives while a
// call sleeps is read only after it, exactly like a busy real server. With a
// log path as argv[1], every line received is appended to that file, so a
// test can see what the client sent after giving up on a call (the
// notifications/cancelled law).

$log = $argv[1] ?? null;
$born = microtime(true);

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 60.0) {
        exit(0);
    }

    if ($log !== null) {
        file_put_contents($log, $line, FILE_APPEND);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
        continue;
    }

    $id = (string) $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'fixture-slow', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'sleep',
                    'description' => 'Sleeps, then answers',
                    'inputSchema' => ['type' => 'object', 'properties' => ['seconds' => ['type' => 'number']]],
                ]],
            ]]);
            break;

        case 'tools/call':
            $seconds = (float) ($msg['params']['arguments']['seconds'] ?? 0);
            usleep((int) ($seconds * 1_000_000));
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => 'slept ' . $seconds]],
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
