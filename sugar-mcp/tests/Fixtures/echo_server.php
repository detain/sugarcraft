<?php

declare(strict_types=1);

// Round-trip fixture: answers the MCP handshake, advertises an "echo" tool,
// and for tools/call echoes arguments.raw back VERBATIM as the result (scalar
// and null coverage for the callTool wrapping law) — or a standard content
// envelope when raw is absent. With --flood it first writes ~200KiB to
// stderr: a server our client must drain during the handshake wait, or the
// 64KiB kernel pipe deadlocks the child before it ever answers.

$flood = in_array('--flood', $argv, true);
if ($flood) {
    fwrite(STDERR, str_repeat('diagnostic noise 0123456789abcdef', 8192)); // ~224KiB
}

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
    if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
        continue;
    }

    $id = (string) $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'fixture-echo', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'echo',
                    'description' => 'Echoes its input',
                    'inputSchema' => ['type' => 'object', 'properties' => ['raw' => []]],
                ]],
            ]]);
            break;

        case 'tools/call':
            $args = $msg['params']['arguments'] ?? [];
            if (array_key_exists('raw', $args)) {
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => $args['raw']]);
            } else {
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => 'echoed']],
                ]]);
            }
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
