<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\McpLaunchEnabledTrait;

/**
 * E696: the global MCP deny patterns used to be minted and never applied —
 * `McpClient::setDenyPatterns()` had no caller, and the map was consulted only
 * by the preset router, which the main agent's unrestricted client never
 * builds. Now the `disabledMcpServers` setting feeds it at launch, and the
 * client applies it to the servers it starts, to its unrestricted listing and
 * calls, and (unchanged) through the router.
 */
final class McpDenyPatternsTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /**
     * Answers `initialize`, `tools/list` (one tool, `ping_<server>`) and
     * `tools/call`; appends its server name to the handshake log on each
     * `initialize`, so the log says which servers were actually spawned.
     */
    private const SERVER = <<<'PHP'
        <?php
        [, $name, $log] = $argv;
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize') {
                file_put_contents($log, $name . "\n", FILE_APPEND);
            }
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
    private string $log;

    /** @var list<McpClient> clients this test built directly, stopped in tearDown */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $made = sys_get_temp_dir() . '/sc_mcp_deny_' . bin2hex(random_bytes(6));
        mkdir($made . '/repo', 0o755, true);
        $this->dir = (string) realpath($made);
        $this->repo = $this->dir . '/repo';
        $this->log = $this->dir . '/handshakes.log';
        $this->useHomeSandbox($this->dir . '/home');
        file_put_contents($this->dir . '/server.php', self::SERVER);
        file_put_contents($this->repo . '/.mcp.json', (string) json_encode(['mcpServers' => [
            'good' => $this->entry('good'),
            'untrusted_x' => $this->entry('untrusted_x'),
        ]]));
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stopServers();
        }
        Bootstrap::stopMcpServers();
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);
        $this->restoreMcpLaunchEnabled();

        parent::tearDown();
    }

    public function testADeniedServerIsNeverStartedListedOrCalled(): void
    {
        $client = $this->client();
        $client->setDenyPatterns(['untrusted_*' => 'deny']);
        $client->startServers();

        self::assertSame(['untrusted_x'], $client->deniedServers());
        self::assertSame(['good'], $this->spawned(), 'the denied entry was never spawned');
        self::assertSame(['ping_good'], $this->toolNames($client));

        $this->expectExceptionMessage('Unknown MCP server: untrusted_x');
        $client->callTool('untrusted_x', 'ping_untrusted_x', []);
    }

    /** The unrestricted arm applies the map too, so a map set after the start still binds. */
    public function testAMapSetAfterTheStartStillHidesAndRefuses(): void
    {
        $client = $this->client();
        $client->startServers();
        self::assertSame(['ping_good', 'ping_untrusted_x'], $this->toolNames($client));

        $client->setDenyPatterns(['good' => 'deny']);

        self::assertSame(['ping_untrusted_x'], $this->toolNames($client));
        $this->expectExceptionMessage('MCP server not allowed for this agent: good');
        $client->callTool('good', 'ping_good', []);
    }

    /** End to end: the launch reads `disabledMcpServers` and hands it to the client before the start. */
    public function testTheLaunchFeedsTheSettingToTheClient(): void
    {
        Bootstrap::writeUserConfig([
            'trustedProjectMcp' => [$this->repo],
            McpClient::DENY_SETTINGS_KEY => ['untrusted_*', '', 7],
        ]);

        $client = Bootstrap::mcpClient($this->repo);

        self::assertNotNull($client);
        self::assertSame(['untrusted_x'], $client->deniedServers());
        self::assertSame(['good'], $this->spawned());
        self::assertSame(['ping_good'], $this->toolNames($client));
    }

    public function testWithNothingSetEveryTrustedServerStarts(): void
    {
        Bootstrap::writeUserConfig(['trustedProjectMcp' => [$this->repo]]);

        $client = Bootstrap::mcpClient($this->repo);

        self::assertNotNull($client);
        self::assertSame([], $client->deniedServers());
        self::assertSame(['good', 'untrusted_x'], $this->spawned());
    }

    private function client(): McpClient
    {
        $client = new McpClient($this->repo . '/.mcp.json', unrestricted: true);
        $this->clients[] = $client;

        return $client;
    }

    /** @return array<string, mixed> */
    private function entry(string $name): array
    {
        return [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$this->dir . '/server.php', $name, $this->dir . '/handshakes.log'],
            'startTimeout' => 5,
        ];
    }

    /** @return list<string> */
    private function spawned(): array
    {
        $names = is_file($this->log) ? array_filter(explode("\n", (string) file_get_contents($this->log))) : [];
        sort($names);

        return array_values($names);
    }

    /** @return list<string> */
    private function toolNames(McpClient $client): array
    {
        $names = array_map(static fn (McpTool $tool): string => $tool->name, $client->listTools());
        sort($names);

        return $names;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
