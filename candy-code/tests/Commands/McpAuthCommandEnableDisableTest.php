<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Commands\McpAuthCommand;
use SugarCraft\Code\MCP\McpAuthStore;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\OAuthClientRegistration;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * C-MCP-4.b: the two new `/mcp` arms. The command reaches the memoised launch
 * client through the injected resolver, refuses names the launch never
 * declared, and `--persist` mirrors the toggle into (or out of) the user
 * `disabledMcpServers` settings list — writing exactly the one named entry,
 * unsetting the key when an emptied list would leave a dead `[]` behind.
 */
final class McpAuthCommandEnableDisableTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /** Minimal stdio mock: enough for `startServers()` to record entries. */
    private const AUTH_SERVER = <<<'PHP'
        <?php
        [, $name] = $argv;
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $result = match ((string) ($msg['method'] ?? '')) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => []],
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

    /** @var list<McpClient> */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $made = sys_get_temp_dir() . '/sc_mcp_cmdtoggle_' . bin2hex(random_bytes(6));
        mkdir($made . '/repo', 0o755, true);
        $this->dir = (string) realpath($made);
        $this->repo = $this->dir . '/repo';
        $this->useHomeSandbox($this->dir . '/home');
        file_put_contents($this->dir . '/server.php', self::AUTH_SERVER);
        $this->writeAuthConfig(['alpha', 'beta']);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stopServers();
        }
        Bootstrap::stopMcpServers();
        $this->restoreHomeSandbox();
        $this->removeAuthTree($this->dir);
        $this->restoreMcpLaunchEnabled();

        parent::tearDown();
    }

    public function testDisableArmsTheLiveClientAndReportsTheNextTurnLaw(): void
    {
        $client = $this->startedClient();
        $out = $this->execToggle($client, ['disable', 'alpha']);

        self::assertSame(0, $out[0]);
        self::assertStringContainsString('✓ Disabled `alpha` for this session', $out[1]);
        self::assertStringContainsString('from the next turn', $out[1], 'the cache-law promise must ride the row itself');
        self::assertSame(['alpha'], $client->sessionDisabledServers());
        $this->assertFileNotPersisted();
    }

    public function testEnableLiftsAndReportsNotArmedWhenNothingStood(): void
    {
        $client = $this->startedClient();

        $notArmed = $this->execToggle($client, ['enable', 'alpha']);
        self::assertSame(0, $notArmed[0]);
        self::assertStringContainsString('! `alpha` was not disabled for this session.', $notArmed[1]);

        $this->execToggle($client, ['disable', 'alpha']);
        $armed = $this->execToggle($client, ['enable', 'alpha']);
        self::assertSame(0, $armed[0]);
        self::assertStringContainsString('✓ Enabled `alpha` for this session', $armed[1]);
        self::assertSame([], $client->sessionDisabledServers());
    }

    public function testANeverDeclaredNameIsRefusedAndUsageNeedsAName(): void
    {
        $client = $this->startedClient();

        $unknown = $this->execToggle($client, ['disable', 'nosuch']);
        self::assertSame(1, $unknown[0]);
        self::assertStringContainsString('! `nosuch` is not a declared MCP server of this session', $unknown[1]);

        $usage = $this->execToggle($client, ['enable']);
        self::assertSame(1, $usage[0]);
        self::assertStringContainsString('Usage: /mcp enable <server> [--persist]', $usage[1]);

        $flagAsName = $this->execToggle($client, ['disable', '--persist']);
        self::assertSame(1, $flagAsName[0], 'a bare --persist is not a server name');
        self::assertStringContainsString('Usage: /mcp disable <server> [--persist]', $flagAsName[1]);
    }

    public function testNoClientAnswersQuietlyWithItsOwnRowPerVerb(): void
    {
        $command = $this->command(static fn (): ?McpClient => null);

        ob_start();
        $rc = $command->execute(new Chat([]), ['disable', 'alpha']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $rc);
        self::assertStringContainsString('No MCP client this session — nothing to disable', $out);
    }

    public function testPersistWritesExactlyOneNameAndSurvivesARepeat(): void
    {
        $client = $this->startedClient();

        $first = $this->execToggle($client, ['disable', 'alpha', '--persist']);
        self::assertSame(0, $first[0]);
        self::assertStringContainsString('Persisted `alpha` in the user disabledMcpServers list', $first[1]);
        self::assertSame([McpClient::DENY_SETTINGS_KEY => ['alpha']], $this->persistedList(), 'the whole settings value is exactly the one named entry');

        $second = $this->execToggle($client, ['disable', 'alpha', '--persist']);
        self::assertStringContainsString('`alpha` is already persisted', $second[1]);
        self::assertSame(['alpha'], $this->persistedList()[McpClient::DENY_SETTINGS_KEY], 'the repeat must not duplicate the row');
    }

    public function testEnablePersistRemovesOnlyItsNameAndUnsetsAnEmptiedKey(): void
    {
        Bootstrap::writeUserConfig([McpClient::DENY_SETTINGS_KEY => ['alpha', 'beta']]);
        $client = $this->startedClient();

        $removed = $this->execToggle($client, ['enable', 'alpha', '--persist']);
        self::assertSame(0, $removed[0]);
        self::assertStringContainsString('Removed `alpha` from the user disabledMcpServers list', $removed[1]);
        self::assertSame([McpClient::DENY_SETTINGS_KEY => ['beta']], $this->persistedList(), 'the sibling entry rides untouched');

        $second = $this->execToggle($client, ['enable', 'beta', '--persist']);
        self::assertStringContainsString('Removed `beta`', $second[1]);
        self::assertArrayNotHasKey(McpClient::DENY_SETTINGS_KEY, Bootstrap::readUserConfig(), 'an emptied list unsets the key rather than persisting a dead []');

        $nothing = $this->execToggle($client, ['enable', 'gamma', '--persist']);
        self::assertSame(1, $nothing[0], 'gamma was never declared, so the unknown gate answers before the persist leg');
    }

    public function testPersistRefusesAGlobShapedNameAndWritesNothing(): void
    {
        // The metacharacter is built at runtime: a glob-shaped string literal
        // here would enter the PathGlob corpus and drift its census figure.
        $wildName = 'wild' . \chr(42) . 'card';
        $this->writeAuthConfig(['alpha', $wildName]);
        $client = $this->startedClient(extraConfig: true);

        $armed = $this->execToggle($client, ['disable', $wildName, '--persist']);
        self::assertSame(0, $armed[0], 'the session refusal itself still arms for the exact typed name');
        self::assertStringContainsString('carries fnmatch metacharacters; --persist writes exact names only', $armed[1]);
        self::assertSame($wildName, $client->sessionDisabledServers()[0]);
        self::assertArrayNotHasKey(McpClient::DENY_SETTINGS_KEY, Bootstrap::readUserConfig(), 'the refused persist must write nothing');
    }

    /** @return array{0: int, 1: string} */
    private function execToggle(McpClient $client, array $args): array
    {
        $command = $this->command(static fn (): ?McpClient => $client);

        ob_start();
        $rc = $command->execute(new Chat([]), $args);

        return [$rc, (string) ob_get_clean()];
    }

    private function command(\Closure $resolver): McpAuthCommand
    {
        return new McpAuthCommand(
            new McpAuthStore(new OAuthClientRegistration(new Client(), $this->dir . '/auth.json')),
            null,
            $resolver,
            static fn (): ?bool => false,
        );
    }

    /** A client that has run startServers() — configEntries() is only real after the launch attempt. */
    private function startedClient(bool $extraConfig = false): McpClient
    {
        $path = $this->repo . ($extraConfig ? '/.mcp-wild.json' : '/.mcp.json');
        $client = new McpClient($path, unrestricted: true);
        $this->clients[] = $client;
        $client->startServers();

        return $client;
    }

    /** @param list<string> $names */
    private function writeAuthConfig(array $names): void
    {
        $servers = [];
        foreach ($names as $name) {
            $servers[$name] = [
                'type' => 'stdio',
                'command' => PHP_BINARY,
                'args' => [$this->dir . '/server.php', $name],
                'startTimeout' => 5,
            ];
        }
        file_put_contents($this->repo . '/.mcp.json', (string) json_encode(['mcpServers' => $servers]));
        file_put_contents($this->repo . '/.mcp-wild.json', (string) json_encode(['mcpServers' => $servers]));
    }

    /** @return array<string, mixed> */
    private function persistedList(): array
    {
        $raw = Bootstrap::readUserConfig();

        return isset($raw[McpClient::DENY_SETTINGS_KEY])
            ? [McpClient::DENY_SETTINGS_KEY => $raw[McpClient::DENY_SETTINGS_KEY]]
            : [];
    }

    private function assertFileNotPersisted(): void
    {
        self::assertArrayNotHasKey(McpClient::DENY_SETTINGS_KEY, Bootstrap::readUserConfig());
    }

    private function removeAuthTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeAuthTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
