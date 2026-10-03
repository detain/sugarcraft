<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Cli\Subcommands;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpTrustPins;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\McpLaunchEnabledTrait;

/**
 * Audit MCP-5: project MCP trust is bound to each server's command, args and
 * env, not to the root path alone. Trust a root, change one server's `args`,
 * rebuild the client: that server is refused (its command never runs), the
 * unchanged one starts, and the refusal names the server. `sugarcrush mcp
 * trust` approves the change.
 */
final class BootstrapMcpTrustPinsTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /** @var array<int, array<string, McpClient>> */
    private array $memoBefore = [];

    /** @var array<int, array<string, array{sha256: string, mtime: int}>> */
    private array $digestsBefore = [];

    private string $tmp;
    private string $root;
    private string $home;

    protected function setUp(): void
    {
        $this->armMcpLaunchEnabled();
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_mcp_pins_' . bin2hex(random_bytes(6));
        $this->root = $this->tmp . '/project';
        $this->home = $this->tmp . '/home';
        mkdir($this->root, 0o700, true);
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->home);
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode([
            'trustedProjectMcp' => [$this->root],
        ], JSON_THROW_ON_ERROR));

        $this->memoBefore = self::memo('mcpClients');
        $this->digestsBefore = self::memo('mcpConfigDigests');
    }

    protected function tearDown(): void
    {
        self::setMemo('mcpClients', $this->memoBefore);
        self::setMemo('mcpConfigDigests', $this->digestsBefore);
        $this->restoreHomeSandbox();
        $this->restoreMcpLaunchEnabled();
        self::rmrf($this->tmp);
    }

    public function testAChangedServerIsRefusedWhileItsUnchangedSiblingStarts(): void
    {
        $first = $this->tmp . '/first.mark';
        $second = $this->tmp . '/second.mark';

        $this->writeConfig($first);
        $this->launch();
        self::assertFileExists($first, 'the first launch under a grant runs and records what it starts');
        self::assertFileExists($this->home . '/.sugar-crush/' . McpTrustPins::FILENAME);

        // A `git pull` changes one server's args.
        $this->writeConfig($second);
        $launch = $this->launch();

        self::assertFileDoesNotExist($second, 'a server whose args changed since trust must not run');
        self::assertSame(['shell'], $launch['refused']);
        self::assertArrayHasKey('ledger', $launch['started'], 'the unchanged server still starts');

        $notice = $this->refusalNotice();
        self::assertNotNull($notice, 'the refusal is reported');
        self::assertStringContainsString('"shell" changed', $notice);
        self::assertStringContainsString('first.mark', $notice);
        self::assertStringContainsString('second.mark', $notice);
        self::assertStringContainsString('sugarcrush mcp trust', $notice);
    }

    public function testAnAddedServerIsRefusedAndMcpTrustApprovesIt(): void
    {
        $mark = $this->tmp . '/added.mark';
        $this->writeServers(['ledger' => ['type' => 'git']]);
        $this->launch();

        $this->writeServers([
            'ledger' => ['type' => 'git'],
            'added' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $mark], 'startTimeout' => 0.5],
        ]);
        self::assertSame(['added'], $this->launch()['refused']);
        self::assertFileDoesNotExist($mark);

        $report = Bootstrap::trustProjectMcp($this->root);
        self::assertTrue($report['granted']);
        self::assertTrue($report['recorded']);
        self::assertEquals(
            ['added' => 'new', 'ledger' => 'unchanged'],
            array_column($report['servers'], 'change', 'name'),
        );

        self::assertSame([], $this->launch()['refused']);
        self::assertFileExists($mark, 'an approved server starts at the next launch');
    }

    public function testMcpTrustAddsAMissingGrantForTheCanonicalRoot(): void
    {
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode(['theme' => 'kept'], JSON_THROW_ON_ERROR));
        $this->writeServers(['ledger' => ['type' => 'git']]);

        $report = Bootstrap::trustProjectMcp($this->root . '/./');

        self::assertSame(Bootstrap::MCP_UNTRUSTED, $report['status']);
        self::assertTrue($report['granted']);
        $config = json_decode((string) file_get_contents($this->home . '/.sugar-crush/config.json'), true);
        self::assertSame(['theme' => 'kept', 'trustedProjectMcp' => [$this->root]], $config);
    }

    public function testTheMcpTrustSubcommandPrintsTheReviewAndRecords(): void
    {
        $this->writeServers(['ledger' => ['type' => 'git'], 'srv' => ['command' => 'srv', 'env' => ['API_KEY' => 'sk-secret']]]);

        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['sugarcrush', $this->root, 'mcp', 'trust']));
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);
        self::assertMatchesRegularExpression('/^  srv\s+new\s+srv \[env: API_KEY\]$/m', $stdout);
        self::assertStringNotContainsString('sk-secret', $stdout);
        self::assertStringContainsString('2 servers recorded', $stdout);
        self::assertNotNull(McpTrustPins::load($this->home . '/.sugar-crush/' . McpTrustPins::FILENAME)->forRoot($this->root));
    }

    /**
     * Item 0.14-b (D3): the launch names the inherited credentials a stdio
     * server was started without, so a server that needed one does not just
     * fail silently. A server that declares the name in `env` is not named.
     */
    public function testAStdioServerStartedWithoutAnInheritedCredentialIsNamedInTheLaunchNotices(): void
    {
        $saved = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN=ghp_FAKE_w1int');

        try {
            $this->writeServers([
                'bare' => ['command' => '/bin/sh', 'args' => ['-c', 'exit 0'], 'startTimeout' => 0.5],
                'declared' => [
                    'command' => '/bin/sh',
                    'args' => ['-c', 'exit 0'],
                    'env' => ['GITHUB_TOKEN' => '${GITHUB_TOKEN}'],
                    'startTimeout' => 0.5,
                ],
            ]);
            $this->launch();
        } finally {
            $saved === false ? putenv('GITHUB_TOKEN') : putenv('GITHUB_TOKEN=' . $saved);
        }

        $notice = null;
        foreach (array_reverse(Bootstrap::launchNotices()) as $candidate) {
            if (str_starts_with($candidate, 'MCP stdio servers start without inherited credentials')) {
                $notice = $candidate;
                break;
            }
        }

        self::assertNotNull($notice, 'the stripped-credential notice reaches the launch notices');
        self::assertMatchesRegularExpression('/\bbare \([^)]*\bGITHUB_TOKEN\b/', $notice);
        // The developer's own shell may hold other credentials, so `declared`
        // can still be named for those; it must not be named for the one it
        // declared.
        self::assertDoesNotMatchRegularExpression('/\bdeclared \([^)]*\bGITHUB_TOKEN\b/', $notice, 'a server that declared the name kept it');
        self::assertStringNotContainsString('ghp_FAKE_w1int', $notice, 'names only, never values');
    }

    private function writeConfig(string $mark): void
    {
        $this->writeServers([
            'ledger' => ['type' => 'git'],
            'shell' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $mark], 'startTimeout' => 0.5],
        ]);
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function writeServers(array $servers): void
    {
        file_put_contents(
            $this->root . '/' . Bootstrap::MCP_CONFIG_FILENAME,
            json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * One launch's client build, then its servers stopped and its memo entry
     * dropped, so the next call is a fresh launch of the same root.
     *
     * @return array{refused: list<string>, started: array<string, mixed>}
     */
    private function launch(): array
    {
        $client = Bootstrap::mcpClient($this->root);
        self::assertInstanceOf(McpClient::class, $client);
        $result = ['refused' => $client->refusedServers(), 'started' => $client->startedSnapshot()];
        $client->stopServers();

        $path = Bootstrap::mcpConfigDecision($this->root)['path'];
        $memo = self::memo('mcpClients');
        unset($memo[getmypid() ?: 0][$path]);
        self::setMemo('mcpClients', $memo);

        return $result;
    }

    private function refusalNotice(): ?string
    {
        foreach (array_reverse(Bootstrap::launchNotices()) as $notice) {
            if (str_starts_with($notice, 'MCP servers in ')) {
                return $notice;
            }
        }

        return null;
    }

    /** @return array<mixed> */
    private static function memo(string $property): array
    {
        $ref = new \ReflectionProperty(Bootstrap::class, $property);

        return (array) $ref->getValue();
    }

    /** @param array<mixed> $value */
    private static function setMemo(string $property, array $value): void
    {
        (new \ReflectionProperty(Bootstrap::class, $property))->setValue(null, $value);
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
