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

    /**
     * A stdio MCP server must outlive the handshake. `cat` is the smallest
     * command that does: it holds its stdin open until EOF, so disconnect()'s
     * fclose() is what ends it — no timeout, no orphan, no scheduling race.
     *
     * The obvious choices are `true` and `echo`, and both are wrong here.
     * They exit immediately, so connect()'s handshake fwrite() races the
     * kernel closing the pipe's read end. Lose that race and the write gets
     * EPIPE — "fwrite(): Write of 52 bytes failed with errno=32 Broken pipe" —
     * which phpunit.xml's failOnWarning="true" turns into a failure, and
     * sendMessage() then throws so the isConnected() assertions never run.
     * Win it and everything passes. That is the whole flake.
     */
    private const LIVE_SERVER = 'cat';

    public function testConnectReturnsEarlyWhenAlreadyConnected(): void
    {
        $client = new McpClient(self::LIVE_SERVER);
        $client->connect();
        $this->assertTrue($client->isConnected());

        // The early return is observable two ways: it answers [] rather than
        // readMessages(), and it leaves the child alone. Assert the second —
        // a respawn would replace the process handle even if both calls
        // happened to return an empty array.
        $handle = new \ReflectionProperty(McpClient::class, 'process');
        $before = $handle->getValue($client);

        $this->assertSame([], $client->connect());
        $this->assertSame($before, $handle->getValue($client));

        $client->disconnect();
    }

    public function testDestructorDisconnects(): void
    {
        // __destruct() delegates to disconnect(); this pins that disconnect()
        // cleans up without error while the child is still running.
        $client = new McpClient(self::LIVE_SERVER);
        $client->connect();
        $this->assertTrue($client->isConnected());
        // Explicitly disconnect to avoid relying on GC timing
        $client->disconnect();
        $this->assertFalse($client->isConnected());
    }

    public function testConnectSucceedsWithExistingExecutable(): void
    {
        $client = new McpClient(self::LIVE_SERVER);
        $result = $client->connect();
        $this->assertTrue($client->isConnected());
        $this->assertIsArray($result);
        $client->disconnect();
    }

    public function testResolveExecutableFindsCommandInPath(): void
    {
        // A command with no path separator is resolved against PATH.
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR, self::LIVE_SERVER);

        $client = new McpClient(self::LIVE_SERVER, ['-u']);
        $client->connect();
        $this->assertTrue($client->isConnected());
        $client->disconnect();
    }
}
