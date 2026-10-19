<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;
use SugarCraft\Code\Tools\BuiltIn\McpCallTool;
use SugarCraft\Code\Tools\BuiltIn\McpListTool;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * MCP-7 wire tier: the floor decision made through the REAL pipeline —
 * user-tier `mcp.json`, live stdio handshake against fixture servers, and
 * `Bootstrap::tools()` — including the byte-identity of the small-server
 * half (zero UX regression for the common tiny `.mcp.json`), the deferred
 * half's round trip through `mcp_call`, and the law that the roster NEVER
 * re-derives mid-session.
 *
 * Fixture servers are ~25-line NDJSON scripts, one process per server, self
 * -reporting their pid into a handshake log; tearDown kills exactly those
 * pids and stops the clients through the seam that owns them. Same discipline
 * as tests/Integration/McpToolWiringTest, whose assertions this file must
 * not duplicate: what is pinned HERE only exists once MCP-7 can defer.
 */
final class McpLazyToolLoadingTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    private string $tempDir = '';
    private string $repo = '';
    private string $callLog = '';
    private string $handshakeLog = '';

    /** @var list<int> */
    private array $strayPids = [];

    private const FIXTURE = <<<'PHP'
        <?php
        $log = $argv[1] ?? '';
        $handshake = $argv[2] ?? '';
        $tool = $argv[3] ?? 'ping';
        $pad = (int) ($argv[4] ?? 0);
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize' && $handshake !== '') {
                file_put_contents($handshake, getmypid() . "\n", FILE_APPEND);
            }
            if ($method === 'tools/call') {
                file_put_contents($log, json_encode(['tool' => $tool, 'name' => $msg['params']['name'] ?? null]) . "\n", FILE_APPEND);
            }
            $result = match ($method) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => [[
                    'name' => $tool,
                    'description' => 'Answer with pong.' . str_repeat('x', $pad),
                    'inputSchema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string']], 'required' => []],
                ]]],
                'tools/call' => ['content' => [['type' => 'text', 'text' => 'pong:' . $tool]]],
                default => null,
            };
            echo json_encode($result === null
                ? ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'error' => ['code' => -32601, 'message' => 'unknown']]
                : ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]) . "\n";
            flush();
        }
        PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_lazy_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/repo', 0o755, true);
        $this->tempDir = (string) realpath($this->tempDir);
        $this->repo = $this->tempDir . '/repo';
        $this->callLog = $this->tempDir . '/calls.log';
        $this->handshakeLog = $this->tempDir . '/handshakes.log';
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        Bootstrap::stopMcpServers();
        foreach ($this->strayPids as $pid) {
            if ($pid > 0 && function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }
        $this->restoreHomeSandbox();
        $this->removeTree($this->tempDir);
        $this->restoreMcpLaunchEnabled();
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        // A RecursiveIteratorIterator, not glob(): the glob PATTERN strings a
        // helper like this needs are exactly the literals the GlobDialect
        // corpus harvests, and a test-only file must not move that figure.
        if (!is_dir($dir)) {
            return;
        }
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * @param array<string, array<string, mixed>> $servers name => entry
     */
    private function writeUserMcpJson(array $servers): string
    {
        $dir = $this->useHomeSandbox($this->tempDir . '/home');
        @mkdir($dir . '/.candy-code', 0o755, true);
        $path = $dir . '/.candy-code/mcp.json';
        file_put_contents($path, (string) json_encode(['mcpServers' => $servers]));

        foreach (file_exists($this->handshakeLog) ? file($this->handshakeLog, FILE_IGNORE_NEW_LINES) : [] as $pid) {
            $this->strayPids[] = (int) $pid;
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function serverEntry(string $tool, int $pad = 0): array
    {
        $script = $this->tempDir . '/' . $tool . '.php';
        file_put_contents($script, self::FIXTURE);

        return [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$script, $this->callLog, $this->handshakeLog, $tool, $pad],
            'startTimeout' => 5,
        ];
    }

    /** @return list<string> */
    private function toolNames(): array
    {
        return array_map(static fn (object $t): string => $t->name(), Bootstrap::tools($this->repo));
    }

    // ---- zero UX regression: the small config answers exactly as before ------

    public function testASmallUserConfigStillPublishesExactlyTheByteIdenticalBridge(): void
    {
        $this->writeUserMcpJson(['fake' => $this->serverEntry('ping')]);

        $tools = Bootstrap::tools($this->repo);
        $names = array_map(static fn (object $t): string => $t->name(), $tools);
        $bridge = array_values(array_filter($tools, static fn (object $t): bool => $t instanceof McpToolBridge));

        $this->assertCount(1, $bridge);
        $this->assertSame('mcp__fake__ping', $names[array_search($bridge[0]->name(), $names, true)] ?? '');
        $this->assertSame('mcp__fake__ping', $bridge[0]->name());
        $this->assertSame('[MCP fake] Answer with pong.', $bridge[0]->description());
        $this->assertSame(['note' => ['type' => 'string']], $bridge[0]->inputSchema()['properties']);
        $this->assertNotContains(McpListTool::NAME, $names, 'nothing was deferred, so the pair must not exist');
        $this->assertNotContains(McpCallTool::NAME, $names);
        $this->assertNotNull(Bootstrap::mcpToolShape($this->repo));
        $this->assertFalse((bool) Bootstrap::mcpToolShape($this->repo)?->lazy);
    }

    // ---- the floor splits one config per server --------------------------------

    public function testAFatServerDefersWhileItsSmallNeighbourStaysFirstClass(): void
    {
        Bootstrap::writeUserConfig([Bootstrap::MCP_LAZY_SETTING => 2000]);
        $this->writeUserMcpJson([
            'small' => $this->serverEntry('ping'),
            'big' => $this->serverEntry('wide', 12000),
        ]);

        $names = $this->toolNames();
        $this->assertContains('mcp__small__ping', $names);
        $this->assertNotContains(McpToolBridge::wireNameFor('big', 'wide'), $names, 'the deferred tool must NOT be first-class');
        $this->assertContains(McpListTool::NAME, $names);
        $this->assertContains(McpCallTool::NAME, $names);

        $shape = Bootstrap::mcpToolShape($this->repo);
        $this->assertNotNull($shape);
        $this->assertTrue($shape->lazy);
        $this->assertNotNull($shape->findDispatched('big', 'wide'));
        $this->assertSame([], $shape->findDispatched('small', 'ping') === null ? [] : ['found!'], 'the small neighbour was not taxed for its noisy companion');

        // The list answer reflects the split, not a live re-query.
        $listed = $shape->listTool()->execute([]);
        $this->assertStringContainsString('Server big:', $listed->content());
        $this->assertStringNotContainsString('Server small:', $listed->content());

        // And the dispatched call reaches the server through the same client.
        $called = $shape->callTool()->execute(['server' => 'big', 'tool' => 'wide', 'arguments' => []]);
        $this->assertFalse($called->isError(), $called->content());
        $this->assertStringContainsString('pong:wide', $called->content());
        $this->assertStringContainsString('"tool":"wide"', str_replace(' ', '', (string) file_get_contents($this->callLog)), 'the fixture server logged the dispatched call');
    }

    public function testTheEagerFlagKeepsAFatServerFirstClassAndSuppressesThePair(): void
    {
        Bootstrap::writeUserConfig([Bootstrap::MCP_LAZY_SETTING => 2000]);
        $entry = $this->serverEntry('wide', 12000);
        $entry['eager'] = true;
        $this->writeUserMcpJson(['big' => $entry]);

        $names = $this->toolNames();
        $this->assertContains(McpToolBridge::wireNameFor('big', 'wide'), $names);
        $this->assertNotContains(McpListTool::NAME, $names);
        $this->assertNotContains(McpCallTool::NAME, $names);
    }

    public function testTheFloorAtZeroDefersNothingNoMatterHowFat(): void
    {
        Bootstrap::writeUserConfig([Bootstrap::MCP_LAZY_SETTING => 0]);
        $this->writeUserMcpJson(['big' => $this->serverEntry('wide', 12000)]);

        $names = $this->toolNames();
        $this->assertContains(McpToolBridge::wireNameFor('big', 'wide'), $names);
        $this->assertNotContains(McpCallTool::NAME, $names);
    }

    // ---- the cache law: a mid-session edit changes nothing until next session --

    public function testTheRosterNeverReDerivesMidSession(): void
    {
        $this->writeUserMcpJson(['fake' => $this->serverEntry('ping')]);

        $first = Bootstrap::mcpToolShape($this->repo);
        $this->assertNotNull($first);
        $namesBefore = $this->toolNames();

        // The operator edits mcp.json right now: drop the live server, add a
        // fat one. Adoption of servers and tools is NEXT session (E703-alpha
        // for the roster, same rule made structural by MCP-7's memo).
        $this->writeUserMcpJson(['late' => $this->serverEntry('wide', 12000)]);

        $again = Bootstrap::mcpToolShape($this->repo);
        $this->assertSame($first, $again, 'the shape object itself must be the frozen one — a re-derive would return a NEW object even when values agree');
        $this->assertSame($namesBefore, $this->toolNames(), 'and the launch tool set must not have blinked');
        $this->assertFalse($again->lazy, 'the late fat server is not adopted: the frozen shape never saw it');
    }
}
