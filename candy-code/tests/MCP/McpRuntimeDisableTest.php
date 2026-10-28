<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * C-MCP-4.b: `/mcp disable <server>` arms a per-session, exact-name refusal on
 * the live client. The refusal ROUTES — it never tears down: the server
 * process stays up, the model-facing tool roster stands (the same cache law
 * that governs `reconnect`), and only calls answer with a message naming the
 * fix. `enable` lifts the refusal; a `forAgentPreset()` handle inherits the
 * set as it stood at fork time, which is why the command's prose promises the
 * NEXT turn, not the running one.
 */
final class McpRuntimeDisableTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /**
     * Answers `initialize`, `tools/list` (one tool, `ping_<name>`) and
     * `tools/call` with `pong`. No handshake log: this test never asks which
     * servers were spawned — the process surviving a disable is pinned
     * through the snapshot's `up` leg instead.
     */
    private const SERVER_SCRIPT = <<<'PHP'
        <?php
        [, $name] = $argv;
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $method = (string) ($msg['method'] ?? '');
            $result = match ($method) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => [['name' => 'ping_' . $name, 'description' => 'pong', 'inputSchema' => ['type' => 'object']]]],
                'tools/call' => ['content' => [['type' => 'text', 'text' => 'pong']]],
                default => null,
            };
            echo json_encode($result === null
                ? ['jsonrpc' => '2.0', 'id' => $msg['id'], 'error' => ['code' => -32601, 'message' => 'unknown']]
                : ['jsonrpc' => '2.0', 'id' => $msg['id'], 'result' => $result]) . "\n";
            flush();
        }
        PHP;

    private string $dir;
    private string $repo;

    /** @var list<McpClient> clients this test built directly, stopped in tearDown */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $made = sys_get_temp_dir() . '/sc_mcp_rtdis_' . bin2hex(random_bytes(6));
        mkdir($made . '/repo', 0o755, true);
        $this->dir = (string) realpath($made);
        $this->repo = $this->dir . '/repo';
        $this->useHomeSandbox($this->dir . '/home');
        file_put_contents($this->dir . '/server.php', self::SERVER_SCRIPT);
        // Plain names: no fnmatch metacharacters anywhere in this fixture, per
        // the GlobDialect corpus law (a glob-shaped literal would move the
        // PathGlob census figure this file must not touch).
        file_put_contents($this->repo . '/.mcp.json', (string) json_encode(['mcpServers' => [
            'alpha' => $this->runtimeEntry('alpha'),
            'beta' => $this->runtimeEntry('beta'),
        ]]));
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stopServers();
        }
        Bootstrap::stopMcpServers();
        $this->restoreHomeSandbox();
        $this->wipeTree($this->dir);
        $this->restoreMcpLaunchEnabled();

        parent::tearDown();
    }

    public function testDisablingRefusesCallsAndLeavesTheProcessAndRosterStanding(): void
    {
        $client = $this->runtimeClient();
        $client->startServers();
        self::assertSame(['ping_alpha', 'ping_beta'], $this->runtimeToolNames($client));

        $client->disableForSession('alpha');

        self::assertSame(['ping_alpha', 'ping_beta'], $this->runtimeToolNames($client), 'the cache law holds: a disabled server keeps its listed tools');

        $refusal = null;
        try {
            $client->callTool('alpha', 'ping_alpha', []);
        } catch (\RuntimeException $e) {
            $refusal = $e->getMessage();
        }
        self::assertSame('MCP server alpha is disabled for this session (/mcp enable alpha)', $refusal, 'the refused call must answer with the fix it names, and the call must not return');

        self::assertSame(['content' => [['type' => 'text', 'text' => 'pong']]], $client->callTool('beta', 'ping_beta', []), 'the untouched server still routes');

        $snapshot = $client->startedSnapshot();
        self::assertTrue($snapshot['alpha']['disabled'], 'the panel row lost its disabled flag');
        self::assertTrue($snapshot['alpha']['up'], 'refusal is routing, not teardown — the process stays up in the snapshot');
        self::assertSame(1, $snapshot['alpha']['tools'], 'the disabled row still counts its cached tools');
        self::assertArrayNotHasKey('disabled', $snapshot['beta'], 'an untouched server carries no flag');
    }

    /** The by-name route reaches transports directly; it must not leak past the toggle. */
    public function testCallToolByNameCarriesTheSameRefusal(): void
    {
        $client = $this->runtimeClient();
        $client->startServers();
        $client->disableForSession('alpha');

        $this->expectExceptionMessage('MCP server alpha is disabled for this session (/mcp enable alpha)');
        $client->callToolByName('ping_alpha', []);
    }

    public function testEnablingRestoresRoutingAndTheArmedSetTracksIt(): void
    {
        $client = $this->runtimeClient();
        $client->startServers();

        self::assertSame([], $client->sessionDisabledServers());

        $client->disableForSession('alpha');
        self::assertSame(['alpha'], $client->sessionDisabledServers());

        $client->enableForSession('alpha');
        self::assertSame([], $client->sessionDisabledServers(), 'the accessor must empty when the last refusal lifts');

        self::assertSame('pong', $client->callTool('alpha', 'ping_alpha', [])['content'][0]['text']);
        self::assertArrayNotHasKey('disabled', $client->startedSnapshot()['alpha'], 'the lifted flag must leave the snapshot, not ride it as false');
    }

    public function testDisableIsIdempotentAndEnableNeverRescuesLaunchDenies(): void
    {
        $client = $this->runtimeClient();
        $client->setDenyPatterns(['beta' => 'deny']);
        $client->startServers();

        $client->disableForSession('alpha');
        $client->disableForSession('alpha');
        self::assertSame(['alpha'], $client->sessionDisabledServers(), 'double-arming must not duplicate');

        $client->enableForSession('beta');
        self::assertTrue($this->refusesCall(fn (): array => $client->callTool('beta', 'ping_beta', [])), 'a launch deny pattern is not a session refusal — enable must not widen past it');
    }

    /**
     * The fork-time-inheritance claim the command prose and the McpClient
     * docblock both make: a per-agent clone copies the set as it stood, so a
     * toggle made after dispatch heals neither direction of the handle pair.
     */
    public function testAForkedHandleInheritsTheSetAtForkTimeAndNeverAgain(): void
    {
        $client = $this->runtimeClient();
        $client->startServers();
        $client->disableForSession('alpha');

        $fork = $client->forAgentPreset(new AgentPreset(
            name: 'c-mcp-4-b-fork',
            description: '',
        ));

        $forkRefusal = null;
        try {
            $fork->callTool('alpha', 'ping_alpha', []);
        } catch (\RuntimeException $e) {
            $forkRefusal = $e->getMessage();
        }
        self::assertStringContainsString('/mcp enable alpha', (string) $forkRefusal, 'the fork must refuse the refusal that predates it');

        // Lift on the launch handle: the running fork keeps refusing (its copy
        // forked while the refusal stood), and arming beta now does not reach
        // the fork either — divergence in both directions is the claim.
        $client->enableForSession('alpha');
        $client->disableForSession('beta');

        self::assertTrue($this->refusesCall(static fn (): array => $fork->callTool('alpha', 'ping_alpha', [])), 'the launch handle healed itself but must not retroactively heal a forked handle');
        self::assertSame('pong', $fork->callTool('beta', 'ping_beta', [])['content'][0]['text'], 'a refusal armed after the fork must not reach into it');
        self::assertSame('pong', $client->callTool('alpha', 'ping_alpha', [])['content'][0]['text'], 'and the launch handle itself routes again once lifted');
    }

    private function runtimeClient(): McpClient
    {
        $client = new McpClient($this->repo . '/.mcp.json', unrestricted: true);
        $this->clients[] = $client;

        return $client;
    }

    /** @return array<string, mixed> */
    private function runtimeEntry(string $name): array
    {
        return [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$this->dir . '/server.php', $name],
            'startTimeout' => 5,
        ];
    }

    /** @return list<string> */
    private function runtimeToolNames(McpClient $client): array
    {
        $names = array_map(static fn (McpTool $tool): string => $tool->name, $client->listTools());
        sort($names);

        return $names;
    }

    private function refusesCall(\Closure $call): bool
    {
        try {
            $call();
        } catch (\RuntimeException) {
            return true;
        }

        return false;
    }

    private function wipeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->wipeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
