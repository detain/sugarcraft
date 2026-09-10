<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * E440 — the idle-stderr pump on {@see StdioMcpServer} and its forwarding
 * sibling on {@see McpClient}.
 *
 * WHAT WAS SAID: bytes a server writes to stderr BETWEEN exchanges sit in the
 * pipe until some later exchange's select loop drains them, so the diagnostic
 * tail is stale exactly when nobody is talking to the server.
 * WHAT IS TRUE NOW: `pumpStderr()` absorbs them on demand; these tests are the
 * behaviour contract, and the loop-mount alternative stays refused per E537 —
 * see the pump's own docblock; do not reopen it here or against
 * LspConnection, which shipped the same seam first.
 * WHY THESE ROWS EARN THEIR PLACE: the pump's whole risk surface is silence —
 * a version that read nothing, blocked forever, or ate stdout would keep every
 * EXISTING test green. Each row therefore asserts a POSITIVE byte outcome
 * (marker bytes in the tail) or pins a bound (elapsed, cap), and the
 * stdout-intact follow-up call rules out the pump having consumed a reply.
 */
final class StdioMcpServerPumpStderrTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/crush-pump-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    public function testThePumpAbsorbsBytesWrittenWhileTheServerSitsIdle(): void
    {
        $server = $this->serverWith($this->script("'PUMPMARK-' . str_repeat('p', 4096) . \"-END\\n\""));
        $server->start();

        try {
            // Give the child time to see the initialized notification and write;
            // the parent reads NOTHING in this window — no exchange is in flight,
            // which is precisely the state the pump exists for.
            usleep(300000);

            $server->pumpStderr();

            $this->assertStringContainsString('PUMPMARK-', $this->tailOf($server));
            $this->assertStringEndsWith("-END\n", $this->tailOf($server));
            // STDOUT SURVIVES THE PUMP: fd 2 only. A pump that grabbed the
            // response stream would fail HERE, not silently.
            $this->assertSame('pong', $server->callTool('ping', [])['content'][0]['text']);
        } finally {
            $server->stop();
        }
    }

    public function testThePumpReturnsPromptlyWhenTheServerHasNothingToSay(): void
    {
        $server = $this->serverWith($this->script("''"));
        $server->start();

        try {
            $start = microtime(true);
            $server->pumpStderr();
            $elapsed = microtime(true) - $start;

            // Generous wall bound, no scheduling assumption: the point is that
            // the pump CANNOT block on a quiet fd 2. A blocking-read regression
            // parks here until the child dies, so even 2s says the story.
            $this->assertLessThan(2.0, $elapsed, 'pumpStderr() blocked on an idle stderr pipe');
            $this->assertSame('', $this->tailOf($server));
        } finally {
            $server->stop();
        }
    }

    public function testThePumpIsSilentBeforeStartAndAfterStop(): void
    {
        // THE TEARDOWN-RACE CONTRACT: stop() nulls the pipes, __destruct and
        // late dispatch layers may still hold the handle. Both ends must no-op
        // rather than throw — the guards inside absorbStderr() answer for it.
        $unstarted = $this->serverWith($this->script("''"));
        $unstarted->pumpStderr();
        $this->assertSame('', $this->tailOf($unstarted));

        $server = $this->serverWith($this->script("'BYE'"));
        $server->start();
        $server->stop();
        $server->pumpStderr();
        $this->assertSame('', $this->tailOf($server), 'a stopped server kept (or invented) tail bytes');
    }

    public function testThePumpIsBoundedAndKeepsTheTailAcrossFloods(): void
    {
        // 200000 bytes: past ONE pump's 16 x 8192 = 131072 absorption budget and
        // past the 64 KiB tail cap, so a single call must return bounded, two
        // calls must drain the child completely, and the cap must have evicted
        // the head while keeping the very last marker.
        $flood = "str_repeat('F', 200000) . 'ENDMARK'";
        $server = $this->serverWith($this->script($flood));
        $server->start();

        try {
            usleep(300000);

            $start = microtime(true);
            $server->pumpStderr();
            $firstElapsed = microtime(true) - $start;
            $this->assertLessThan(3.0, $firstElapsed, 'the first pump pass was not bounded');

            $tail = $this->tailOf($server);
            $this->assertLessThanOrEqual(65536, strlen($tail), 'the cap was not applied');
            $this->assertStringNotContainsString('ENDMARK', $tail, 'one pass absorbed more than its budget');

            $server->pumpStderr();
            $tail = $this->tailOf($server);
            $this->assertStringEndsWith('ENDMARK', $tail, 'the cap kept the head instead of the tail');
            // The child was never wedged by our boundedness: it can still answer.
            $this->assertSame('pong', $server->callTool('ping', [])['content'][0]['text']);
        } finally {
            $server->stop();
        }
    }

    public function testTheClientPumpForwardsToEveryStartedStdioServer(): void
    {
        $configPath = $this->tempDir . '/mcp.json';
        file_put_contents($configPath, json_encode([
            'mcpServers' => [
                'pumpy' => [
                    'type' => 'stdio',
                    'command' => PHP_BINARY,
                    'args' => [$this->script("'CLIENT-PUMPED'")],
                    'env' => new \stdClass(),
                ],
            ],
        ], JSON_PRETTY_PRINT));

        $client = new McpClient($configPath);
        $client->startServers();

        try {
            usleep(300000);
            $client->pumpStderr();

            $servers = (new \ReflectionProperty(McpClient::class, 'servers'))->getValue($client);
            $this->assertCount(1, $servers);
            $server = array_values($servers)[0];
            $this->assertInstanceOf(StdioMcpServer::class, $server);
            $this->assertStringContainsString('CLIENT-PUMPED', $this->tailOf($server));
        } finally {
            $client->stopServers();
        }

        // Empty set after stopServers(): the forward loop must simply return.
        $client->pumpStderr();
        $this->assertTrue(true, 'reaching here is the assertion');
    }

    /**
     * A well-behaved MCP child: answers the handshake, and AFTER flushing its
     * `tools/list` reply — the last line the parent's start() waits for — waits
     * 50ms and writes %s (a PHP expression yielding a string) unprompted to
     * stderr, then goes quiet. Post-reply and delayed, not notification-
     * triggered: MEASURED, a child that answers `initialized` with noise gets
     * that noise absorbed INSIDE start()'s tools/list read loop (which drains
     * fd 2 unconditionally when ready — the E442 wedge fix), leaving nothing
     * for the idle pump to demonstrate. Bytes written after the final reply
     * can only arrive in the IDLE window the pump owns.
     */
    private function script(string $noiseExpression): string
    {
        $path = $this->tempDir . '/srv-' . substr(md5($noiseExpression), 0, 8) . '.php';
        file_put_contents($path, <<<PHP
            <?php
            \$noise = {$noiseExpression};
            \$noised = false;
            while ((\$line = fgets(STDIN)) !== false) {
                \$msg = json_decode(\$line, true);
                if (!is_array(\$msg)) {
                    continue;
                }
                \$method = (string) (\$msg['method'] ?? '');
                if (!isset(\$msg['id'])) {
                    continue;
                }
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
                    fwrite(STDERR, \$noise);
                }
            }
            PHP);

        return $path;
    }

    private function serverWith(string $script): StdioMcpServer
    {
        return new StdioMcpServer(
            name: 'pump',
            command: PHP_BINARY,
            args: [$script],
            env: [],
            startTimeoutSeconds: 10.0,
        );
    }

    private function tailOf(StdioMcpServer $server): string
    {
        $property = new \ReflectionProperty($server, 'stderrTail');
        $property->setAccessible(true);

        return (string) $property->getValue($server);
    }
}
