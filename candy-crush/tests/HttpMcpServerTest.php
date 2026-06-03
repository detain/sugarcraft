<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpTool;

/**
 * @see HttpMcpServer
 */
final class HttpMcpServerTest extends TestCase
{
    private MockObject&Client $mockHttpClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockHttpClient = $this->createMock(Client::class);
    }

    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithAllParameters(): void
    {
        $server = new HttpMcpServer(
            name: 'http-test',
            url: 'http://localhost:8080/mcp',
            headers: ['Authorization' => 'Bearer token123'],
            httpClient: $this->mockHttpClient,
        );

        $this->assertSame('http-test', $server->name);
    }

    public function testCanBeCreatedWithEmptyHeaders(): void
    {
        $server = new HttpMcpServer(
            name: 'no-headers',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $this->assertInstanceOf(HttpMcpServer::class, $server);
    }

    public function testReadonlyNameProperty(): void
    {
        $server = new HttpMcpServer(
            name: 'readonly-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $this->assertSame('readonly-http', $server->name);
    }

    // =========================================================================
    // start Tests
    // =========================================================================

    public function testStartInitializesServerAndListsTools(): void
    {
        $server = new HttpMcpServer(
            name: 'init-test',
            url: 'http://localhost:8080/mcp',
            headers: ['X-API-Key' => 'test'],
            httpClient: $this->mockHttpClient,
        );

        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode([
                    'result' => [
                        'tools' => [
                            ['name' => 'api_tool', 'description' => 'API tool', 'inputSchema' => []],
                        ],
                    ],
                ])),
            );

        $server->start();

        $tools = $server->listTools();
        $this->assertCount(1, $tools);
        $this->assertSame('api_tool', $tools[0]->name);
        $this->assertSame('init-test', $tools[0]->serverName);
    }

    public function testStartIsIdempotent(): void
    {
        $server = new HttpMcpServer(
            name: 'idempotent-start',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $this->mockHttpClient->expects($this->once())
            ->method('post')
            ->willReturn(new Response(200, [], json_encode(['result' => ['capabilities' => []]])));

        $server->start();
        $server->start(); // Should not call HTTP again

        $this->assertTrue(true);
    }

    public function testStartThrowsOnInitializeFailure(): void
    {
        $server = new HttpMcpServer(
            name: 'init-fail',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $this->mockHttpClient->method('post')
            ->willThrowException(new \Exception('Connection refused'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to start MCP server init-fail: Connection refused');

        $server->start();
    }

    public function testStartThrowsOnListToolsFailure(): void
    {
        $server = new HttpMcpServer(
            name: 'list-fail',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(500, [], 'Internal Server Error'),
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to start MCP server list-fail');

        $server->start();
    }

    // =========================================================================
    // stop Tests
    // =========================================================================

    public function testStopDoesNothing(): void
    {
        $server = new HttpMcpServer(
            name: 'http-stop',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        // Should not throw
        $server->stop();

        $this->assertTrue(true);
    }

    public function testStopCanBeCalledMultipleTimes(): void
    {
        $server = new HttpMcpServer(
            name: 'multi-stop-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
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
        $server = new HttpMcpServer(
            name: 'not-started-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $tools = $server->listTools();

        $this->assertSame([], $tools);
    }

    public function testListToolsReturnsCachedTools(): void
    {
        $server = new HttpMcpServer(
            name: 'cached-tools',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode([
                    'result' => [
                        'tools' => [
                            ['name' => 'cached_tool', 'description' => 'Cached', 'inputSchema' => []],
                        ],
                    ],
                ])),
            );

        $server->start();

        // First call
        $tools1 = $server->listTools();
        // Second call should use cache
        $tools2 = $server->listTools();

        $this->assertCount(1, $tools1);
        $this->assertCount(1, $tools2);
        $this->assertSame('cached_tool', $tools1[0]->name);
        $this->assertSame($tools1, $tools2);
    }

    // =========================================================================
    // callTool Tests
    // =========================================================================

    public function testCallToolMakesHttpRequest(): void
    {
        $server = new HttpMcpServer(
            name: 'call-http',
            url: 'http://localhost:8080/mcp',
            headers: ['Content-Type' => 'application/json'],
            httpClient: $this->mockHttpClient,
        );

        // Start the server first to set initialized flag
        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
            );

        $server->start();

        // Now mock the callTool request
        $this->mockHttpClient->expects($this->once())
            ->method('post')
            ->with(
                'http://localhost:8080/mcp',
                $this->callback(function ($options) {
                    return isset($options['json']['method'])
                        && $options['json']['method'] === 'tools/call'
                        && $options['json']['params']['name'] === 'test_tool'
                        && $options['json']['params']['arguments'] === ['arg1' => 'value1'];
                })
            )
            ->willReturn(new Response(200, [], json_encode([
                'result' => ['output' => 'tool result'],
            ])));

        $result = $server->callTool('test_tool', ['arg1' => 'value1']);

        $this->assertSame(['output' => 'tool result'], $result);
    }

    public function testCallToolReturnsErrorOnException(): void
    {
        $server = new HttpMcpServer(
            name: 'call-error',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        // Start the server
        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
            );

        $server->start();

        // Mock exception on tool call
        $this->mockHttpClient->method('post')
            ->willThrowException(new \Exception('Network error'));

        $result = $server->callTool('failing_tool', []);

        $this->assertSame(['error' => 'Network error'], $result);
    }

    public function testCallToolReturnsErrorOnInvalidResponse(): void
    {
        $server = new HttpMcpServer(
            name: 'invalid-response',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        // Start the server
        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
            );

        $server->start();

        // Return non-array response
        $this->mockHttpClient->method('post')
            ->willReturn(new Response(200, [], 'not json'));

        $result = $server->callTool('test_tool', []);

        $this->assertSame(['error' => 'Invalid response'], $result);
    }

    public function testCallToolReturnsErrorOnMissingResult(): void
    {
        $server = new HttpMcpServer(
            name: 'missing-result',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        // Start the server
        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
            );

        $server->start();

        // Return response without result
        $this->mockHttpClient->method('post')
            ->willReturn(new Response(200, [], json_encode(['error' => 'method not found'])));

        $result = $server->callTool('test_tool', []);

        $this->assertSame(['error' => 'Tool call failed'], $result);
    }

    // =========================================================================
    // parseTools Tests
    // =========================================================================

    public function testParseToolsWithValidResponse(): void
    {
        $server = new HttpMcpServer(
            name: 'parse-http',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $response = [
            'result' => [
                'tools' => [
                    [
                        'name' => 'http_tool',
                        'description' => 'An HTTP tool',
                        'inputSchema' => ['type' => 'object'],
                    ],
                ],
            ],
        ];

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertCount(1, $tools);
        $this->assertSame('http_tool', $tools[0]->name);
        $this->assertSame('An HTTP tool', $tools[0]->description);
        $this->assertSame('parse-http', $tools[0]->serverName);
    }

    public function testParseToolsWithEmptyToolsArray(): void
    {
        $server = new HttpMcpServer(
            name: 'empty-http-parse',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
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
        $server = new HttpMcpServer(
            name: 'missing-http-result',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
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
        $server = new HttpMcpServer(
            name: 'missing-http-tools',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
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
        $server = new HttpMcpServer(
            name: 'mixed-http-tools',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $response = [
            'result' => [
                'tools' => [
                    ['name' => 'valid_http', 'description' => 'Valid', 'inputSchema' => []],
                    'string_tool',
                    null,
                    ['name' => 'another_valid_http', 'description' => 'Also valid', 'inputSchema' => []],
                ],
            ],
        ];

        $reflection = new \ReflectionClass($server);
        $method = $reflection->getMethod('parseTools');
        $method->setAccessible(true);

        $tools = $method->invoke($server, $response);

        $this->assertCount(2, $tools);
        $this->assertSame('valid_http', $tools[0]->name);
        $this->assertSame('another_valid_http', $tools[1]->name);
    }

    public function testParseToolsWithComplexSchema(): void
    {
        $server = new HttpMcpServer(
            name: 'complex-http-schema',
            url: 'http://localhost:8080/mcp',
            headers: [],
            httpClient: $this->mockHttpClient,
        );

        $complexSchema = [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'filters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                    ],
                ],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'required' => ['query'],
        ];

        $response = [
            'result' => [
                'tools' => [
                    [
                        'name' => 'search_tool',
                        'description' => 'Search with filters',
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
    // Headers Resolution Tests
    // =========================================================================

    public function testHeadersArePassedToHttpRequests(): void
    {
        $server = new HttpMcpServer(
            name: 'header-test',
            url: 'http://localhost:8080/mcp',
            headers: [
                'Authorization' => 'Bearer abc123',
                'X-Custom-Header' => 'custom-value',
            ],
            httpClient: $this->mockHttpClient,
        );

        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->with(
                'http://localhost:8080/mcp',
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization'])
                        && $options['headers']['Authorization'] === 'Bearer abc123'
                        && $options['headers']['X-Custom-Header'] === 'custom-value';
                })
            )
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
            );

        $server->start();
    }
}
