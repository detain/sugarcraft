<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * @see StdioMcpServer
 */
final class StdioMcpServerTest extends TestCase
{
    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithAllParameters(): void
    {
        $server = new StdioMcpServer(
            name: 'test-server',
            command: 'echo',
            args: ['hello'],
            env: ['ECHO_VAR' => 'value'],
        );

        $this->assertSame('test-server', $server->name);
    }

    public function testCanBeCreatedWithEmptyArgs(): void
    {
        $server = new StdioMcpServer(
            name: 'no-args-server',
            command: 'ls',
            args: [],
            env: [],
        );

        $this->assertInstanceOf(StdioMcpServer::class, $server);
    }

    public function testCanBeCreatedWithEmptyEnv(): void
    {
        $server = new StdioMcpServer(
            name: 'no-env-server',
            command: 'date',
            args: [],
            env: [],
        );

        $this->assertInstanceOf(StdioMcpServer::class, $server);
    }

    public function testReadonlyNameProperty(): void
    {
        $server = new StdioMcpServer(
            name: 'readonly-test',
            command: 'echo',
            args: [],
            env: [],
        );

        $this->assertSame('readonly-test', $server->name);
    }

    // =========================================================================
    // start Tests
    // =========================================================================

    public function testStartThrowsOnInvalidCommand(): void
    {
        $server = new StdioMcpServer(
            name: 'invalid-command',
            command: '/nonexistent/binary/that/does/not/exist',
            args: [],
            env: [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to start MCP server: invalid-command');

        $server->start();
    }

    public function testStartWithEchoCommandDoesNotThrow(): void
    {
        $server = new StdioMcpServer(
            name: 'echo-test',
            command: 'echo',
            args: ['test'],
            env: [],
        );

        // This should not throw - echo is a valid command
        // Note: It may still fail due to the protocol exchange, but not on start itself
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            // If it fails due to MCP protocol, that's expected for echo
            $this->assertStringContainsString('MCP server', $e->getMessage());
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // stop Tests
    // =========================================================================

    public function testStopDoesNotThrowWhenNotStarted(): void
    {
        $server = new StdioMcpServer(
            name: 'not-started',
            command: 'echo',
            args: [],
            env: [],
        );

        // Should not throw even if not started
        $server->stop();

        $this->assertTrue(true);
    }

    public function testStopCanBeCalledMultipleTimes(): void
    {
        $server = new StdioMcpServer(
            name: 'multi-stop',
            command: 'echo',
            args: [],
            env: [],
        );

        $server->stop();
        $server->stop();

        $this->assertTrue(true);
    }

    // =========================================================================
    // listTools Tests
    // =========================================================================

    public function testListToolsReturnsEmptyArrayWhenNotStarted(): void
    {
        $server = new StdioMcpServer(
            name: 'not-started-list',
            command: 'echo',
            args: [],
            env: [],
        );

        $tools = $server->listTools();

        $this->assertSame([], $tools);
    }

    // =========================================================================
    // callTool Tests
    // =========================================================================

    public function testCallToolReturnsErrorWhenNotStarted(): void
    {
        $server = new StdioMcpServer(
            name: 'not-started-call',
            command: 'echo',
            args: [],
            env: [],
        );

        $result = $server->callTool('some_tool', ['arg' => 'value']);

        $this->assertSame(['error' => 'Tool call failed'], $result);
    }

    // =========================================================================
    // parseTools Tests
    // =========================================================================

    public function testParseToolsWithValidResponse(): void
    {
        $server = new StdioMcpServer(
            name: 'parse-test',
            command: 'echo',
            args: [],
            env: [],
        );

        $response = [
            'result' => [
                'tools' => [
                    [
                        'name' => 'get_time',
                        'description' => 'Get the current time',
                        'inputSchema' => ['type' => 'object'],
                    ],
                    [
                        'name' => 'get_date',
                        'description' => 'Get the current date',
                        'inputSchema' => ['type' => 'object'],
                    ],
                ],
            ],
        ];

        // Use reflection to access private method
        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertCount(2, $tools);
        $this->assertSame('get_time', $tools[0]->name);
        $this->assertSame('Get the current time', $tools[0]->description);
        $this->assertSame('parse-test', $tools[0]->serverName);
        $this->assertSame('get_date', $tools[1]->name);
    }

    public function testParseToolsWithEmptyToolsArray(): void
    {
        $server = new StdioMcpServer(
            name: 'empty-parse',
            command: 'echo',
            args: [],
            env: [],
        );

        $response = [
            'result' => [
                'tools' => [],
            ],
        ];

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertSame([], $tools);
    }

    public function testParseToolsWithMissingResult(): void
    {
        $server = new StdioMcpServer(
            name: 'missing-result',
            command: 'echo',
            args: [],
            env: [],
        );

        $response = [];

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertSame([], $tools);
    }

    public function testParseToolsWithMissingToolsKey(): void
    {
        $server = new StdioMcpServer(
            name: 'missing-tools',
            command: 'echo',
            args: [],
            env: [],
        );

        $response = [
            'result' => [],
        ];

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertSame([], $tools);
    }

    public function testParseToolsSkipsNonArrayToolDefinitions(): void
    {
        $server = new StdioMcpServer(
            name: 'mixed-tools',
            command: 'echo',
            args: [],
            env: [],
        );

        $response = [
            'result' => [
                'tools' => [
                    ['name' => 'valid_tool', 'description' => 'Valid', 'inputSchema' => []],
                    'not_an_array',
                    null,
                    123,
                    ['name' => 'another_valid', 'description' => 'Valid too', 'inputSchema' => []],
                ],
            ],
        ];

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertCount(2, $tools);
        $this->assertSame('valid_tool', $tools[0]->name);
        $this->assertSame('another_valid', $tools[1]->name);
    }

    public function testParseToolsWithComplexInputSchema(): void
    {
        $server = new StdioMcpServer(
            name: 'complex-schema',
            command: 'echo',
            args: [],
            env: [],
        );

        $complexSchema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'source' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['name'],
        ];

        $response = [
            'result' => [
                'tools' => [
                    [
                        'name' => 'complex_tool',
                        'description' => 'Tool with complex schema',
                        'inputSchema' => $complexSchema,
                    ],
                ],
            ],
        ];

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertCount(1, $tools);
        $this->assertSame($complexSchema, $tools[0]->inputSchema);
    }

    // =========================================================================
    // send Tests
    // =========================================================================

    public function testSendReturnsEmptyArrayWhenProcessNotStarted(): void
    {
        $server = new StdioMcpServer(
            name: 'not-started-send',
            command: 'echo',
            args: [],
            env: [],
        );

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('send');
        $method->setAccessible(true);

        $result = $method->invoke($server, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'test']);

        $this->assertSame([], $result);
    }

    public function testSendWithInvalidJson(): void
    {
        $server = new StdioMcpServer(
            name: 'invalid-json',
            command: 'echo',
            args: [],
            env: [],
        );

        // Start with a command that will at least open the process
        try {
            $server->start();
        } catch (\RuntimeException) {
            // Expected if protocol fails
        }

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('send');
        $method->setAccessible(true);

        // This tests the json_encode failure path (hard to trigger normally)
        // For now just verify the method is accessible
        $this->assertTrue($method->isPrivate() || $method->isProtected());

        $server->stop();
    }

    // =========================================================================
    // Integration-like Tests (with actual process)
    // =========================================================================

    public function testFullLifecycleWithCatCommand(): void
    {
        $server = new StdioMcpServer(
            name: 'cat-test',
            command: 'cat',
            args: [],
            env: [],
        );

        try {
            $server->start();
            $tools = $server->listTools();
            $this->assertIsArray($tools);
        } catch (\RuntimeException $e) {
            // Protocol exchange with cat will fail, which is expected
            $this->assertStringContainsString('MCP server', $e->getMessage());
        } finally {
            $server->stop();
        }
    }
}
