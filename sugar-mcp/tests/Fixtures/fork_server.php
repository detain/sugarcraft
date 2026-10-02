<?php

declare(strict_types=1);

// Fork-safety fixture (audit B1 / AG-1). Single-threaded like a real stdio
// server: requests are answered strictly in arrival order.
//   slow  — sleeps 1s, answers "result of slow"
//   fast  — answers "result of fast" at once
//   echo  — sleeps 50-300ms at random, answers "echo:<n>" for argument n
// Lines that are not JSON-RPC are logged to stderr and skipped, which is what
// the TS and Python SDK servers were measured to do with a junk line.
// Bounded: exits on stdin EOF or after 60s, so a broken test cannot leak it.

$born = microtime(true);

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 60.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg)) {
        fwrite(STDERR, 'parse error: ' . substr($line, 0, 80) . "\n");
        continue;
    }
    if (!isset($msg['method'], $msg['id'])) {
        continue;
    }

    $id = $msg['id'];

    switch ($msg['method']) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'fixture-fork', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]];
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [
                ['name' => 'slow', 'description' => 'slow', 'inputSchema' => $schema],
                ['name' => 'fast', 'description' => 'fast', 'inputSchema' => $schema],
                ['name' => 'echo', 'description' => 'echo', 'inputSchema' => $schema],
            ]]]);
            break;

        case 'tools/call':
            $name = $msg['params']['name'] ?? '';
            if ($name === 'slow') {
                usleep(1_000_000);
                $text = 'result of slow';
            } elseif ($name === 'echo') {
                usleep(random_int(50_000, 300_000));
                $text = 'echo:' . ($msg['params']['arguments']['n'] ?? '?');
            } else {
                $text = "result of {$name}";
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => $text]],
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
