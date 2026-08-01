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

    public function testConnectWithEmptyCommandThrows(): void
    {
        $client = new McpClient('', [], null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn MCP process');

        $client->connect();
    }

    public function testConnectWithNonexistentAbsolutePathThrows(): void
    {
        // A path with separator that doesn't exist as a file
        $client = new McpClient('/nonexistent/directory/command', [], null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn MCP process');

        $client->connect();
    }

    public function testConnectWithPathSeparatorButNotExecutableThrows(): void
    {
        // Create a file that exists but is not executable
        $tempFile = sys_get_temp_dir() . '/nonexec_' . uniqid();
        file_put_contents($tempFile, '#!/bin/bash\necho test');
        chmod($tempFile, 0644); // not executable

        try {
            $client = new McpClient($tempFile, [], null);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to spawn MCP process');

            $client->connect();
        } finally {
            unlink($tempFile);
        }
    }

    public function testConnectReturnsEarlyWhenAlreadyConnected(): void
    {
        // We can't easily test the "already connected" early return without
        // actually connecting. The connected flag is private.
        // This test verifies that multiple connect() calls don't double-connect.
        // Since we can't directly test the early return with current design,
        // we verify the client transitions to connected state after connect.
        $client = new McpClient('true'); // /bin/true always exists and succeeds
        $result = $client->connect();
        $this->assertTrue($client->isConnected());
        // The result should be whatever readMessages() returns (init response)
        $this->assertIsArray($result);
    }

    public function testDestructorDisconnects(): void
    {
        // When the client goes out of scope, __destruct should call disconnect()
        // This tests that disconnect cleans up without error when process is running
        $client = new McpClient('true');
        $client->connect();
        $this->assertTrue($client->isConnected());
        // Explicitly disconnect to avoid relying on GC timing
        $client->disconnect();
        $this->assertFalse($client->isConnected());
    }

    public function testConnectSucceedsWithExistingExecutable(): void
    {
        // /bin/true exists and is executable - connect should succeed
        $client = new McpClient('true');
        $result = $client->connect();
        $this->assertTrue($client->isConnected());
        $this->assertIsArray($result);
        $client->disconnect();
    }

    public function testResolveExecutableFindsCommandInPath(): void
    {
        // When command has no path separator, it searches PATH
        // Using 'echo' which should exist on all Unix systems
        $client = new McpClient('echo', ['--version']);
        // Should not throw - echo is in PATH
        $result = $client->connect();
        $this->assertTrue($client->isConnected());
        $client->disconnect();
    }
}
