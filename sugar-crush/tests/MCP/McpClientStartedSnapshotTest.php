<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\GitMcpServer;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpServer;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * E698: {@see McpClient::startedSnapshot()} is a READOUT of the started map —
 * every row derived from state the client already holds, with zero wire
 * traffic and zero side effects.
 *
 * The three transports are exercised as themselves where that is cheap and
 * honest (a real stdio child answers the handshake, proving the
 * `proc_get_status` leg on a live process and its flipped answer after
 * `stop()`; the git server is in-process, so `start()` IS the state change),
 * and by direct state where the alternative would be a Guzzle double that
 * re-tests Guzzle (`initialized` is a bool the readout only ever echoes).
 * The fourth shape — a server class none of the three instanceof arms match —
 * is planted with an anonymous class, because `up: null` is the design's
 * fail-honest answer for an unclassifiable transport and nothing in the
 * factory can produce it today.
 */
final class McpClientStartedSnapshotTest extends TestCase
{
    /**
     * An MCP server this suite planted into a client's private map; each
     * entry is stopped in tearDown so a live child never outlives the test
     * that spawned it.
     *
     * @var list<McpServer>
     */
    private array $plantedServers = [];

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_snapshot_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->plantedServers as $server) {
            try {
                $server->stop();
            } catch (\Throwable) {
                // Teardown best effort; the child-ladder in stop() is bounded anyway.
            }
        }
        $this->plantedServers = [];

        if (is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $leftover) {
                if ($leftover !== '.' && $leftover !== '..') {
                    @unlink($this->tempDir . '/' . $leftover);
                }
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * A client that started nothing snapshots nothing — and, unlike the map
     * being absent, answers the SHAPE the panel's byte-stability rule keys on:
     * an empty map, not null.
     */
    public function testAFreshClientSnapshotsTheEmptyMap(): void
    {
        $client = new McpClient($this->tempDir . '/nothing-here.json');

        self::assertSame([], $client->startedSnapshot());
    }

    /**
     * THE LIVE-CHILD LEG, end to end: a real stdio child answers the
     * handshake, so the row is `stdio / up / cached tool count` — and the
     * count comes from the start-time cache, proven below by reading the
     * snapshot AGAIN after the child's script could not have answered
     * anything new.
     */
    public function testALiveStdioChildReadsUpWithItsCachedToolCount(): void
    {
        $server = $this->startAnsweringStdioServer();
        $client = $this->clientWith(['ping-stdio' => $server]);

        $row = $client->startedSnapshot()['ping-stdio'] ?? null;

        self::assertIsArray($row);
        self::assertSame('stdio', $row['transport']);
        self::assertTrue($row['up'], 'a child this suite just started and talked to must read up');
        self::assertSame(1, $row['tools'], 'the handshake cached one tool; the readout must echo the cache');

        // Second read: same answer, and the child was never written to again
        // (a listTools() re-issue would have consumed the stub's stdin and
        // the row could not change). Determinism of a cache, not luck.
        self::assertSame($row, $client->startedSnapshot()['ping-stdio']);
    }

    /**
     * THE FLIPPED LEG: `stop()` releases the child; the server object stays
     * in the client's map (only stopServers() clears the map), so the
     * readout must show the registered-but-gone state rather than vanishing.
     * This is the shape m1 mutates — neuter the up-leg and this goes green.
     */
    public function testAStoppedStdioChildReadsExitedAndKeepsItsToolCache(): void
    {
        $server = $this->startAnsweringStdioServer();
        $client = $this->clientWith(['gone-stdio' => $server]);

        self::assertTrue($client->startedSnapshot()['gone-stdio']['up']);

        $server->stop();

        $row = $client->startedSnapshot()['gone-stdio'] ?? null;
        self::assertIsArray($row, 'a stopped-but-still-registered server must still appear');
        self::assertFalse($row['up'], 'after stop() the child is gone — the readout must say so');
        self::assertSame(1, $row['tools'], 'the advertised-tool cache survives the child, which is the half-truth the panel pairs with exited');
    }

    /**
     * HTTP has no process to lose: the readout echoes the handshake flag.
     * `initialized` is set by direct state because the alternative — a full
     * Guzzle transport double — would be re-testing HttpMcpServer::start(),
     * which its own suite owns.
     */
    public function testHttpReadsItsInitializedFlagAndToolCache(): void
    {
        $server = new HttpMcpServer(
            name: 'rest',
            url: 'https://mcp.invalid/snapshot',
            headers: [],
            httpClient: $this->createMock(\GuzzleHttp\Client::class),
        );

        self::assertFalse($server->isUp(), 'an un-started http server is not up');

        $this->plantedServers[] = $server;
        $client = $this->clientWith(['rest' => $server]);
        self::assertSame('http', $client->startedSnapshot()['rest']['transport']);

        $this->setPrivate($server, 'initialized', true);
        $this->setPrivate($server, 'tools', [
            new McpTool('a', 'A', [], 'rest'),
            new McpTool('b', 'B', [], 'rest'),
        ]);

        $row = $client->startedSnapshot()['rest'] ?? null;
        self::assertIsArray($row);
        self::assertTrue($row['up']);
        self::assertSame(2, $row['tools']);
    }

    /**
     * Git answers `up` for the in-process flag — no child, no socket; the
     * tool count is the constructor-built list, already cached before start.
     */
    public function testGitReadsTheInProcessRunningFlag(): void
    {
        $server = new GitMcpServer(handlers: new \SugarCraft\Crush\MCP\GitCommandHandlers(cwd: $this->tempDir));
        $server->start();
        $this->plantedServers[] = $server;

        $client = $this->clientWith(['repo' => $server]);
        $row = $client->startedSnapshot()['repo'] ?? null;

        self::assertIsArray($row);
        self::assertSame('git', $row['transport']);
        self::assertTrue($row['up']);
        self::assertSame(count($server->listTools()), $row['tools']);

        $server->stop();
        self::assertFalse($client->startedSnapshot()['repo']['up']);
    }

    /**
     * THE UNKNOWN TRANSPORT: a fourth class implementing the interface must
     * NOT be forced to answer `up` — the pumpStderr law in reverse. Its tools
     * are still countable (listTools is on the interface); its liveness is
     * reported as the null it is.
     */
    public function testAnUnrecognisedServerReportsOtherWithNullLiveness(): void
    {
        $server = new class implements McpServer {
            public function start(): void {}
            public function stop(): void {}

            /** @return array<McpTool> */
            public function listTools(): array
            {
                return [new McpTool('solo', 'Solo', [], 'odd')];
            }

            /** @return array<mixed> */
            public function callTool(string $toolName, array $args): array
            {
                return [];
            }
        };

        $client = $this->clientWith(['odd' => $server]);
        $row = $client->startedSnapshot()['odd'] ?? null;

        self::assertIsArray($row);
        self::assertSame('other', $row['transport']);
        self::assertNull($row['up'], 'unknown stays unknown — a defaulted false would read as "checked and dead"');
        self::assertSame(1, $row['tools']);
    }

    // -------------------------------------------------------------------------
    // Fixture plumbing (names deliberately unique suite-wide)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, McpServer> $servers
     */
    private function clientWith(array $servers): McpClient
    {
        $client = new McpClient($this->tempDir . '/unused.json');
        $this->setPrivate($client, 'servers', $servers);

        return $client;
    }

    private function setPrivate(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setAccessible(true);
        $ref->setValue($target, $value);
    }

    /**
     * A stdio child that answers BOTH handshake halves, in the house style of
     * this suite family (fixtures are scripts this test writes, never
     * installed servers). Distinct tool name from every sibling file's stub
     * so no two fixtures can be confused for a copy.
     */
    private function startAnsweringStdioServer(): StdioMcpServer
    {
        $script = $this->tempDir . '/snapshot_responder.php';
        file_put_contents($script, self::ANSWERING_SNAPSHOT_STUB);

        $server = new StdioMcpServer(
            name: 'snapshot-ping',
            command: PHP_BINARY,
            args: [$script],
            env: [],
            startTimeoutSeconds: 5.0,
        );
        $this->plantedServers[] = $server;
        $server->start();

        return $server;
    }

    private const ANSWERING_SNAPSHOT_STUB = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $result = match ((string) ($msg['method'] ?? '')) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => [[
                    'name' => 'snapshot_ping',
                    'description' => 'Liveness fixture tool.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]],
                default => null,
            };
            echo json_encode($result === null
                ? ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'error' => ['code' => -32601, 'message' => 'unknown']]
                : ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        PHP;
}

