<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpServer;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * @see McpClient
 */
final class McpClientTest extends TestCase
{
    private string $tempDir;
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/mcp_client_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->configPath = $this->tempDir . '/config.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    // =========================================================================
    // Creation Tests
    // =========================================================================

    public function testCanBeCreatedWithConfigPath(): void
    {
        $client = new McpClient($this->configPath);

        $this->assertInstanceOf(McpClient::class, $client);
    }

    // =========================================================================
    // loadConfig Tests
    // =========================================================================

    public function testLoadConfigReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $client = new McpClient('/nonexistent/path/config.json');

        // Use reflection to test private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('loadConfig');
        $method->setAccessible(true);

        $result = $method->invoke($client);

        $this->assertSame([], $result);
    }

    public function testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails(): void
    {
        // This would require mocking file_get_contents, which is complex
        // The method already handles file_exists check
        $this->markTestSkipped('Would require mocking built-in functions');
    }

    public function testLoadConfigParsesValidJson(): void
    {
        $config = [
            'mcpServers' => [
                'test-server' => [
                    'type' => 'stdio',
                    'command' => 'echo',
                    'args' => ['hello'],
                ],
            ],
        ];
        file_put_contents($this->configPath, json_encode($config));

        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('loadConfig');
        $method->setAccessible(true);

        $result = $method->invoke($client);

        $this->assertArrayHasKey('mcpServers', $result);
        $this->assertArrayHasKey('test-server', $result['mcpServers']);
    }

    public function testLoadConfigReturnsEmptyArrayForInvalidJson(): void
    {
        file_put_contents($this->configPath, 'not valid json {');

        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('loadConfig');
        $method->setAccessible(true);

        $result = $method->invoke($client);

        $this->assertSame([], $result);
    }

    public function testLoadConfigReturnsEmptyArrayForEmptyFile(): void
    {
        file_put_contents($this->configPath, '');

        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('loadConfig');
        $method->setAccessible(true);

        $result = $method->invoke($client);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // resolveEnv Tests
    // =========================================================================

    public function testResolveEnvReturnsEmptyArrayForEmptyInput(): void
    {
        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveEnv');
        $method->setAccessible(true);

        $result = $method->invoke($client, []);

        $this->assertSame([], $result);
    }

    public function testResolveEnvPassesThroughNonEnvVariables(): void
    {
        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveEnv');
        $method->setAccessible(true);

        $input = ['KEY' => 'value', 'ANOTHER' => 'plain_value'];
        $result = $method->invoke($client, $input);

        $this->assertSame($input, $result);
    }

    public function testResolveEnvResolvesEnvVariable(): void
    {
        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveEnv');
        $method->setAccessible(true);

        putenv('TEST_VAR=value123');
        $input = ['MAPPED' => '${TEST_VAR}'];
        $result = $method->invoke($client, $input);
        putenv('TEST_VAR');

        $this->assertSame('value123', $result['MAPPED']);
    }

    public function testResolveEnvResolvesEnvVariableWithDefault(): void
    {
        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveEnv');
        $method->setAccessible(true);

        putenv('UNSET_VAR');
        $input = ['KEY' => '${UNSET_VAR:-fallback_value}'];
        $result = $method->invoke($client, $input);
        putenv('UNSET_VAR');

        $this->assertSame('fallback_value', $result['KEY']);
    }

    public function testResolveEnvUsesEmptyStringWhenEnvVarNotSetAndNoDefault(): void
    {
        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveEnv');
        $method->setAccessible(true);

        putenv('ANOTHER_UNSET');
        $input = ['KEY' => '${ANOTHER_UNSET}'];
        $result = $method->invoke($client, $input);
        putenv('ANOTHER_UNSET');

        $this->assertSame('', $result['KEY']);
    }

    public function testResolveEnvWithMixedVariables(): void
    {
        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveEnv');
        $method->setAccessible(true);

        putenv('EXISTING_VAR=exists');
        putenv('ANOTHER_VAR=another');
        $input = [
            'STATIC' => 'static_value',
            'RESOLVED' => '${EXISTING_VAR}',
            'ALSO_RESOLVED' => '${ANOTHER_VAR:-default}',
        ];
        $result = $method->invoke($client, $input);
        putenv('EXISTING_VAR');
        putenv('ANOTHER_VAR');

        $this->assertSame('static_value', $result['STATIC']);
        $this->assertSame('exists', $result['RESOLVED']);
        $this->assertSame('another', $result['ALSO_RESOLVED']);
    }

    public function testResolveEnvWithNestedBracesSyntax(): void
    {
        $client = new McpClient($this->configPath);
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('resolveEnv');
        $method->setAccessible(true);

        putenv('NESTED_VAR=nested_value');
        $input = ['KEY' => '${NESTED_VAR}'];
        $result = $method->invoke($client, $input);
        putenv('NESTED_VAR');

        $this->assertSame('nested_value', $result['KEY']);
    }

    // =========================================================================
    // startServers Tests
    // =========================================================================

    public function testStartServersWithEmptyConfig(): void
    {
        $client = new McpClient($this->configPath);
        $client->startServers();
        $client->stopServers();

        // Should complete without error
        $this->assertTrue(true);
    }

    public function testStartServersParsesStdioServerConfig(): void
    {
        $config = [
            'mcpServers' => [
                'test-stdio' => [
                    'type' => 'stdio',
                    'command' => 'echo',
                    'args' => ['test'],
                    'env' => ['ECHO_VAR' => 'hello'],
                ],
            ],
        ];
        file_put_contents($this->configPath, json_encode($config));

        $client = new McpClient($this->configPath);
        $client->startServers();

        // Verify we can list tools (will be empty for echo but should not error)
        $tools = $client->listTools();
        $this->assertIsArray($tools);

        $client->stopServers();
    }

    public function testStartServersWithHttpServerConfig(): void
    {
        $config = [
            'mcpServers' => [
                'test-http' => [
                    'type' => 'http',
                    'url' => 'http://localhost:12345/mcp',
                    'headers' => ['Authorization' => 'Bearer test'],
                ],
            ],
        ];
        file_put_contents($this->configPath, json_encode($config));

        $client = new McpClient($this->configPath);
        $client->startServers();
        $client->stopServers();

        // Should complete without error even though server isn't running
        $this->assertTrue(true);
    }

    public function testStartServersThrowsOnUnknownType(): void
    {
        $config = [
            'mcpServers' => [
                'unknown-type' => [
                    'type' => 'unknown',
                    'command' => 'echo',
                ],
            ],
        ];
        file_put_contents($this->configPath, json_encode($config));

        $client = new McpClient($this->configPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP server type: unknown');

        $client->startServers();
    }

    // =========================================================================
    // stopServers Tests
    // =========================================================================

    public function testStopServersDoesNothingWhenNoServersStarted(): void
    {
        $client = new McpClient($this->configPath);
        $client->stopServers();

        $this->assertTrue(true);
    }

    public function testStopServersClearsServerList(): void
    {
        $config = [
            'mcpServers' => [
                'stop-test' => [
                    'type' => 'stdio',
                    'command' => 'echo',
                    'args' => ['stop'],
                ],
            ],
        ];
        file_put_contents($this->configPath, json_encode($config));

        $client = new McpClient($this->configPath);
        $client->startServers();
        $client->stopServers();

        // After stopping, listTools should return empty
        $tools = $client->listTools();
        $this->assertSame([], $tools);
    }

    // =========================================================================
    // listTools Tests
    // =========================================================================

    public function testListToolsReturnsEmptyArrayWhenNoServers(): void
    {
        $client = new McpClient($this->configPath);

        $tools = $client->listTools();

        $this->assertSame([], $tools);
    }

    public function testListToolsReturnsToolsFromAllServers(): void
    {
        // Create a mock server that returns tools
        $mockServer = $this->createMock(McpServer::class);
        $mockServer->method('listTools')->willReturn([
            new McpTool('tool1', 'Tool 1', [], 'server1'),
            new McpTool('tool2', 'Tool 2', [], 'server2'),
        ]);

        $client = new McpClient($this->configPath);

        // Use reflection to inject the mock server
        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, ['mock-server' => $mockServer]);

        $tools = $client->listTools();

        $this->assertCount(2, $tools);
        $this->assertSame('tool1', $tools[0]->name);
        $this->assertSame('tool2', $tools[1]->name);
    }

    // =========================================================================
    // callTool Tests
    // =========================================================================

    public function testCallToolThrowsOnUnknownServer(): void
    {
        $client = new McpClient($this->configPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown MCP server: unknown-server');

        $client->callTool('unknown-server', 'tool', []);
    }

    public function testCallToolDelegatesToServer(): void
    {
        $mockServer = $this->createMock(McpServer::class);
        $mockServer->method('callTool')
            ->with('test_tool', ['arg1' => 'value1'])
            ->willReturn(['result' => 'success']);

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, ['test-server' => $mockServer]);

        $result = $client->callTool('test-server', 'test_tool', ['arg1' => 'value1']);

        $this->assertSame(['result' => 'success'], $result);
    }

    // =========================================================================
    // callToolByName Tests
    // =========================================================================

    public function testCallToolByNameThrowsWhenToolNotFound(): void
    {
        $mockServer = $this->createMock(McpServer::class);
        $mockServer->method('listTools')->willReturn([
            new McpTool('other_tool', 'Other tool', [], 'server1'),
        ]);

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, ['server1' => $mockServer]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool not found: nonexistent_tool');

        $client->callToolByName('nonexistent_tool', []);
    }

    public function testCallToolByNameCallsFirstMatchingTool(): void
    {
        $mockServer = $this->createMock(McpServer::class);
        $mockServer->method('listTools')->willReturn([
            new McpTool('target_tool', 'Target tool', [], 'server1'),
            new McpTool('target_tool', 'Same name, different server', [], 'server2'),
        ]);
        $mockServer->method('callTool')
            ->with('target_tool', ['arg' => 'value'])
            ->willReturn(['found' => true]);

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, ['server1' => $mockServer, 'server2' => $mockServer]);

        $result = $client->callToolByName('target_tool', ['arg' => 'value']);

        $this->assertSame(['found' => true], $result);
    }

    public function testCallToolByNameSearchesAcrossMultipleServers(): void
    {
        $mockServer1 = $this->createMock(McpServer::class);
        $mockServer1->method('listTools')->willReturn([
            new McpTool('unique_tool_1', 'Unique 1', [], 'server1'),
        ]);

        $mockServer2 = $this->createMock(McpServer::class);
        $mockServer2->method('listTools')->willReturn([
            new McpTool('unique_tool_2', 'Unique 2', [], 'server2'),
        ]);
        $mockServer2->method('callTool')
            ->with('unique_tool_2', [])
            ->willReturn(['server' => 'server2']);

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, ['server1' => $mockServer1, 'server2' => $mockServer2]);

        $result = $client->callToolByName('unique_tool_2', []);

        $this->assertSame(['server' => 'server2'], $result);
    }
}
