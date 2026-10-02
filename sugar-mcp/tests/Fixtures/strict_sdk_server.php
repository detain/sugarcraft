<?php

declare(strict_types=1);

// Wire-shape fixture modelled on the official MCP SDK servers (audit MCP-1).
// It decodes WITHOUT assoc so a JSON `{}` and a JSON `[]` stay distinct, then
// refuses array-shaped objects exactly the way the SDKs were measured to:
//   - initialize with `capabilities` (or params) as an array → -32603
//     "expected object, received array", like @modelcontextprotocol/server-everything;
//   - tools/list with `params` as an array → NO id-bearing reply at all, like
//     both SDKs (the client can only time out);
//   - tools/call with `arguments` as an array → -32603 "expected record,
//     received array".
// Every notification's method name is appended to the file named by argv[1],
// so a test can pin the exact name the client sent.
// With --refuse-init it answers initialize with a JSON-RPC error (and writes a
// stderr line first), the shape of a server rejecting the session outright.
// Self-terminates after 30s so a killed test runner can never orphan it.

$notificationLog = $argv[1] ?? null;
$refuseInit = in_array('--refuse-init', $argv, true);
$born = microtime(true);

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

$reject = static function (string $id, string $message) use ($write): void {
    $write(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $message]]);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line);
    if (!$msg instanceof stdClass || !isset($msg->method) || !is_string($msg->method)) {
        continue;
    }

    if (!property_exists($msg, 'id')) {
        if ($notificationLog !== null) {
            file_put_contents($notificationLog, $msg->method . "\n", FILE_APPEND);
        }
        continue;
    }

    $id = (string) $msg->id;
    $params = $msg->params ?? null;

    switch ($msg->method) {
        case 'initialize':
            if ($refuseInit) {
                fwrite(STDERR, "strict-sdk: refusing this session\n");
                fflush(STDERR);
                $write(['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                    'code' => -32602,
                    'message' => 'Unsupported protocol version: fixture refusal',
                ]]);
                break;
            }
            if (!$params instanceof stdClass) {
                $reject($id, 'params: expected object, received array');
                break;
            }
            if (!isset($params->capabilities) || !$params->capabilities instanceof stdClass) {
                $reject($id, 'params.capabilities: expected object, received array');
                break;
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'fixture-strict-sdk', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            if ($params !== null && !$params instanceof stdClass) {
                break; // the SDKs' measured behaviour: no reply carrying our id
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'tools' => [[
                    'name' => 'get-tiny-image',
                    'description' => 'Takes no arguments',
                    'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                ]],
            ]]);
            break;

        case 'tools/call':
            if (!$params instanceof stdClass) {
                $reject($id, 'params: expected object, received array');
                break;
            }
            if (property_exists($params, 'arguments') && !$params->arguments instanceof stdClass) {
                $reject($id, 'params.arguments: expected record, received array');
                break;
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => 'tiny']],
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
