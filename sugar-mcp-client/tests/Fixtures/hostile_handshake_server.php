<?php

declare(strict_types=1);

/**
 * Fixture server whose handshake and tool answers each commit one protocol
 * sin the client must catch (review M2/M6/M13):
 *
 *  --lie-version     answers initialize with protocolVersion "2099-01-01"
 *                    (client speaks 2024-11-05 and must refuse the start).
 *  --omit-version    answers initialize with NO protocolVersion at all —
 *                    a legacy server the client must still boot.
 *  --both-shapes     answers tools/call with result AND error in one frame
 *                    (forbidden by JSON-RPC; the client must report the
 *                    REFUSAL, never the sweet-looking result).
 *  --osc8            answers tools/call with an error whose message carries
 *                    an OSC-8 hyperlink + BEL, and stderr carrying CSI —
 *                    every control byte must be swept from diagnostics.
 *  --latin1          writes a raw Latin-1 (invalid UTF-8) stderr line —
 *                    the /u sweep refuses it, so the diagnostic must fall
 *                    back to a byte-wise ASCII sweep + marker, never blank.
 *  --slow-handshake  sleeps 1.4 s before answering initialize, so a
 *                    start(onWait:) beat must fire at least once inside it.
 *  --bad-tool-name   advertises a tool whose name carries a raw NUL
 *                    byte (gate skips it and the malformed-count rail
 *                    announces it; the boot survives with `echo`).
 *  --noisy           floods 70 KiB of plain stderr at startup, then behaves:
 *                    the tail (and its truncation flag) must survive stop().
 *
 * NDJSON on stdout, one JSON-RPC frame per line, exactly like echo_server.
 */
$mode = $argv[1] ?? '';
$born = microtime(true);

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

if ($mode === '--noisy') {
    fwrite(STDERR, str_repeat('diagnostic noise 0123456789abcdef ', 3000)); // ~90 KiB > 64 KiB cap
}

if ($mode === '--osc8') {
    fwrite(STDERR, "fatal \x1b[31mred\x1b[0m\r\n");
}

if ($mode === '--latin1') {
    fwrite(STDERR, "cause: caf\xe9 exploded \x80\x07\n");
}

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
        continue;
    }

    $id = $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $result = [
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'hostile-fixture', 'version' => '0'],
            ];
            if ($mode !== '--omit-version') {
                $result['protocolVersion'] = $mode === '--lie-version' ? '2099-01-01' : '2024-11-05';
            }
            if ($mode === '--slow-handshake') {
                usleep(1400000);
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
            break;

        case 'tools/list':
            $good = [
                'name' => 'echo',
                'description' => 'echo raw',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            ];
            if ($mode === '--bad-tool-name') {
                // A server-authored NUL in the name: the well-typedness gate
                // skips it, and the malformed-count rail announces the skip
                // while the boot proceeds with the well-formed sibling.
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [
                    ['name' => 'bad' . chr(0) . 'name', 'description' => 'poison', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
                    $good,
                ]]]);
                break;
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [$good]]]);
            break;

        case 'tools/call':
            $args = $msg['params']['arguments'] ?? [];
            if ($mode === '--both-shapes') {
                $write(['jsonrpc' => '2.0', 'id' => $id,
                    'result' => ['content' => [['type' => 'text', 'text' => 'looks fine']]],
                    'error' => ['code' => -32603, 'message' => 'actually broken'],
                ]);
                break;
            }

            if ($mode === '--osc8') {
                $write(['jsonrpc' => '2.0', 'id' => $id,
                    'error' => ['code' => -32603, 'message' => "a\x1b]8;;http://evil\x07b"],
                ]);
                break;
            }

            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [[
                'type' => 'text',
                'text' => (string) ($args['raw'] ?? ''),
            ]]]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]);
            break;
    }
}
