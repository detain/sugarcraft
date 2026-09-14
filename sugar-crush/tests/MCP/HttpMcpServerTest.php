<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SugarCraft\Crush\MCP\AuthEntry;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpAuthStore;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\MCP\OAuthClientRegistration;

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

        // start() makes exactly two posts (initialize + tools/list). The second
        // start() must add NO further posts — exactly(2) proves idempotency since
        // a third/fourth call would fail the expectation.
        $this->mockHttpClient->expects($this->exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
            );

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

        // One expectation governs all three posts: initialize + tools/list (from
        // start) then tools/call (from callTool). Two separate expects() on the
        // same mock method don't sequence — the first cap would reject the third
        // call — so the tools/call shape assertion lives in the callback.
        $this->mockHttpClient->expects($this->exactly(3))
            ->method('post')
            ->with(
                'http://localhost:8080/mcp',
                $this->callback(function ($options) {
                    $method = $options['json']['method'] ?? null;
                    if ($method !== 'tools/call') {
                        return true; // handshake legs pass through unasserted
                    }
                    return $options['json']['params']['name'] === 'test_tool'
                        && $options['json']['params']['arguments'] === ['arg1' => 'value1'];
                })
            )
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
                new Response(200, [], json_encode(['result' => ['output' => 'tool result']])),
            );

        $server->start();

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

        // Single expectation across all posts (start's two + callTool's one).
        // A callback lets the handshake succeed and the third (tools/call) throw —
        // willReturnOnConsecutiveCalls() cannot raise an exception mid-sequence.
        $call = 0;
        $this->mockHttpClient->expects($this->exactly(3))
            ->method('post')
            ->willReturnCallback(function () use (&$call) {
                $call++;
                if ($call === 1) {
                    return new Response(200, [], json_encode(['result' => ['capabilities' => []]]));
                }
                if ($call === 2) {
                    return new Response(200, [], json_encode(['result' => ['tools' => []]]));
                }
                throw new \Exception('Network error');
            });

        $server->start();

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

        // Single expectation across all posts: handshake succeeds, then the
        // tools/call response body is non-JSON (decodes to a non-array).
        $this->mockHttpClient->expects($this->exactly(3))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
                new Response(200, [], 'not json'),
            );

        $server->start();

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

        // Single expectation across all posts: handshake succeeds, then the
        // tools/call response is valid JSON but carries no 'result' key.
        $this->mockHttpClient->expects($this->exactly(3))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode(['result' => ['capabilities' => []]])),
                new Response(200, [], json_encode(['result' => ['tools' => []]])),
                new Response(200, [], json_encode(['error' => 'method not found'])),
            );

        $server->start();

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

    // =========================================================================
    // E695: stored OAuth tokens attach to requests (store was write-only)
    // =========================================================================

    private ?string $e695AuthFile = null;

    protected function tearDown(): void
    {
        if ($this->e695AuthFile !== null && file_exists($this->e695AuthFile)) {
            unlink($this->e695AuthFile);
            rmdir(dirname($this->e695AuthFile));
        }
        $this->e695AuthFile = null;
        parent::tearDown();
    }

    /**
     * A store backed by a throwaway file, with an optional token-endpoint
     * double for the refresh leg.
     */
    private function e695Store(array $responses = []): McpAuthStore
    {
        $dir = sys_get_temp_dir() . '/e695_http_' . uniqid((string) getmypid(), true);
        mkdir($dir, 0700, true);
        $this->e695AuthFile = $dir . '/auth.json';

        return new McpAuthStore(new OAuthClientRegistration(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
            $this->e695AuthFile,
        ));
    }

    /**
     * @param array<string, string> $configHeaders
     * @return array<string, mixed> the captured request options of the FIRST post
     */
    private function e695StartAndCapture(array $configHeaders, ?McpAuthStore $store, string $url = 'http://localhost:9777/mcp'): array
    {
        $captured = null;
        $this->mockHttpClient->method('post')
            ->willReturnCallback(static function (string $uri, array $options) use (&$captured): Response {
                $captured ??= $options;
                $method = $options['json']['method'] ?? '';
                if ($method === 'initialize') {
                    return new Response(200, [], json_encode(['result' => ['capabilities' => []]]));
                }

                return new Response(200, [], json_encode(['result' => ['tools' => []]]));
            });

        $server = new HttpMcpServer(
            name: 'e695',
            url: $url,
            headers: $configHeaders,
            httpClient: $this->mockHttpClient,
            authStore: $store,
        );
        $server->start();

        $this->assertNotNull($captured, 'no request reached the HTTP client');

        return $captured;
    }

    public function testStoredTokenAttachesAsBearerHeaderAlongsideConfiguredOnes(): void
    {
        $store = $this->e695Store();
        $store->oauth()->saveAuth('http://localhost:9777/mcp', new AuthEntry(
            clientId: 'c1',
            clientSecret: '',
            registrationAccessToken: 'r1',
            accessToken: 'stored-access-1',
            refreshToken: '',
            expiresAt: time() + 3600,
            tokenUrl: 'http://localhost:9777/token',
        ));

        $options = $this->e695StartAndCapture(['X-Api-Key' => 'k'], $store);

        $this->assertSame('Bearer stored-access-1', $options['headers']['Authorization']);
        $this->assertSame('k', $options['headers']['X-Api-Key']);
    }

    public function testStaticAuthorizationHeaderWinsOverTheStore(): void
    {
        $store = $this->e695Store();
        $store->oauth()->saveAuth('http://localhost:9777/mcp', new AuthEntry(
            clientId: 'c2',
            clientSecret: '',
            registrationAccessToken: 'r2',
            accessToken: 'ambient-token-must-not-appear',
            refreshToken: 'rt-must-not-refresh',
            expiresAt: time() - 10,
            tokenUrl: 'http://localhost:9777/token',
        ));

        $options = $this->e695StartAndCapture(['authorization' => 'Static operator-header'], $store);

        $this->assertSame('Static operator-header', $options['headers']['authorization']);
        $this->assertArrayNotHasKey('Authorization', $options['headers']);
    }

    public function testServerWithoutAuthStoreSendsConfiguredHeadersByteIdentical(): void
    {
        $options = $this->e695StartAndCapture(['X-Api-Key' => 'k', 'Accept' => 'application/json'], null);

        $this->assertSame(['X-Api-Key' => 'k', 'Accept' => 'application/json'], $options['headers']);
    }

    public function testServerWithStoreHoldingNoEntrySendsConfiguredHeadersByteIdentical(): void
    {
        $options = $this->e695StartAndCapture(['X-Api-Key' => 'k'], $this->e695Store());

        $this->assertSame(['X-Api-Key' => 'k'], $options['headers']);
    }

    public function testExpiredTokenIsRefreshedThroughTheStoreBeforeAttaching(): void
    {
        $store = $this->e695Store([new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'rotated-access-3',
            'refresh_token' => 'rotated-refresh-3',
            'expires_in' => 3600,
        ]))]);
        $store->oauth()->saveAuth('http://localhost:9777/mcp', new AuthEntry(
            clientId: 'c3',
            clientSecret: '',
            registrationAccessToken: 'r3',
            accessToken: 'stale-access-3',
            refreshToken: 'old-refresh-3',
            expiresAt: time() - 5,
            tokenUrl: 'http://localhost:9777/token',
        ));

        $options = $this->e695StartAndCapture([], $store);

        $this->assertSame('Bearer rotated-access-3', $options['headers']['Authorization']);
        $fresh = (new OAuthClientRegistration(new Client(), $this->e695AuthFile))->loadAuth();
        $this->assertSame('rotated-access-3', $fresh['http://localhost:9777/mcp']->accessToken);
    }

    public function testTokenBytesNeverReachFailureMessages(): void
    {
        $store = $this->e695Store();
        $store->oauth()->saveAuth('http://localhost:9777/mcp', new AuthEntry(
            clientId: 'c4',
            clientSecret: '',
            registrationAccessToken: 'r4',
            accessToken: 'secret-access-must-stay-hidden',
            refreshToken: '',
            expiresAt: time() - 5,
            tokenUrl: 'http://localhost:9777/token',
        ));

        try {
            $server = new HttpMcpServer(
                name: 'e695-leak-check',
                url: 'http://localhost:9777/mcp',
                headers: [],
                httpClient: $this->mockHttpClient,
                authStore: $store,
            );
            $server->start();
            $this->fail('expected the un-refreshable entry to fail the start');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('e695-leak-check', $e->getMessage());
            $this->assertStringNotContainsString('secret-access-must-stay-hidden', $e->getMessage());
        }
    }
}
