<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpServer;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\Permissions\PermissionMode;

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

    /**
     * Build an AgentPreset with only the fields relevant to routing tests.
     *
     * @param array<string> $mcpServers
     */
    private function makePreset(array $mcpServers = []): AgentPreset
    {
        return new AgentPreset(
            name: 'test-preset',
            description: 'Test preset for routing',
            tools: [],
            disallowedTools: [],
            model: 'inherit',
            permissionMode: PermissionMode::Default,
            maxTurns: null,
            skills: [],
            mcpServers: $mcpServers,
            memory: MemoryScope::User,
            background: false,
            effort: Effort::Medium,
            isolation: null,
            color: null,
            initialPrompt: null,
        );
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

    public function testStartServersWithGitServerConfig(): void
    {
        $config = [
            'mcpServers' => [
                'test-git' => [
                    'type' => 'git',
                    'path' => null,
                ],
            ],
        ];
        file_put_contents($this->configPath, json_encode($config));

        $client = new McpClient($this->configPath);
        $client->startServers();

        // Verify we can list tools - GitMcpServer provides 29 tools
        $tools = $client->listTools();
        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);

        // Verify git-specific tools are present
        $toolNames = array_map(fn(McpTool $t) => $t->name, $tools);
        $this->assertContains('gitStatus', $toolNames);
        $this->assertContains('gitCommit', $toolNames);
        $this->assertContains('gitBranchList', $toolNames);
        $this->assertContains('gitLog', $toolNames);

        $client->stopServers();
    }

    public function testStartServersWithGitServerConfigAndPath(): void
    {
        // Use the repo root as a valid git repo path
        $config = [
            'mcpServers' => [
                'test-git' => [
                    'type' => 'git',
                    'path' => __DIR__ . '/../../',
                ],
            ],
        ];
        file_put_contents($this->configPath, json_encode($config));

        $client = new McpClient($this->configPath);
        $client->startServers();

        $tools = $client->listTools();
        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);

        $client->stopServers();
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

    // =========================================================================
    // Per-agent MCP routing enforcement (R15)
    //
    // These prove enforcement through McpClient itself -- not merely through
    // McpRouter tested in isolation -- by driving a client wired with both an
    // "allowed" and a "denied" server, restricting it to an agent preset that
    // only names the allowed server, and asserting the denied server's tools
    // are neither listed nor reachable via callTool()/callToolByName().
    // =========================================================================

    public function testListToolsHidesToolsFromServersOutsideAgentAllowlist(): void
    {
        $allowedServer = $this->createMock(McpServer::class);
        $allowedServer->method('listTools')->willReturn([
            new McpTool('allowed_tool', 'Allowed', [], 'allowed-server'),
        ]);

        $deniedServer = $this->createMock(McpServer::class);
        $deniedServer->method('listTools')->willReturn([
            new McpTool('secret_tool', 'Secret', [], 'denied-server'),
        ]);
        // If enforcement only happened at listTools() call sites further up the
        // stack (and not inside McpClient), this would still be invoked.
        $deniedServer->expects($this->never())->method('callTool');

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, [
            'allowed-server' => $allowedServer,
            'denied-server' => $deniedServer,
        ]);

        $client->setAgentPreset($this->makePreset(['allowed-server']));

        $tools = $client->listTools();

        $this->assertCount(1, $tools);
        $this->assertSame('allowed_tool', $tools[0]->name);
        $names = array_map(fn(McpTool $t) => $t->name, $tools);
        $this->assertNotContains('secret_tool', $names);
    }

    public function testCallToolThrowsWhenServerOutsideAgentAllowlist(): void
    {
        $allowedServer = $this->createMock(McpServer::class);
        $deniedServer = $this->createMock(McpServer::class);
        // Naming the denied server directly must not reach its callTool(),
        // even though it is a genuine, started server the client knows about.
        $deniedServer->expects($this->never())->method('callTool');

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, [
            'allowed-server' => $allowedServer,
            'denied-server' => $deniedServer,
        ]);

        $client->setAgentPreset($this->makePreset(['allowed-server']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP server not allowed for this agent: denied-server');

        $client->callTool('denied-server', 'secret_tool', []);
    }

    public function testCallToolByNameCannotReachToolOnDeniedServer(): void
    {
        $allowedServer = $this->createMock(McpServer::class);
        $allowedServer->method('listTools')->willReturn([
            new McpTool('allowed_tool', 'Allowed', [], 'allowed-server'),
        ]);

        $deniedServer = $this->createMock(McpServer::class);
        $deniedServer->method('listTools')->willReturn([
            new McpTool('secret_tool', 'Secret', [], 'denied-server'),
        ]);
        // The denied server would happily serve this tool if ever reached --
        // proving the tool is genuinely unreachable, not merely unlisted.
        $deniedServer->expects($this->never())->method('callTool');

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, [
            'allowed-server' => $allowedServer,
            'denied-server' => $deniedServer,
        ]);

        $client->setAgentPreset($this->makePreset(['allowed-server']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool not found: secret_tool');

        $client->callToolByName('secret_tool', []);
    }

    public function testCallToolByNameStillReachesToolOnAllowedServer(): void
    {
        $allowedServer = $this->createMock(McpServer::class);
        $allowedServer->method('listTools')->willReturn([
            new McpTool('allowed_tool', 'Allowed', [], 'allowed-server'),
        ]);
        $allowedServer->method('callTool')
            ->with('allowed_tool', [])
            ->willReturn(['ok' => true]);

        $deniedServer = $this->createMock(McpServer::class);
        $deniedServer->method('listTools')->willReturn([
            new McpTool('secret_tool', 'Secret', [], 'denied-server'),
        ]);

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, [
            'allowed-server' => $allowedServer,
            'denied-server' => $deniedServer,
        ]);

        $client->setAgentPreset($this->makePreset(['allowed-server']));

        $result = $client->callToolByName('allowed_tool', []);

        $this->assertSame(['ok' => true], $result);
    }

    public function testListToolsIsUnrestrictedWhenNoAgentPresetIsSet(): void
    {
        // Regression guard: a client with no agent preset attached (the
        // pre-fix default, and still correct for non-agent-scoped callers)
        // keeps seeing every configured server's tools.
        $serverA = $this->createMock(McpServer::class);
        $serverA->method('listTools')->willReturn([
            new McpTool('tool_a', 'A', [], 'server-a'),
        ]);
        $serverB = $this->createMock(McpServer::class);
        $serverB->method('listTools')->willReturn([
            new McpTool('tool_b', 'B', [], 'server-b'),
        ]);

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, ['server-a' => $serverA, 'server-b' => $serverB]);

        $tools = $client->listTools();

        $this->assertCount(2, $tools);
    }

    public function testSetAgentPresetCanBeClearedBackToUnrestricted(): void
    {
        $allowedServer = $this->createMock(McpServer::class);
        $allowedServer->method('listTools')->willReturn([
            new McpTool('allowed_tool', 'Allowed', [], 'allowed-server'),
        ]);
        $deniedServer = $this->createMock(McpServer::class);
        $deniedServer->method('listTools')->willReturn([
            new McpTool('secret_tool', 'Secret', [], 'denied-server'),
        ]);

        $client = new McpClient($this->configPath);

        $reflection = new \ReflectionClass($client);
        $property = $reflection->getProperty('servers');
        $property->setAccessible(true);
        $property->setValue($client, [
            'allowed-server' => $allowedServer,
            'denied-server' => $deniedServer,
        ]);

        $client->setAgentPreset($this->makePreset(['allowed-server']));
        $this->assertCount(1, $client->listTools());

        $client->setAgentPreset(null);
        $this->assertCount(2, $client->listTools());
    }
}
