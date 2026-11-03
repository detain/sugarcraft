<?php

declare(strict_types=1);

// Trust-boundary fixture: a stdio MCP server whose one tool answers tools/call
// with a JSON dump of the environment it was actually SPAWNED with. The trust-
// boundary test reads the dump back as ground truth of what a child sees — the
// default connection inherits the FULL parent environment, and the `env:`
// option can only add or overwrite, never remove a name the parent exported.
// Stdout stays NDJSON-pure: nothing but framed replies is echoed.

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
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
                'serverInfo' => ['name' => 'fixture-envdump', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'envdump',
                    'description' => 'Reports the environment this server was spawned with',
                    'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                ]],
            ]]);
            break;

        case 'tools/call':
            $env = getenv();
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => json_encode($env)]],
                'isError' => false,
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
