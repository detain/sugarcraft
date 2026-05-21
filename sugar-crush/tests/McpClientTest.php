<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\McpClient;
use SugarCraft\Crush\McpMessage;
use PHPUnit\Framework\TestCase;

final class McpClientTest extends TestCase
{
    public function testConstruction(): void
    {
        $client = new McpClient('claude', ['--mcp'], ['capabilities' => ['tools' => true]]);

        $this->assertSame('claude', $client->command);
        $this->assertSame(['--mcp'], $client->args);
        $this->assertSame(['capabilities' => ['tools' => true]], $client->initialOptions);
        $this->assertFalse($client->isConnected());
    }

    public function testForClaudeCodeDefaults(): void
    {
        $client = McpClient::forClaudeCode();

        $this->assertSame('claude', $client->command);
        $this->assertSame(['--mcp'], $client->args);
        $this->assertFalse($client->isConnected());
    }

    public function testForClaudeCodeWithOptions(): void
    {
        $options = ['timeout' => 30];
        $client = McpClient::forClaudeCode($options);

        $this->assertSame($options, $client->initialOptions);
    }

    public function testConnectThrowsWhenProcessFails(): void
    {
        $client = new McpClient('nonexistent-command-xyz', [], null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn MCP process');

        $client->connect();
    }

    public function testDisconnectWhenNotConnectedIsNoOp(): void
    {
        $client = new McpClient();
        $client->disconnect(); // should not throw
        $this->assertFalse($client->isConnected());
    }

    public function testCallToolThrowsWhenNotConnected(): void
    {
        $client = new McpClient();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP client not connected');

        $client->callTool('test_tool');
    }

    public function testListToolsThrowsWhenNotConnected(): void
    {
        $client = new McpClient();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP client not connected');

        $client->listTools();
    }

    public function testSendMessageThrowsWhenNotConnected(): void
    {
        $client = new McpClient();
        $msg = McpMessage::notification('test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP client not connected');

        $client->sendMessage($msg);
    }

    public function testReadMessagesWhenNotConnectedReturnsEmpty(): void
    {
        $client = new McpClient();
        $this->assertSame([], $client->readMessages());
    }

    public function testForClaudeCodeSetsCorrectCommandAndArgs(): void
    {
        $client = McpClient::forClaudeCode(['protocolVersion' => '2024-11-05']);

        $this->assertSame('claude', $client->command);
        $this->assertCount(1, $client->args);
        $this->assertSame('--mcp', $client->args[0]);
        $this->assertNotNull($client->initialOptions);
        $this->assertSame('2024-11-05', $client->initialOptions['protocolVersion']);
    }

    public function testInitialOptionsDefaults(): void
    {
        $client = new McpClient();
        $this->assertNull($client->initialOptions);

        $client2 = new McpClient('claude');
        $this->assertNull($client2->initialOptions);
    }
}
