<?php

declare(strict_types=1);

// Liveness fixture: a server that forks a helper and then dies mid tool call.
// The helper inherits stdout's write end, so the client's stdout pipe never
// reaches EOF while it lives — exactly the server-with-workers (or wrapper-
// dies-first) shape that turned a deadline-less callTool() into a read that
// only the helper's own exit could end.
//
// argv[1]: a file the helper's pid is written to, so the test can kill it.
// Flags after argv[1], combinable:
// '--chatty': the helper logs to its inherited stderr every 0.2s
// instead of idling silently — the language-server/node-worker shape whose
// chatter keeps the client's select from ever going idle.
// '--die-mid-write': once tools/list is answered the server takes
// one bite of the next request, forks the helper and dies, so a request larger
// than the pipe is left stuck in a WRITE no broken pipe will ever end (the
// helper holds stdin and never reads it).
// The helper sleeps at most 15s on its own, so a killed test runner cannot
// leak it for long. Needs ext-pcntl (the test skips without it).

$pidFile = $argv[1] ?? '';
$flags = array_slice($argv, 2);
$chatty = in_array('--chatty', $flags, true);
$dieMidWrite = in_array('--die-mid-write', $flags, true);

$forkHelperAndDie = static function () use ($pidFile, $chatty): never {
    $helper = pcntl_fork();
    if ($helper === 0) {
        // The helper: holds the inherited stdin/stdout/stderr and idles —
        // silently, or logging to stderr several times per second.
        if (!$chatty) {
            sleep(15);
            exit(0);
        }

        $until = microtime(true) + 15.0;
        while (microtime(true) < $until) {
            fwrite(STDERR, "helper: still working\n");
            usleep(200_000);
        }
        exit(0);
    }

    if ($pidFile !== '') {
        file_put_contents($pidFile, (string) $helper);
    }

    // The direct child dies with the call in flight; the helper lives.
    exit(1);
};
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
                'serverInfo' => ['name' => 'fixture-orphaned-pipe', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'work',
                    'description' => 'Forks a helper, then dies without answering',
                    'inputSchema' => ['type' => 'object'],
                ]],
            ]]);

            if ($dieMidWrite) {
                fread(STDIN, 4096);
                $forkHelperAndDie();
            }
            break;

        case 'tools/call':
            $forkHelperAndDie();
    }
}

exit(0);
