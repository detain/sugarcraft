<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpTrustPins;
use SugarCraft\Crush\Tools\McpToolBridge;

/**
 * Item 0.5, second half, under decision D2: a stdio server's `toolTimeout`
 * (seconds) is an OPT-IN per-call bound. Unset is UNBOUNDED — no global
 * default — because a tool call is somebody's real work (E646) and a
 * sequential call now keeps its turn alive with wait beats (0.4-b) instead.
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

    public function testWithoutToolTimeoutACallIsUnbounded(): void
    {
        $result = $this->bridge([])->execute(['seconds' => 1.2]);

        self::assertFalse($result->isError());
        self::assertSame('rested', $result->content());
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
        yield 'zero is unbounded' => [0, null];
        yield 'negative is unbounded' => [-1, null];
        yield 'string is unbounded' => ['30', null];
        yield 'bool is unbounded' => [true, null];
    }

    /** @dataProvider coercions */
    public function testOnlyAPositiveNumberOptsIn(mixed $raw, ?float $expected): void
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
