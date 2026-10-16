<?php

declare(strict_types=1);

// Failure-gates fixture for FIX #6: handshakes correctly, then on the FIRST
// tools/call floods the stream with frames no request can be attributed to —
// float ids (un-coercible, stored null), explicit null-id pushes, a batch
// array, a bare garbage line — and never answers. A deadline-less callTool()
// against the pre-fix reader hung forever on exactly this server.
//
// With --recovers it emits ONE FRAME FEW than the refusal threshold
// (7 strikes) and then answers properly: the under-threshold side of the pin,
// proving sloppy-but-eventually-honest servers keep working and any genuine
// reply resets the count (the counter lives per read).

$recovers = in_array('--recovers', $argv, true);
$strikes = $recovers ? 7 : 9; // threshold is 8; 9 crosses it, 7 stays under

$write = static function (array|string $payload): void {
    if (is_string($payload)) {
        echo $payload, "\n";
    } else {
        echo json_encode($payload), "\n";
    }
    fflush(STDOUT);
};

$poisoned = false;

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
                'serverInfo' => ['name' => 'fixture-unattributable', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'echo',
                    'description' => 'Echoes its input (until the flood)',
                    'inputSchema' => ['type' => 'object', 'properties' => ['raw' => []]],
                ]],
            ]]);
            break;

        case 'tools/call':
            if ($poisoned) {
                break; // second and later calls: alive, listening, silent
            }
            $poisoned = true;

            // All five shapes below are unattributable by construction; they
            // cycle so the pin covers both strike branches of readResponse.
            $shapes = [
                '{"jsonrpc":"2.0","id":1.5,"result":{"late":true}}',          // float id -> null
                '{"jsonrpc":"2.0","id":null,"error":{"code":-1,"message":"orphan"}}', // explicit null id
                '[{"jsonrpc":"2.0","id":"1","result":{}}]',                   // batch -> parse null
                'not json-rpc at all',                                        // garbage -> parse null
                '{"jsonrpc":"2.0","result":{"broadcast":true}}',              // missing id member
            ];
            for ($n = 0; $n < $strikes; $n++) {
                $write($shapes[$n % count($shapes)]);
            }

            if ($recovers) {
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => 'recovered']],
                ]]);
            }
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
