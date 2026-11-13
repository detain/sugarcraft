<?php

declare(strict_types=1);

// Test fixture: a well-behaved MCP server that MIRRORS what the client
// actually sent. Captures the initialize params (clientInfo rides there) and
// answers the "mirror" tool call with their JSON, plus the value of the
// MCP_PROBE_ENV environment variable — so a test can pin, end to end, which
// identity and which environment the spawned child really received.
// Self-terminates after 30s so a killed test runner can never orphan it.

$born = microtime(true);

/** @var array<string,mixed>|null $initParams */
$initParams = null;

$write = static function (array $payload): void {
    echo json_encode($payload), "\n";
    fflush(STDOUT);
};

while (($line = fgets(STDIN)) !== false) {
    if (microtime(true) - $born > 30.0) {
        exit(0);
    }

    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        continue;
    }

    if (!isset($msg['id'])) {
        continue; // notification — nothing to answer
    }

    $id = (string) $msg['id'];
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    switch ($msg['method']) {
        case 'initialize':
            $initParams = is_array($msg['params'] ?? null) ? $msg['params'] : [];
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new stdClass(),
                    'serverInfo' => ['name' => 'fixture-mirror', 'version' => '0.0.1'],
                ],
            ]);
            break;

        case 'tools/list':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'tools' => [[
                        'name' => 'mirror',
                        'description' => 'Echoes the captured initialize params',
                        'inputSchema' => ['type' => 'object', 'properties' => []],
                    ]],
                ],
            ]);
            break;

        case 'tools/call':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'initializeParams' => $initParams,
                            'probeEnv' => getenv('MCP_PROBE_ENV'),
                            'secretPresent' => getenv('MCP_TEST_SECRET') !== false,
                        ]),
                    ]],
                ],
            ]);
            break;

        case 'resources/list':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'resources' => [[
                        'uri' => 'file:///notes/today.md',
                        'name' => 'Today',
                        'mimeType' => 'text/plain',
                    ]],
                ],
            ]);
            break;

        case 'resources/read':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'contents' => [[
                        'uri' => $params['uri'] ?? '',
                        'mimeType' => 'text/plain',
                        'text' => 'body of the resource',
                    ]],
                ],
            ]);
            break;

        case 'prompts/list':
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'prompts' => [[
                        'name' => 'recap',
                        'description' => 'Recap the session',
                        'arguments' => [['name' => 'lang', 'required' => true]],
                    ]],
                ],
            ]);
            break;

        case 'prompts/get':
            // Strict like a real SDK server: `arguments` must arrive as a
            // JSON object, never a (despite the repair law) bare list.
            $wire = json_decode($line);
            $wireArgs = $wire->params->arguments ?? null;
            if ($wireArgs !== null && !is_object($wireArgs)) {
                $write([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32602, 'message' => 'expected object, received array'],
                ]);
                break;
            }
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'description' => 'Recap the session',
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => 'recap language: ' . (is_array($arguments) ? (string) ($arguments['lang'] ?? '?') : '?'),
                        ],
                    ]],
                ],
            ]);
            break;

        default:
            $write([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ]);
    }
}

exit(0);
