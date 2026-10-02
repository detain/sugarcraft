<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Crush\MCP\ClaudeCodeMcpServer;
use SugarCraft\Crush\MCP\HttpMcpServer;

/**
 * Audit MCP-9 on the two transports sugar-crush owns (the stdio one is the
 * sugar-mcp library's, pinned by its StdioMcpServerNestedArgumentsWireTest).
 *
 * A model's `{"filter":{}}` reaches the transport as `['filter' => []]`
 * (every provider parser decodes assoc). Before the fix only the top-level
 * `arguments` was coerced, so the nested map went out as `"filter":[]` and
 * an official-SDK server refused the call. Both rows assert the BYTES that
 * left the process: Guzzle's encoded request body for HTTP, and what a
 * non-assoc-decoding child received for claude-mcp.
 */
final class McpNestedArgumentsWireTest extends TestCase
{
    /** The schema both fake servers advertise for their one tool. */
    private const INPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'filter' => ['type' => 'object', 'properties' => []],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'options' => ['anyOf' => [['$ref' => '#/$defs/Options'], ['type' => 'null']]],
        ],
        '$defs' => ['Options' => [
            'type' => 'object',
            'properties' => ['headers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
        ]],
    ];

    private const ARGUMENTS = ['filter' => [], 'tags' => [], 'options' => ['headers' => []]];

    private const EXPECTED_WIRE = '{"filter":{},"tags":[],"options":{"headers":{}}}';

    private string $workDir = '';

    protected function tearDown(): void
    {
        if ($this->workDir !== '') {
            foreach (glob($this->workDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->workDir);
        }

        parent::tearDown();
    }

    public function testHttpToolsCallBodyCarriesNestedEmptyObjectsAsObjects(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['result' => ['capabilities' => new \stdClass()]])),
            new Response(202),
            new Response(200, [], (string) json_encode(['result' => ['tools' => [
                ['name' => 'search', 'description' => 'nested', 'inputSchema' => self::INPUT_SCHEMA],
            ]]])),
            new Response(200, [], (string) json_encode(['result' => ['content' => [['type' => 'text', 'text' => 'ok']]]])),
        ]));
        $stack->push(Middleware::history($history));

        $server = new HttpMcpServer(
            name: 'nested-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: new Client(['handler' => $stack]),
        );
        $server->start();

        $result = $server->callTool('search', self::ARGUMENTS);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'ok']]], $result);

        $call = null;
        foreach ($history as $transaction) {
            $request = $transaction['request'];
            self::assertInstanceOf(RequestInterface::class, $request);
            $body = (string) $request->getBody();
            if ((json_decode($body)->method ?? null) === 'tools/call') {
                $call = $body;
            }
        }

        self::assertNotNull($call, 'no tools/call request reached the HTTP client');
        self::assertStringContainsString('"arguments":' . self::EXPECTED_WIRE, $call);
    }

    public function testClaudeMcpToolsCallCarriesNestedEmptyObjectsAsObjects(): void
    {
        $this->workDir = sys_get_temp_dir() . '/mcp9_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o700, true);
        $script = $this->workDir . '/responder.php';
        file_put_contents($script, sprintf(<<<'PHP'
            <?php
            $schema = json_decode(%s);
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line); // NOT assoc: {} and [] stay distinct
                if (!$msg instanceof stdClass || !property_exists($msg, 'id')) { continue; }
                $out = ['jsonrpc' => '2.0', 'id' => $msg->id];
                if ($msg->method === 'initialize') {
                    $out['result'] = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
                        'serverInfo' => ['name' => 'fake', 'version' => '0']];
                } elseif ($msg->method === 'tools/list') {
                    $out['result'] = ['tools' => [['name' => 'search', 'description' => 'nested', 'inputSchema' => $schema]]];
                } elseif ($msg->method === 'tools/call') {
                    $out['result'] = ['content' => [['type' => 'text', 'text' => json_encode($msg->params->arguments)]]];
                }
                echo json_encode($out), "\n";
                fflush(STDOUT);
            }
            PHP, var_export((string) json_encode(self::INPUT_SCHEMA), true)) . "\n");

        $server = ClaudeCodeMcpServer::fromGrant('gated', ['type' => ClaudeCodeMcpServer::TYPE], [
            'binary' => PHP_BINARY,
            'args' => [$script],
            'env' => null,
        ]);

        $server->start();
        try {
            $result = $server->callTool('search', self::ARGUMENTS);
        } finally {
            $server->stop();
        }

        self::assertSame(self::EXPECTED_WIRE, $result['content'][0]['text'] ?? null);
    }
}
