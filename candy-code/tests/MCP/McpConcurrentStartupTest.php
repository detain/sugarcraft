<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;

/**
 * Startup used to spawn and handshake one server at a time, so N cold `npx`
 * servers stacked their start times in front of the first frame. The startup
 * state machine launches every handshake before waiting on any of them, and
 * the blocking wrapper must still hand back the same finished server set.
 */
final class McpConcurrentStartupTest extends TestCase
{
    /** Seconds each fixture server holds its `initialize` reply. */
    private const DELAY = 2.0;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/sc_concurrent_startup_' . \bin2hex(\random_bytes(6));
        \mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tempDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tempDir);
    }

    public function testTwoSlowServersStartInLessThanTheSumOfTheirDelays(): void
    {
        $client = new McpClient($this->writeConfig(['alpha', 'beta']));

        $started = \microtime(true);
        try {
            $client->startServers();
            $elapsed = \microtime(true) - $started;

            $this->assertSame(['alpha', 'beta'], \array_keys($client->startedSnapshot()));
            $this->assertLessThan(
                2 * self::DELAY - 0.5,
                $elapsed,
                \sprintf('two %.1fs handshakes took %.2fs: they ran one after the other', self::DELAY, $elapsed),
            );
        } finally {
            $client->stopServers();
        }
    }

    public function testBeginStartupReturnsBeforeAnyServerIsReady(): void
    {
        $client = new McpClient($this->writeConfig(['alpha', 'beta']));

        $started = \microtime(true);
        try {
            $client->beginStartup();
            $elapsed = \microtime(true) - $started;

            $this->assertLessThan(self::DELAY / 2, $elapsed, 'beginStartup() waited for a handshake');
            $this->assertSame(['alpha' => 'starting', 'beta' => 'starting'], $client->startupStates());
            $this->assertSame([], $client->startedSnapshot(), 'a starting server must not offer tools yet');

            $client->startAllBlocking();
            $this->assertSame(['alpha' => 'ready', 'beta' => 'ready'], $client->startupStates());
            $this->assertSame(['alpha', 'beta'], \array_keys($client->startedSnapshot()));
        } finally {
            $client->stopServers();
        }
    }

    public function testBlockingWrapperReturnsTheSameServerSetAsTheTickedPath(): void
    {
        $names = ['alpha', 'beta', 'gamma'];

        $blocking = new McpClient($this->writeConfig($names, delay: 0.0));
        $ticked = new McpClient($this->writeConfig($names, delay: 0.0));
        try {
            $blocking->startServers();

            $ticked->beginStartup();
            $deadline = \microtime(true) + 30.0;
            while (\in_array('starting', $ticked->startupStates(), true) && \microtime(true) < $deadline) {
                $ticked->tick();
                \usleep(5000);
            }

            $this->assertSame(
                \array_keys($blocking->startedSnapshot()),
                \array_keys($ticked->startedSnapshot()),
            );
            $this->assertSame($names, \array_keys($blocking->startedSnapshot()));
            $this->assertSame($blocking->startupStates(), $ticked->startupStates());
        } finally {
            $blocking->stopServers();
            $ticked->stopServers();
        }
    }

    /**
     * @param list<string> $names
     */
    private function writeConfig(array $names, float $delay = self::DELAY): string
    {
        $servers = [];
        foreach ($names as $name) {
            $servers[$name] = [
                'type' => 'stdio',
                'command' => PHP_BINARY,
                'args' => [$this->fixtureScript(), (string) $delay],
                'env' => new \stdClass(),
            ];
        }

        $path = $this->tempDir . '/mcp.json';
        \file_put_contents($path, \json_encode(['mcpServers' => $servers], JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * A stdio server that holds its `initialize` reply for the given seconds
     * and answers `tools/list` at once.
     */
    private function fixtureScript(): string
    {
        $path = $this->tempDir . '/slow-server.php';
        \file_put_contents($path, <<<'PHP'
            <?php
            $delay = (float) ($argv[1] ?? 0);
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) {
                    continue;
                }
                if (($msg['method'] ?? '') === 'initialize' && $delay > 0) {
                    usleep((int) ($delay * 1000000));
                }
                $result = ($msg['method'] ?? '') === 'initialize'
                    ? ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()]
                    : ['tools' => [[
                        'name' => 'ping',
                        'description' => 'Answer with pong.',
                        'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                    ]]];
                echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
                flush();
            }
            PHP);

        return $path;
    }
}
