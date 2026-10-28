<?php

declare(strict_types=1);

// Test fixture (C-MCP-3): an MCP server that TALKS BACK mid-call.
//
// Answers the handshake, then during the "talk" tool call it pushes the
// server-initiated traffic the client used to skip unanswered forever:
// a `ping` request with an INTEGER wire id, an unknown `audit/nope`
// request with a string id, and a `notifications/progress` notification.
// For each request it reads the client's reply (tolerating stray frames,
// bounded so a client that never answers still finishes) and records what
// came back. The tools/call result text is the JSON of those observations
// — plus the raw initialize capabilities the client declared — so a test
// pins the whole round trip through one callTool result.
// Self-terminates after 30s so a killed test runner can never orphan it.

$born = microtime(true);

/** @var array<string,mixed>|null $initCapabilities */
$initCapabilities = null;

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

/**
 * Read STDIN until a frame carrying $wanted arrives (or $budget burns).
 * Skips everything else — notifications, cancelled notices, late frames.
 *
 * @return array<mixed>|null
 */
$await = static function (int|string $wanted, float $budget): ?array {
    $until = microtime(true) + $budget;
    while (microtime(true) < $until) {
        $line = fgets(STDIN);
        if ($line === false) {
            return null;
        }
        $frame = json_decode($line, true);
        if (is_array($frame) && array_key_exists('id', $frame) && $frame['id'] === $wanted) {
            return $frame;
        }
    }

    return null;
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

    $id = $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
            $initCapabilities = $params['capabilities'] ?? null;
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new stdClass(),
                    'serverInfo' => ['name' => 'fixture-server-requests', 'version' => '0.0.1'],
                ],
            ]);
            break;

        case 'tools/list':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'tools' => [[
                        'name' => 'talk',
                        'description' => 'Replies after pushing server-initiated traffic',
                        'inputSchema' => ['type' => 'object', 'properties' => []],
                    ]],
                ],
            ]);
            break;

        case 'tools/call':
            // 1. ping with an integer wire id — the client must echo id 101
            //    as a number and answer with the empty object.
            $write([
                'jsonrpc' => '2.0',
                'id' => 101,
                'method' => 'ping',
                'params' => new stdClass(),
            ]);
            $pingReply = $await(101, 5.0);
            $ping = [
                'answered' => $pingReply !== null,
                'idWasInt' => $pingReply !== null && $pingReply['id'] === 101,
                'resultWasEmptyObject' => $pingReply !== null
                    && array_key_exists('result', $pingReply)
                    && $pingReply['result'] === [],
            ];

            // 2. unknown request with a string id — default answer is the
            //    -32601 method-not-found error; a handler may replace it.
            $write([
                'jsonrpc' => '2.0',
                'id' => 'sr-1',
                'method' => 'audit/nope',
                'params' => ['probe' => true],
            ]);
            $auditReply = $await('sr-1', 5.0);
            $audit = [
                'answered' => $auditReply !== null,
                'errorCode' => is_array($auditReply['error'] ?? null) ? ($auditReply['error']['code'] ?? null) : null,
                'hadResult' => $auditReply !== null && array_key_exists('result', $auditReply),
            ];

            // 3. progress notification — a frame to absorb, never to answer.
            $write([
                'jsonrpc' => '2.0',
                'method' => 'notifications/progress',
                'params' => ['progressToken' => 'pt-1', 'progress' => 1, 'total' => 2],
            ]);

            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'initializeCapabilities' => $initCapabilities,
                            'ping' => $ping,
                            'audit' => $audit,
                        ]),
                    ]],
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
