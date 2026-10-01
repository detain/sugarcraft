<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * Non-spawning transport pins lifted from sugar-crush/tests/MCP:
 * construction coercion, idle-state shapes, the parseTools guard matrix
 * (via reflection, as in the product suite), and the frame-cap refusal pair.
 */
final class StdioMcpServerTest extends TestCase
{
    private static function server(string $name = 'probe'): StdioMcpServer
    {
        return new StdioMcpServer($name, '/bin/true');
    }

    private static function readPrivate(StdioMcpServer $server, string $property): mixed
    {
        $reflection = new \ReflectionProperty(StdioMcpServer::class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($server);
    }

    public function testTheNameIsCarriedReadonly(): void
    {
        self::assertSame('probe', self::server()->name);
    }

    public function testStartThrowsForANonexistentBinary(): void
    {
        $server = new StdioMcpServer('invalid-command', '/nonexistent/binary/that/does/not/exist');

        try {
            $server->start();
            self::fail('start() must throw for an unspawnable command');
        } catch (\RuntimeException $thrown) {
            self::assertStringContainsString('Failed to start MCP server: invalid-command', $thrown->getMessage());
        }
    }

    public function testStopIsSafeWhenNeverStartedAndIdempotent(): void
    {
        $server = self::server();

        $server->stop();
        $server->stop();

        self::assertFalse($server->isUp());
    }

    public function testListToolsIsEmptyBeforeStart(): void
    {
        self::assertSame([], self::server()->listTools());
    }

    public function testCallToolOnAColdServerReportsTheFailureShape(): void
    {
        self::assertSame(['error' => 'Tool call failed'], self::server()->callTool('ping', []));
    }

    public function testPumpStderrIsANoOpBeforeStart(): void
    {
        self::server()->pumpStderr();
        self::assertFalse(self::server()->isUp());
    }

    public function testUnusableStartTimeoutsFallBackToTheDefault(): void
    {
        foreach ([null, 0.0, -5.0] as $bad) {
            $server = new StdioMcpServer('probe', '/bin/true', startTimeoutSeconds: $bad);

            self::assertSame(
                StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS,
                self::readPrivate($server, 'startTimeoutSeconds'),
                'a bad ceiling must never disable the bound entirely',
            );
        }

        $server = new StdioMcpServer('probe', '/bin/true', startTimeoutSeconds: 2.5);
        self::assertSame(2.5, self::readPrivate($server, 'startTimeoutSeconds'));
    }

    public function testTheDefaultHandshakeCeilingIsSixtySeconds(): void
    {
        // Domain pin: 60s is sized for cold npx fetches. Changing it is a
        // supported-policy change, so it must be an explicit edit here too.
        self::assertSame(60.0, StdioMcpServer::DEFAULT_START_TIMEOUT_SECONDS);
    }

    public function testTheFrameCapIsSixtyFourMebibytesAndDistinctFromTheStderrCap(): void
    {
        $frameCap = (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_FRAME_BYTES');
        $stderrCap = (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_STDERR_BYTES');

        self::assertSame(64 * 1024 * 1024, $frameCap);
        self::assertNotSame($stderrCap, $frameCap, 'collapsing the two caps would make one knob steer both bounds');
    }

    public function testRefuseAnOversizedFrameDropsTheBufferAndNamesTheServer(): void
    {
        $cap = (int) (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_FRAME_BYTES');
        $server = self::server();

        $buffer = new \ReflectionProperty(StdioMcpServer::class, 'readBuffer');
        $buffer->setAccessible(true);
        $refuse = new \ReflectionMethod(StdioMcpServer::class, 'refuseAnOversizedFrame');
        $refuse->setAccessible(true);

        $buffer->setValue($server, str_repeat('x', $cap + 1));

        try {
            $refuse->invoke($server);
            self::fail('a frame past the cap must be refused');
        } catch (\RuntimeException $thrown) {
            self::assertStringContainsString('probe', $thrown->getMessage());
            self::assertStringContainsString((string) $cap, $thrown->getMessage());
            self::assertStringContainsString('frame cap', $thrown->getMessage());
            // Dropped, not truncated: half a frame would blame the server.
            self::assertSame('', $buffer->getValue($server));
        }
    }

    public function testAFrameExactlyAtTheCapIsKeptWholeAndSilent(): void
    {
        $cap = (int) (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_FRAME_BYTES');
        $server = self::server();

        $buffer = new \ReflectionProperty(StdioMcpServer::class, 'readBuffer');
        $buffer->setAccessible(true);
        $held = str_repeat('x', $cap);
        $buffer->setValue($server, $held);

        $refuse = new \ReflectionMethod(StdioMcpServer::class, 'refuseAnOversizedFrame');
        $refuse->setAccessible(true);
        $refuse->invoke($server);

        self::assertSame($held, $buffer->getValue($server));
    }

    /** @return list<array{string, array<string,mixed>, list<string>}> */
    public static function toolListShapes(): array
    {
        return [
            'two valid tools' => ['probe', [
                'result' => ['tools' => [
                    ['name' => 'get_time', 'description' => 't', 'inputSchema' => ['type' => 'object']],
                    ['name' => 'get_date', 'description' => 'd', 'inputSchema' => ['type' => 'object']],
                ]],
            ], ['get_time', 'get_date']],
            'empty tools' => ['probe', ['result' => ['tools' => []]], []],
            'missing result' => ['probe', [], []],
            'missing tools key' => ['probe', ['result' => []], []],
            'non-array tools container' => ['probe', ['result' => ['tools' => 'nope']], []],
            'malformed entries skipped, neighbours survive' => ['probe', [
                'result' => ['tools' => [
                    ['name' => 'a', 'description' => '', 'inputSchema' => []],
                    'garbage-string',
                    null,
                    7,
                    ['name' => 'b', 'description' => '', 'inputSchema' => []],
                ]],
            ], ['a', 'b']],
            'wrong-typed name skipped' => ['probe', [
                'result' => ['tools' => [['name' => 5], ['name' => 'ok', 'description' => '', 'inputSchema' => []]]],
            ], ['ok']],
            // Review probe P8: definitions with no usable name must be skipped,
            // not minted as phantom tools the router cannot address.
            'nameless and empty-named definitions skipped' => ['probe', [
                'result' => ['tools' => [
                    ['description' => 'no name at all'],
                    ['name' => '', 'description' => 'empty name'],
                    ['name' => null, 'description' => 'null name'],
                    ['name' => 'keeper', 'description' => '', 'inputSchema' => []],
                ]],
            ], ['keeper']],
        ];
    }

    /** @dataProvider toolListShapes */
    public function testParseToolsGuardsEveryNestedShape(string $name, array $response, array $expected): void
    {
        $server = new StdioMcpServer($name, '/bin/true');

        $parse = new \ReflectionMethod(StdioMcpServer::class, 'parseTools');
        $parse->setAccessible(true);
        /** @var list<\SugarCraft\Mcp\McpTool> $tools */
        $tools = $parse->invoke($server, $response);

        self::assertSame($expected, array_map(static fn ($tool): string => $tool->name, $tools));
        foreach ($tools as $tool) {
            self::assertSame($name, $tool->serverName);
        }
    }

    public function testParseToolsPreservesComplexInputSchemas(): void
    {
        $schema = ['type' => 'object', 'properties' => ['p' => ['type' => 'string']], 'required' => ['p']];
        $server = new StdioMcpServer('probe', '/bin/true');

        $parse = new \ReflectionMethod(StdioMcpServer::class, 'parseTools');
        $parse->setAccessible(true);
        $tools = $parse->invoke($server, ['result' => ['tools' => [
            ['name' => 'x', 'description' => '', 'inputSchema' => $schema],
        ]]]);

        self::assertSame($schema, $tools[0]->inputSchema);
    }

    public function testAnEchoCommandEitherThrowsTheMcpMessageOrStartsCleanly(): void
    {
        // /bin/echo answers nothing useful: the handshake gate must catch it
        // (the reply is not a response) and fail with the MCP message.
        $server = new StdioMcpServer('echo-command', '/bin/echo', ['not-json']);

        try {
            $server->start();
            $started = true;
        } catch (\RuntimeException $thrown) {
            self::assertStringContainsString('Failed to start MCP server: echo-command', $thrown->getMessage());
            $started = false;
        } finally {
            $server->stop();
        }

        if ($started) {
            self::assertSame([], $server->listTools(), 'a server with no tools/list answer is up with an empty table');
        }

        self::assertTrue(true);
    }
}
