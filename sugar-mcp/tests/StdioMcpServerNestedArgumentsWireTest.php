<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * Audit MCP-9: a model's `{"filter":{}}` is decoded assoc to
 * `['filter' => []]` long before the transport sees it, and used to go back
 * on the wire as `"filter":[]` — refused by SDK servers whose schema declares
 * `filter` an object. callTool() now shapes the arguments against the tool's
 * advertised inputSchema, so every nested empty object travels as `{}` while
 * a schema-declared array stays `[]`.
 *
 * The nested_args_server fixture decodes without assoc, refuses array-shaped
 * objects, and echoes the exact `arguments` JSON it received.
 */
final class StdioMcpServerNestedArgumentsWireTest extends TestCase
{
    private const BUDGET_SECONDS = 3.0;

    private ?StdioMcpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function started(): StdioMcpServer
    {
        $this->server = new StdioMcpServer(
            'nested',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/nested_args_server.php'],
            startTimeoutSeconds: self::BUDGET_SECONDS,
        );
        $this->server->start();

        return $this->server;
    }

    public function testNestedEmptyObjectsGoOnTheWireAsObjectsAndEmptyArraysStayArrays(): void
    {
        $result = $this->started()->callTool('search', [
            'filter' => [],
            'tags' => [],
            'options' => ['headers' => []],
        ]);

        self::assertArrayNotHasKey('error', $result, 'the SDK-strict server refused an empty object sent as []');
        self::assertSame(
            '{"filter":{},"tags":[],"options":{"headers":{}}}',
            $result['content'][0]['text'] ?? null,
        );
    }

    public function testNonEmptyArgumentsKeepTheirShape(): void
    {
        $result = $this->started()->callTool('search', [
            'filter' => ['status' => 'open'],
            'tags' => ['a', 'b'],
            'options' => ['headers' => ['X-Trace' => '1']],
        ]);

        self::assertSame(
            '{"filter":{"status":"open"},"tags":["a","b"],"options":{"headers":{"X-Trace":"1"}}}',
            $result['content'][0]['text'] ?? null,
        );
    }

    public function testAnUnknownToolGetsNoGuessesOnlyTheTopLevelObject(): void
    {
        // No schema to consult: a nested [] keeps its decoded shape (the
        // fixture then refuses it, which is the honest outcome), while the
        // empty top level still leaves as {} (audit MCP-1).
        $server = $this->started();

        self::assertSame(['error' => 'Tool call failed'], $server->callTool('not-advertised', ['filter' => []]));
        self::assertSame('{}', $server->callTool('not-advertised', [])['content'][0]['text'] ?? null);
    }
}
