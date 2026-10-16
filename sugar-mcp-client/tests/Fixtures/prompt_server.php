<?php

declare(strict_types=1);

// Minimal well-behaved MCP server for tests: answers initialize, tools/list
// (one "ping" tool) and tools/call ping→pong; unknown methods get -32601;
// notifications are consumed silently. Self-terminates after 30s so a killed
// test runner can never leave it orphaned.

$born = microtime(true);

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        continue;
    }

    if (!isset($msg['id'])) {
        continue; // notification — nothing to answer
    }

    $id = (string) $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new stdClass(),
                    'serverInfo' => ['name' => 'fixture-prompt', 'version' => '0.0.1'],
                ],
            ]);
            break;

        case 'tools/list':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'tools' => [[
                        'name' => 'ping',
                        'description' => 'Responds with pong',
                        'inputSchema' => ['type' => 'object', 'properties' => []],
                    ]],
                ],
            ]);
            break;

        case 'tools/call':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'pong']],
                ],
            ]);
            break;

        default:
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ]);
    }
}

exit(0);
