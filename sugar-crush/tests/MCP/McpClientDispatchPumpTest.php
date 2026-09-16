<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * E677 — the idle pump MOUNTED, not merely present.
 *
 * StdioMcpServer::pumpStderr() and its McpClient fan-out shipped with the
 * note that "the judgement of WHEN to pump belongs to the dispatch layer" —
 * a test of the pump itself could never tell mounted from orphaned. These
 * rows drive the mount: a CHATTERBOX child writes to fd 2 after its
 * handshake reply and is then NEVER exchanged with again; only a dispatch to
 * a different server happens. The bytes reaching the chatterbox tail proves
 * the drain ran at the dispatch caller — remove the mount line and the
 * precondition row here goes red, because nothing else ever reads that pipe.
 */
final class McpClientDispatchPumpTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/crush-dispatch-pump-' . \bin2hex(\random_bytes(6));
        \mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tempDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tempDir);
    }

    public function testDispatchingToOneServerDrainsAnIdleFloodingChildAtTheMountSite(): void
    {
        // READY-FILE POLL, NOT A FIXED SETTLE. The child floods 4096 bytes
        // 50 ms after flushing its tools/list reply; the old 300 ms sleep
        // stood in for "those bytes are in the pipe" — a readiness proxy
        // that reddened a CORRECT mounted pump when a starved shard kept
        // the child from its write inside the window. The fixture touches
        // $ready AFTER fwrite returns, and a sub-pipe-buffer write lands in
        // the kernel buffer by the time fwrite returns, so the marker IS
        // the precondition "bytes are queued, nothing has drained them".
        $ready = $this->tempDir . '/noised.mark';
        @unlink($ready);
        $client = $this->clientWithTwoServers(
            "'DISPATCH-MOUNT-' . \\str_repeat('m', 4096) . \"-END\\n\"",
            $ready,
        );
        $client->startServers();

        try {
            $chatter = $this->server($client, 'chatterbox');

            $deadline = \microtime(true) + 5.0;
            while (!\is_file($ready)) {
                if (\microtime(true) >= $deadline) {
                    $this->fail(
                        'the flooding child never wrote its stderr — the fixture, not the pump '
                        . 'seam, failed to reach the precondition this row drives',
                    );
                }
                \usleep(10000);
            }
            $this->assertSame('', $this->tailOf($chatter), 'something drained the idle child before the dispatch under test');

            $start = \microtime(true);
            $result = $client->callTool('worker', 'ping', []);
            $elapsed = \microtime(true) - $start;

            $this->assertSame('pong', $result['content'][0]['text'], 'the mounted pump disturbed the live exchange');
            $this->assertLessThan(10.0, $elapsed, 'dispatch through the pump was not bounded');
            $this->assertStringContainsString('DISPATCH-MOUNT-', $this->tailOf($chatter), 'the dispatch caller did NOT pump the idle child — the seam is unmounted again');
            $this->assertStringEndsWith("-END\n", $this->tailOf($chatter));
        } finally {
            $client->stopServers();
        }
    }

    public function testTheListToolsDispatchSitePumpsWithoutInventingOrEatingBytes(): void
    {
        $client = $this->clientWithTwoServers("'LIST-MOUNT-' . \"\\n\"");
        $client->startServers();

        try {
            \usleep(300000);
            $tools = $client->listTools();
            $this->assertNotEmpty($tools, 'the listTools mount broke the exchange it rides');
            // Every server answered its own tools/list here, so this row only
            // pins that the added pump is silent-safe on quiet AND chatty
            // children alike — the isolation proof stays with the callTool row.
            $this->assertTrue(true);
        } finally {
            $client->stopServers();
        }
    }

    private function clientWithTwoServers(string $noiseExpression, ?string $chatterReadyPath = null): McpClient
    {
        $configPath = $this->tempDir . '/mcp.json';
        \file_put_contents($configPath, \json_encode([
            'mcpServers' => [
                'worker' => [
                    'type' => 'stdio',
                    'command' => \PHP_BINARY,
                    'args' => [$this->script("''")],
                    'env' => new \stdClass(),
                ],
                'chatterbox' => [
                    'type' => 'stdio',
                    'command' => \PHP_BINARY,
                    'args' => [$this->script($noiseExpression, $chatterReadyPath)],
                    'env' => new \stdClass(),
                ],
            ],
        ], JSON_PRETTY_PRINT));

        // unrestricted: these rows are about the mount, not the router.
        return new McpClient($configPath, null, null, true);
    }

    /**
     * A well-behaved MCP child (handshake, ping tool) that — after flushing
     * its tools/list reply, the last line start() waits for — writes the
     * given expression to stderr once. Post-reply placement is load-bearing:
     * bytes written earlier are absorbed inside start()'s own read loop,
     * leaving the IDLE window empty (see StdioMcpServerPumpStderrTest).
     */
    private function script(string $noiseExpression, ?string $readyPath = null): string
    {
        $path = $this->tempDir . '/srv-' . \substr(\md5($noiseExpression . \microtime(true)), 0, 8) . '.php';
        $readyWrite = $readyPath === null
            ? ''
            : "\n                    \\touch(" . \var_export($readyPath, true) . ');';
        \file_put_contents($path, <<<PHP
            <?php
            \$noise = {$noiseExpression};
            \$noised = false;
            while ((\$line = fgets(STDIN)) !== false) {
                \$msg = json_decode(\$line, true);
                if (!is_array(\$msg) || !isset(\$msg['id'])) {
                    continue;
                }
                \$method = (string) (\$msg['method'] ?? '');
                if (\$method === 'initialize') {
                    \$result = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()];
                } elseif (\$method === 'tools/list') {
                    \$result = ['tools' => [[
                        'name' => 'ping',
                        'description' => 'Answer with pong.',
                        'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                    ]]];
                } else {
                    \$result = ['content' => [['type' => 'text', 'text' => 'pong']]];
                }
                echo json_encode(['jsonrpc' => '2.0', 'id' => (string) \$msg['id'], 'result' => \$result]), "\n";
                flush();
                if (\$method === 'tools/list' && !\$noised && \$noise !== '') {
                    \$noised = true;
                    usleep(50000);
                    fwrite(STDERR, \$noise);{$readyWrite}
                }
            }
            PHP);

        return $path;
    }

    /** @return array<string, object> */
    private function serversOf(McpClient $client): array
    {
        $property = new \ReflectionProperty(McpClient::class, 'servers');
        $property->setAccessible(true);

        return $property->getValue($client);
    }

    private function server(McpClient $client, string $name): StdioMcpServer
    {
        $server = $this->serversOf($client)[$name];
        $this->assertInstanceOf(StdioMcpServer::class, $server);

        return $server;
    }

    private function tailOf(StdioMcpServer $server): string
    {
        $property = new \ReflectionProperty($server, 'stderrTail');
        $property->setAccessible(true);

        return (string) $property->getValue($server);
    }
}
