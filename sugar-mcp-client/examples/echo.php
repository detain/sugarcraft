<?php

declare(strict_types=1);

/**
 * echo — a dependency-free round trip: this file is both roles.
 *
 *   php examples/echo.php
 *
 * Run plainly the file acts as the CLIENT: it starts a StdioMcpServer whose
 * command is `php examples/echo.php --serve` — itself — drives
 * initialize → tools/list → tools/call, and prints the exchange. Under
 * `--serve` it acts as the SERVER: a minimal MCP stdio loop advertising one
 * `echo` tool, mirroring tests/Fixtures/echo_server.php. The point is the
 * promise the README makes: the same three constructor arguments as the
 * npx-based Quick start, with zero Node, zero downloads, zero network.
 */

if (in_array('--serve', $argv, true)) {
    exit(serveEcho());
}

require __DIR__ . '/../vendor/autoload.php';
exit(runClient());

/**
 * Server role: newline-delimited JSON-RPC on stdin/stdout, no library
 * imports — the fixture shape a stdio MCP server has to answer.
 */
function serveEcho(): int
{
    $write = static function (array $payload): void {
        echo json_encode($payload), "\n";
        fflush(STDOUT);
    };

    while (($line = fgets(STDIN)) !== false) {
        $msg = json_decode($line, true);
        if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
            continue; // notifications (no id) are never answered
        }

        $id = (string) $msg['id'];

        switch ($msg['method']) {
            case 'initialize':
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'example-echo', 'version' => '1.0.0'],
                ]]);
                break;

            case 'tools/list':
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'tools' => [[
                        'name' => 'echo',
                        'description' => 'Echoes the message argument back as text',
                        'inputSchema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                    ]],
                ]]);
                break;

            case 'tools/call':
                $message = $msg['params']['arguments']['message'] ?? '';
                $write(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => 'echo: ' . $message]],
                ]]);
                break;

            default:
                $write(['jsonrpc' => '2.0', 'id' => $id,
                    'error' => ['code' => -32601, 'message' => 'Method not found']]);
        }
    }

    return 0;
}

/**
 * Client role: the Quick start's three calls against a server that needs
 * nothing installed. Descriptions are server-authored prose, so they go
 * through Sanitize::untrusted() before reaching the terminal — the same law
 * the library documents, modelled here.
 */
function runClient(): int
{
    $server = new SugarCraft\McpClient\StdioMcpServer(
        name: 'echo',
        command: PHP_BINARY,
        args: [__DIR__ . '/echo.php', '--serve'],
    );

    $server->start(); // initialize -> notifications/initialized -> tools/list

    $identity = $server->serverInfo() ?? [];
    echo 'server: ', $identity['name'] ?? '?', ' ', $identity['version'] ?? '', "\n";

    foreach ($server->listTools() as $tool) {
        echo 'tool: ', $tool->name, ' - ', SugarCraft\Core\Util\Sanitize::untrusted($tool->description), "\n";
    }

    $result = $server->callTool('echo', ['message' => 'dependency-free round trip']);

    if (isset($result['error'])) {
        echo 'call failed: ', $result['error'], "\n";
        $server->stop();

        return 1;
    }

    foreach ($result['content'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'text') {
            echo 'reply: ', $item['text'] ?? '', "\n";
        }
    }

    $server->stop(); // also runs automatically on destruct
    echo "OK\n";

    return 0;
}
