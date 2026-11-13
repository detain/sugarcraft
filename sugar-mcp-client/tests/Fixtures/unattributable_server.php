<?php

declare(strict_types=1);

// Failure-gates fixture for the strike law: handshakes correctly, then on
// the FIRST tools/call floods the stream with frames no request can be
// attributed to — float ids (un-coercible, stored null), a missing-id
// result push, a batch array, a bare garbage line — and never answers. A
// deadline-less callTool() against the pre-fix reader hung forever on
// exactly this server.
//
// With --recovers it emits ONE FRAME FEW than the refusal threshold
// (7 strikes) and then answers properly: the under-threshold side of the
// pin, proving sloppy-but-eventually-honest servers keep working and any
// genuine reply resets the count (the counter lives per read).
//
// With --parse-error it emits a single NULL-ID ERROR frame instead — the
// JSON-RPC parse-refusal shape. Since review M8 that is attributed to the
// awaiting exchange (it answers our possibly-malformed write) and must end
// the wait at once, not join the strike bucket.
//
// With --deep-id it floods depth-65 envelopes addressed to the AWAITING id:
// the parser cannot read them for nesting, so the strike-bucket classification
// must win over the false "answered neither result nor error" server blame.
//
// With --progress it floods twelve notifications/progress frames — VALID,
// method-bearing, over the strike threshold — and then answers: the strike
// exemption is by design (answered frames fire the notification handler, they
// do not join the bucket). With --progress --starve the flood is forever and
// the call is never answered: the bounded wait must end with the honest
// no-reply story, never a strike refusal.

$recovers = in_array('--recovers', $argv, true);
$parseError = in_array('--parse-error', $argv, true);
$deepId = in_array('--deep-id', $argv, true);
$progress = in_array('--progress', $argv, true);
$starve = in_array('--starve', $argv, true);
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

            if ($parseError) {
                $write('{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"parse error"}}');
                break; // then alive, listening, silent
            }

            if ($deepId) {
                $deep = 'leaf';
                for ($i = 0; $i < 65; $i++) {
                    $deep = ['n' => $deep];
                }
                for ($n = 0; $n < $strikes; $n++) {
                    $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => $deep]);
                }
                break; // then alive, listening, silent
            }

            if ($progress) {
                $frame = static fn (int $n): string => json_encode([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => ['progressToken' => 'tick', 'progress' => $n],
                ]);

                if ($starve) {
                    // Flood forever (30s orphan backstop): twelve method-
                    // bearing frames are already past the strike ceiling, so
                    // any counting of these would refuse long before a real
                    // deadline could matter.
                    $born = microtime(true);
                    $n = 0;
                    while (microtime(true) - $born < 30.0) {
                        echo $frame($n++), "\n";
                        if (!fflush(STDOUT)) {
                            exit(0); // reader is gone; nothing left to do
                        }
                        usleep(1000);
                    }
                    exit(0);
                }

                for ($n = 0; $n < 12; $n++) { // threshold is 8; twelve cross it
                    $write($frame($n));
                }
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => 'progressed']],
                ]]);
                break;
            }

            // All four shapes below are unattributable by construction; they
            // cycle so the pin covers both strike branches of readResponse.
            // A null-id ERROR is deliberately absent: that frame is a
            // refusal of OUR write, fast-failed by name (review M8), not a
            // strike.
            $shapes = [
                '{"jsonrpc":"2.0","id":1.5,"result":{"late":true}}',          // float id -> null
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
