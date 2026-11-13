<?php

declare(strict_types=1);

// Test fixture for the handler-credit thread (the deadline-by-reference law):
// an MCP server that talks back during the INITIALIZE leg.
//
// On `initialize` it first pushes its own `ping` request (string wire id),
// reads the client's answer (bounded), and only then answers initialize. On
// `tools/list` it sleeps argv[1] seconds before handing out a well-typed
// roster. The paired test arms a handler that sleeps and a start budget that
// the RAW wall clock of both legs would overrun: only the credited deadline —
// extended across the leg boundary because the handshake threads $deadline by
// reference — lets the start succeed.
// Self-terminates after 30s so a killed test runner can never orphan it.

$born = microtime(true);
$toolsListSleep = isset($argv[1]) ? max(0.0, (float) $argv[1]) : 0.0;

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method']) || !array_key_exists('id', $msg)) {
        continue; // notification or malformed — nothing to answer
    }

    $id = $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            // Talk back BEFORE answering: the client's connection-wide handler
            // answers (slowly), and the wall clock it spends is the very time
            // the credit law owes back to the handshake budget.
            $write(['jsonrpc' => '2.0', 'id' => 'fixture-credit-ping', 'method' => 'ping']);
            $until = microtime(true) + 10.0;
            while (microtime(true) < $until) {
                $reply = fgets(STDIN);
                if ($reply === false) {
                    exit(0);
                }
                $frame = json_decode($reply, true);
                if (is_array($frame) && ($frame['id'] ?? null) === 'fixture-credit-ping') {
                    break;
                }
            }
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new stdClass(),
                    'serverInfo' => ['name' => 'fixture-credentialed-handshake', 'version' => '0.0.1'],
                ],
            ]);
            break;

        case 'tools/list':
            if ($toolsListSleep > 0) {
                usleep((int) ($toolsListSleep * 1_000_000));
            }
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => ['tools' => [[
                    'name' => 'credentialed',
                    'description' => 'the roster behind the credited leg',
                    'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                ]]],
            ]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()]);
    }
}
