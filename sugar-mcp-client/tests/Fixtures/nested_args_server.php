<?php

declare(strict_types=1);

// Wire-shape fixture for audit MCP-9: an SDK-strict server whose tool declares
// NESTED object positions. It decodes WITHOUT assoc so `{}` and `[]` stay
// distinct, refuses `filter`/`options.headers` arriving as arrays the way the
// official SDKs do ("expected object, received array"), and otherwise answers
// with the exact JSON of the `arguments` it received, so a test can pin the
// bytes the client put on the wire. Self-terminates after 30s.

$born = microtime(true);

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line);
    if (!$msg instanceof stdClass || !isset($msg->method) || !property_exists($msg, 'id')) {
        continue;
    }

    $id = $msg->id;

    switch ($msg->method) {
        case 'initialize':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'fixture-nested-args', 'version' => '0.0.1'],
            ]]);
            break;

        case 'tools/list':
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [[
                'name' => 'search',
                'description' => 'Object and array positions, nested',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'filter' => ['type' => 'object', 'properties' => new stdClass()],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'options' => ['$ref' => '#/$defs/Options'],
                    ],
                    '$defs' => [
                        'Options' => [
                            'type' => 'object',
                            'properties' => [
                                'headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                            ],
                        ],
                    ],
                ],
            ]]]]);
            break;

        case 'tools/call':
            $args = $msg->params->arguments ?? null;
            $bad = !$args instanceof stdClass
                || (property_exists($args, 'filter') && !$args->filter instanceof stdClass)
                || (isset($args->options->headers) && !$args->options->headers instanceof stdClass);
            if ($bad) {
                $write(['jsonrpc' => '2.0', 'id' => $id, 'error' => [
                    'code' => -32602,
                    'message' => 'Invalid arguments: expected object, received array; got ' . json_encode($args),
                ]]);
                break;
            }
            $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'content' => [['type' => 'text', 'text' => json_encode($args)]],
            ]]);
            break;

        default:
            $write(['jsonrpc' => '2.0', 'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found']]);
    }
}

exit(0);
