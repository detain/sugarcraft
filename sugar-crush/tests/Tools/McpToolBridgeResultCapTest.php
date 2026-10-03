<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\Tools\McpToolBridge;

/**
 * Item 0.5, first half: an MCP answer is capped like every other open-ended
 * tool output. `Runtime::utf8Safe()` documents "no cap runs after this seam
 * (each tool capped its own output already)", and until this row the bridge
 * was the one tool for which that was false — a server answering with a
 * multi-megabyte dump put all of it into every later request of the turn.
 */
final class McpToolBridgeResultCapTest extends TestCase
{
    private string $workDir;

    private ?McpClient $client = null;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/sc_mcp_cap_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        file_put_contents($this->workDir . '/big.php', <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) { continue; }
                $out = ['jsonrpc' => '2.0', 'id' => $msg['id']];
                if ($msg['method'] === 'initialize') {
                    $out['result'] = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
                        'serverInfo' => ['name' => 'big', 'version' => '0']];
                } elseif ($msg['method'] === 'tools/list') {
                    $out['result'] = ['tools' => [['name' => 'dump', 'description' => 'dumps',
                        'inputSchema' => ['type' => 'object', 'properties' => ['bytes' => ['type' => 'integer']]]]]];
                } elseif ($msg['method'] === 'tools/call') {
                    $bytes = (int) ($msg['params']['arguments']['bytes'] ?? 10);
                    $text = str_repeat("row of dump output\n", intdiv($bytes, 19) + 1);
                    $out['result'] = ['content' => [['type' => 'text', 'text' => substr($text, 0, $bytes)]]];
                }
                echo json_encode($out), "\n";
                fflush(STDOUT);
            }
            PHP);
        file_put_contents($this->workDir . '/.mcp.json', (string) json_encode(['mcpServers' => ['big' => [
            'command' => PHP_BINARY,
            'args' => [$this->workDir . '/big.php'],
        ]]]));
    }

    protected function tearDown(): void
    {
        $this->client?->stopServers();
        foreach (['/big.php', '/.mcp.json'] as $file) {
            @unlink($this->workDir . $file);
        }
        @rmdir($this->workDir);
    }

    private function bridge(?int $maxOutputBytes = null): McpToolBridge
    {
        $this->client = new McpClient($this->workDir . '/.mcp.json', unrestricted: true);
        $this->client->startServers();
        $tools = $this->client->listTools();
        self::assertCount(1, $tools, 'fixture: the server must come up with its one tool');

        return $maxOutputBytes === null
            ? new McpToolBridge($this->client, $tools[0])
            : new McpToolBridge($this->client, $tools[0], $maxOutputBytes);
    }

    public function testAnOversizedAnswerIsClippedAtTheDefaultCapWithAnAnnouncedMarker(): void
    {
        $result = $this->bridge()->execute(['bytes' => 300_000]);
        $content = $result->content();

        self::assertFalse($result->isError());
        self::assertLessThanOrEqual(65_536, strlen($content), 'the default cap is TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES');
        self::assertStringContainsString('[truncated:', $content);
        self::assertStringContainsString('of 300000 bytes omitted', $content, 'the marker names the real size of the answer');
        self::assertStringStartsWith('row of dump output', $content);
    }

    public function testAnAnswerUnderTheCapIsUntouched(): void
    {
        $content = $this->bridge()->execute(['bytes' => 1_000])->content();

        self::assertSame(1_000, strlen($content));
        self::assertStringNotContainsString('[truncated:', $content);
    }

    public function testTheCapIsAConstructorArgument(): void
    {
        $content = $this->bridge(2_048)->execute(['bytes' => 10_000])->content();

        self::assertLessThanOrEqual(2_048, strlen($content));
        self::assertStringContainsString('[truncated:', $content);
    }

    public function testANonPositiveCapDisablesIt(): void
    {
        $content = $this->bridge(0)->execute(['bytes' => 100_000])->content();

        self::assertSame(100_000, strlen($content));
    }
}
