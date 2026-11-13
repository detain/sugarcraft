<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * The nested-object wire audit: a model's `{"filter":{}}` is decoded assoc to
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

    public function testAPositionalArgumentsListRefusesAtTheClientDoorNotTheServer(): void
    {
        $server = $this->started();

        // A non-empty list has no object reading under any schema repair —
        // every conforming server answers the JSON array shape with -32602.
        // The door must refuse BEFORE a byte is written, with the client's
        // own words: the fixture's refusal ('expected object, received
        // array') must never be the one the caller sees.
        $startedAt = microtime(true);
        $result = $server->callTool('not-advertised', ['positional']);
        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(1.0, $elapsed, 'a list of arguments reached the wire and paid a round trip');
        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('got a list', (string) $result['error']);
        self::assertStringNotContainsString('-32602', (string) $result['error'], 'the refusal came from the server, not the door');

        // Nothing went on the wire: the session stays in step.
        self::assertSame('{}', $server->callTool('not-advertised', [])['content'][0]['text'] ?? null);
    }

    public function testAnUnknownToolGetsNoGuessesOnlyTheTopLevelObject(): void
    {
        // No schema to consult: a nested [] keeps its decoded shape (the
        // fixture then refuses it, which is the honest outcome), while the
        // empty top level still leaves as {} (the top-level object law).
        $server = $this->started();

        // Pin flipped 2026-11-04 (fix wave, review M1): the SDK server's
        // -32602 refusal now travels in full instead of three words.
        $error = (string) ($server->callTool('not-advertised', ['filter' => []])['error'] ?? '');
        self::assertStringContainsString('tools/call refused (-32602)', $error);
        self::assertStringContainsString('expected object, received array', $error);
        self::assertSame('{}', $server->callTool('not-advertised', [])['content'][0]['text'] ?? null);
    }
}
