#!/usr/bin/env php
<?php
/**
 * Hostile-plugin fixture — answers a mode given in argv[1], each mode
 * exercising one arm of the ExternalModule trust boundary:
 *
 *  garbage   init handshake is honest; every later response is an
 *            unparsable wire line carrying terminal control bytes.
 *  flood     init handshake is honest; every later response is a single
 *            MAX_LINE_BYTES-sized run with NO newline (runaway plugin).
 *  env       init response echoes whether the parent's canary variable
 *            survived into this child's environment (leak canary).
 *  deaf      never reads stdin, never writes stdout, sleeps forever —
 *            the host handshake must time out bounded, not hang.
 *  name      init response advertises a control-byte-laden, oversized name.
 *  counter   honest handshake; each view response carries a render counter
 *            ("render #K") that increments per wire round-trip, so callers
 *            can see whether a view() was served from the host's cache.
 *  poison    honest handshake; every update reply carries raw invalid-UTF-8
 *            bytes (a lone surrogate \xED\xA0\x80) in the wire line — the
 *            finding-008 landmine, refused by the PHP ≥ 8.3 JSON parser.
 *  surrogate honest handshake; every update reply carries the ASCII
 *            \ud800 ESCAPE form — the finding's literal probe vector,
 *            likewise refused at decode on supported PHP.
 */

declare(strict_types=1);

$mode = substr(($argv[1] ?? '--mode=garbage'), 7); // strip the '--mode=' prefix

if ($mode === 'deaf') {
    // Never reads, never answers; the host must give up on its own.
    sleep(60);
    exit(0);
}

/** Answer an init request honestly (env/name modes bend the advertised name). */
function greet(int $interval = 0): void
{
    global $mode;

    $name = 'hostile-plugin';
    if ($mode === 'env') {
        $canary = getenv('DASHBOARD_TEST_LEAK_CANARY');
        $name = $canary === false ? 'canary-absent' : 'canary=' . $canary;
    }
    if ($mode === 'name') {
        $name = "\x1b[31;1mHOSTILE\x1b[0m\x{e000}zone\x{e001}" . str_repeat('X', 300);
    }

    fwrite(STDOUT, json_encode([
        'type' => 'init',
        'data' => ['minSize' => [30, 4], 'interval' => $interval, 'name' => $name],
    ]) . "\n");
}

if ($mode === 'counter') {
    $renders = 0;
    while (($line = fgets(STDIN)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $request = json_decode($line, true);
        if (!is_array($request)) {
            continue;
        }
        $type = $request['type'] ?? 'unknown';
        $data = $request['data'] ?? [];

        if ($type === 'init') {
            greet();
        } elseif ($type === 'update') {
            fwrite(STDOUT, json_encode(['type' => 'update', 'data' => ['state' => $data['state'] ?? []]]) . "\n");
        } elseif ($type === 'view') {
            $renders++;
            fwrite(STDOUT, json_encode([
                'type' => 'view',
                'data' => ['content' => 'render #' . $renders . ' tick:' . ($data['state']['tick'] ?? 0)],
            ]) . "\n");
        }
    }
    exit(0);
}

if ($mode === 'poison') {
    // sdx-008: reply with raw invalid-UTF-8 bytes in the state. A
    // json_encode of that state throws under JSON_THROW_ON_ERROR on the
    // next send — unless the store seam sanitises it at the trust
    // boundary. Emitted as raw bytes because the child's own encoder
    // (correctly) refuses to build such a string.
    while (($line = fgets(STDIN)) !== false) {
        $request = json_decode(trim($line), true);
        $type = $request['type'] ?? '';
        if ($type === 'init') {
            greet();
            continue;
        }
        if ($type === 'update') {
            fwrite(STDOUT, '{"type":"update","data":{"state":{"note":"pre\xED\xA0\x80post"}}}' . "\n");
            continue;
        }
        if ($type === 'view') {
            fwrite(STDOUT, json_encode(['type' => 'view', 'data' => ['content' => 'poison survived']]) . "\n");
        }
    }
    exit(0);
}

if ($mode === 'surrogate') {
    // sdx-008 literal probe vector: an ASCII-only wire line carrying the
    // \ud800 escape. json_decode refuses unpaired surrogates on supported
    // PHP, so the line is a protocol error — bounded, not a brick.
    while (($line = fgets(STDIN)) !== false) {
        $request = json_decode(trim($line), true);
        $type = $request['type'] ?? '';
        if ($type === 'init') {
            greet();
            continue;
        }
        if ($type === 'update') {
            fwrite(STDOUT, '{"type":"update","data":{"state":{"note":"pre\\ud800post"}}}' . "\n");
            continue;
        }
        if ($type === 'view') {
            fwrite(STDOUT, json_encode(['type' => 'view', 'data' => ['content' => 'survived']]) . "\n");
        }
    }
    exit(0);
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);
    if (!is_array($request)) {
        continue;
    }

    $type = $request['type'] ?? 'unknown';

    if ($type === 'init') {
        greet();
        continue;
    }

    switch ($mode) {
        case 'garbage':
            // Not JSON at all, and laced with escape sequences.
            fwrite(STDOUT, "{\"type\":\x1b[31;1mnever-parses\x1b[0m}\n");
            break;

        case 'flood':
            // Exactly the read cap in bytes, no newline, then silence.
            fwrite(STDOUT, str_repeat('F', 1048576));
            fflush(STDOUT);
            sleep(10);
            break;

        default:
            fwrite(STDOUT, json_encode(['type' => 'error', 'data' => ['message' => 'no such mode']]) . "\n");
    }
}
