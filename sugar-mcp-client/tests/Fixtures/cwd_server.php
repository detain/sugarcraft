<?php

declare(strict_types=1);

// Spawn-directory fixture: answers the MCP handshake, advertises a "where"
// tool, and for tools/call returns the child process's own getcwd() — the
// ground truth of where proc_open actually put it, read from inside the child
// rather than remembered by the parent. With --die it exits before answering
// anything: a spawn whose child cannot be talked to must surface as the
// transport's start failure, cwd or no cwd.

$born = microtime(true);

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

if (in_array('--die', $argv, true)) {
    exit(7);
}

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
        continue;
    }

    $id = (string) $msg['id'];
    $here = (string) getcwd();

    switch ($msg['method']) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'serverInfo' => ['name' => 'fixture-cwd', 'version' => $here],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'where',
                    'description' => 'Reports the working directory this server process starts in',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                ]],
            ]]);
            break;

        case 'tools/call':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => $here]],
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
