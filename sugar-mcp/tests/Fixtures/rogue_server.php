<?php

declare(strict_types=1);

// Round-trip fixture for the review P7 pin: it SPONTANEOUSLY broadcasts a
// `result` frame carrying NO id before every genuine answer — the shape of a
// server pushing unsolicited traffic (or one whose id was un-coercible). The
// transport must skip every id-less broadcast and match only the exact-id
// response; before the fix, the first broadcast was accepted as whatever
// response the client happened to be waiting for, desynchronising the stream.

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
        continue; // notifications: silent, like any well-behaved server
    }

    $id = (string) $msg['id'];

    $write(['jsonrpc' => '2.0', 'result' => ['broadcast' => true]]); // NO id — must be skipped

    switch ($msg['method']) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'fixture-rogue', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'real',
                    'description' => 'The only tool this rogue advertises',
                    'inputSchema' => ['type' => 'object'],
                ]],
            ]]);
            break;

        case 'tools/call':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => 'echoed']],
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
