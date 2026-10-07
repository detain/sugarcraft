<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpTrustPins;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\Tools\McpToolBridge;

/**
 * Item 0.5, second half, under decision D2 as re-ruled by cl-4 FIX-C: a stdio
 * server's `toolTimeout` (seconds) is a per-call bound that is always ON.
 * Unset (or a non-positive) takes {@see StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS}
 * — a hung third-party server must cost one call, never the session — and a
 * legitimately slow server raises the number per entry. A call inside its
 * ceiling still keeps its turn alive with wait beats (0.4-b).
 */
final class StdioMcpServerToolTimeoutTest extends TestCase
{
    private string $workDir;

    private ?McpClient $client = null;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/sc_mcp_tt_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        file_put_contents($this->workDir . '/slow.php', <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) { continue; }
                $out = ['jsonrpc' => '2.0', 'id' => $msg['id']];
                if ($msg['method'] === 'initialize') {
                    $out['result'] = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
                        'serverInfo' => ['name' => 'slow', 'version' => '0']];
                } elseif ($msg['method'] === 'tools/list') {
                    $out['result'] = ['tools' => [['name' => 'nap', 'description' => 'naps',
                        'inputSchema' => ['type' => 'object', 'properties' => ['seconds' => ['type' => 'number']]]]]];
                } elseif ($msg['method'] === 'tools/call') {
                    usleep((int) (((float) ($msg['params']['arguments']['seconds'] ?? 0)) * 1_000_000));
                    $out['result'] = ['content' => [['type' => 'text', 'text' => 'rested']]];
                }
                echo json_encode($out), "\n";
                fflush(STDOUT);
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->client?->stopServers();
        foreach (['/slow.php', '/.mcp.json'] as $file) {
            @unlink($this->workDir . $file);
        }
        @rmdir($this->workDir);
    }

    /** @param array<string, mixed> $extra */
    private function bridge(array $extra): McpToolBridge
    {
        file_put_contents($this->workDir . '/.mcp.json', (string) json_encode(['mcpServers' => ['slow' => [
            'command' => PHP_BINARY,
            'args' => [$this->workDir . '/slow.php'],
        ] + $extra]]));
        $this->client = new McpClient($this->workDir . '/.mcp.json', unrestricted: true);
        $this->client->startServers();
        $tools = $this->client->listTools();
        self::assertCount(1, $tools, 'fixture: the server must come up with its one tool');

        return new McpToolBridge($this->client, $tools[0]);
    }

    public function testAConfiguredToolTimeoutEndsTheCallWithANamedError(): void
    {
        $bridge = $this->bridge(['toolTimeout' => 0.5]);

        $started = microtime(true);
        $result = $bridge->execute(['seconds' => 3]);
        $elapsed = microtime(true) - $started;

        self::assertTrue($result->isError());
        self::assertLessThan(2.5, $elapsed, 'toolTimeout must bound the call');
        self::assertStringContainsString('timed out after 0.5s (toolTimeout)', $result->content());
    }

    public function testWithoutToolTimeoutTheDefaultCeilingStillAnswersRealWork(): void
    {
        $result = $this->bridge([])->execute(['seconds' => 1.2]);

        self::assertFalse($result->isError());
        self::assertSame('rested', $result->content());
    }

    /**
     * cl-4 FIX-C, the default half: an entry with no `toolTimeout` resolves
     * to the ceiling constant on the server the client actually built — the
     * unset config can no longer reach the transport as "no deadline".
     */
    public function testAnEntryWithoutToolTimeoutBuildsTheDefaultCeilingServer(): void
    {
        $this->bridge([]);
        self::assertInstanceOf(McpClient::class, $this->client);

        $property = new \ReflectionProperty(McpClient::class, 'servers');
        /** @var array<string, StdioMcpServer> $servers */
        $servers = $property->getValue($this->client);

        self::assertSame(
            StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS,
            $servers['slow']->toolTimeoutSeconds(),
        );
    }

    /**
     * The constructor's resolution table: null, 0 and a negative all take
     * the default; a positive number is honoured verbatim.
     */
    public function testTheConstructorResolvesEveryCeilingShape(): void
    {
        $default = new StdioMcpServer(name: 'd', command: 'echo', args: [], env: []);
        self::assertSame(StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS, $default->toolTimeoutSeconds());

        $zero = new StdioMcpServer(name: 'z', command: 'echo', args: [], env: [], toolTimeoutSeconds: 0.0);
        self::assertSame(StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS, $zero->toolTimeoutSeconds());

        $negative = new StdioMcpServer(name: 'n', command: 'echo', args: [], env: [], toolTimeoutSeconds: -5.0);
        self::assertSame(StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS, $negative->toolTimeoutSeconds());

        $explicit = new StdioMcpServer(name: 'e', command: 'echo', args: [], env: [], toolTimeoutSeconds: 0.5);
        self::assertSame(0.5, $explicit->toolTimeoutSeconds());
    }

    public function testACallInsideItsToolTimeoutAnswersNormally(): void
    {
        $result = $this->bridge(['toolTimeout' => 10])->execute(['seconds' => 0.1]);

        self::assertFalse($result->isError());
        self::assertSame('rested', $result->content());
    }

    /** @return iterable<string, array{mixed, ?float}> */
    public static function coercions(): iterable
    {
        yield 'absent' => [null, null];
        yield 'integer' => [30, 30.0];
        yield 'fraction' => [0.5, 0.5];
        yield 'zero takes the default' => [0, null];
        yield 'negative takes the default' => [-1, null];
        yield 'string takes the default' => ['30', null];
        yield 'bool takes the default' => [true, null];
    }

    /** @dataProvider coercions */
    public function testOnlyAPositiveNumberIsHonoured(mixed $raw, ?float $expected): void
    {
        self::assertSame($expected, McpClient::toolTimeout($raw));
    }

    /**
     * Tuning a bound is not a new server: editing `toolTimeout` must not
     * re-prompt the trust gate the way changing `command` does.
     */
    public function testToolTimeoutIsLeftOutOfTheTrustFingerprint(): void
    {
        $entry = ['command' => 'npx', 'args' => ['-y', 'srv']];

        self::assertContains('toolTimeout', McpTrustPins::UNPINNED_KEYS);
        self::assertSame(
            McpTrustPins::fingerprint('stdio', $entry),
            McpTrustPins::fingerprint('stdio', $entry + ['toolTimeout' => 45]),
        );
    }
}
