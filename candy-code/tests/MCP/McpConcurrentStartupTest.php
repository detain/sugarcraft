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

    /**
     * Fallback hold for a gated fixture, released early by the test. A blocking
     * beginStartup() would sit out this whole hold, so the bound below it has
     * room for a loaded shard that is slow to spawn the fixture processes.
     */
    private const GATED_HOLD = 30.0;

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
        $release = $this->tempDir . '/release';
        $client = new McpClient($this->writeConfig(['alpha', 'beta'], self::GATED_HOLD, $release));

        $started = \microtime(true);
        try {
            $client->beginStartup();
            $elapsed = \microtime(true) - $started;

            // The fixtures hold their reply until the release file appears, which
            // happens only after this check, so a blocking wait would sit out the
            // whole GATED_HOLD fallback. A 2 s bound against a 2 s reply tripped
            // when a loaded shard took 3 s just to spawn the two processes.
            $this->assertLessThan(self::GATED_HOLD, $elapsed, 'beginStartup() waited for a handshake');
            $this->assertSame(['alpha' => 'starting', 'beta' => 'starting'], $client->startupStates());
            $this->assertSame([], $client->startedSnapshot(), 'a starting server must not offer tools yet');

            \touch($release);
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
    private function writeConfig(array $names, float $delay = self::DELAY, ?string $releaseFile = null): string
    {
        $args = [$this->fixtureScript(), (string) $delay];
        if ($releaseFile !== null) {
            $args[] = $releaseFile;
        }

        $servers = [];
        foreach ($names as $name) {
            $servers[$name] = [
                'type' => 'stdio',
                'command' => PHP_BINARY,
                'args' => $args,
                'env' => new \stdClass(),
            ];
        }

        $path = $this->tempDir . '/mcp.json';
        \file_put_contents($path, \json_encode(['mcpServers' => $servers], JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * A stdio server that holds its `initialize` reply for the given seconds
     * and answers `tools/list` at once. Given a release file, it holds the
     * reply only until that file exists, with the seconds as the fallback.
     */
    private function fixtureScript(): string
    {
        $path = $this->tempDir . '/slow-server.php';
        \file_put_contents($path, <<<'PHP'
            <?php
            $delay = (float) ($argv[1] ?? 0);
            $release = $argv[2] ?? null;
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) {
                    continue;
                }
                if (($msg['method'] ?? '') === 'initialize' && $delay > 0) {
                    if ($release === null) {
                        usleep((int) ($delay * 1000000));
                    } else {
                        $deadline = microtime(true) + $delay;
                        while (!file_exists($release) && microtime(true) < $deadline) {
                            usleep(10000);
                            clearstatcache();
                        }
                    }
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
