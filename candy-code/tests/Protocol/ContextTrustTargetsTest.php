<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTrustPins;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Protocol\TrustAsks;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * C-SEC-5.b: the trust prompt is a LIST now. One `context.sources`
 * measurement of a root that declares several untrusted doors mints one row
 * per pending target — the launch census's order — each answered on its own
 * through its own grant door: a settings grant writes ONLY
 * `trustedProjectSettings` (the widening the prompt's reason names), and the
 * pin row on an already-trusted root re-records the fingerprints through the
 * MCP door. The once law holds per (root, target): a reload re-states the
 * rows and broadcasts nothing new.
 */
final class ContextTrustTargetsTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /** @var array<int, array<string, McpClient>> */
    private array $memoBefore = [];

    private ProtocolFixture $fixture;

    private string $homeRoot = '';

    protected function setUp(): void
    {
        TrustAsks::resetForTesting();
        $this->armMcpLaunchEnabled();
        $this->homeRoot = \sys_get_temp_dir() . '/x5b-home-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        \mkdir($this->homeRoot . '/.candy-code', 0o700, true);
        \mkdir($this->homeRoot . '/memory', 0o700, true);
        $this->fixture = ProtocolFixture::new(memoryStore: new MemoryStore($this->homeRoot . '/memory'));
        $this->memoBefore = self::memo('mcpClients');
    }

    protected function tearDown(): void
    {
        self::setMemo('mcpClients', $this->memoBefore);
        TrustAsks::resetForTesting();
        $this->restoreMcpLaunchEnabled();
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        $this->purgeTree($this->homeRoot);
    }

    public function testOneMeasurementMintsEveryPendingDoorInTheCensusOrder(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $root = (string) realpath($this->fixture->root);

        $this->declareLedgerServer();
        $this->declareHooks();
        $this->declareSettings();

        $rows = $this->sources($client, $sessionId)['trusts'];
        self::assertSame(
            ['mcp', 'hooks', 'settings'],
            \array_column($rows, 'target'),
            'the rows ride the census order (TrustTarget::cases), no commands file and a cold memo so no commands or mcp-pin row',
        );
        self::assertSame([$root, $root, $root], \array_column($rows, 'root'));
        self::assertSame(['asked', 'asked', 'asked'], \array_column($rows, 'status'));
        self::assertStringContainsString('.mcp.json', $rows[0]['reason']);
        self::assertStringContainsString('hook', $rows[1]['reason']);
        self::assertStringContainsString('settings layer', $rows[2]['reason']);
        self::assertSame(
            \array_column($rows, 'askId'),
            \array_unique(\array_column($rows, 'askId')),
            'three rows, three distinct ids',
        );

        $announced = $this->trustEvents($client);
        self::assertCount(3, $announced, 'each pending target announces exactly once');
        self::assertSame(['mcp', 'hooks', 'settings'], \array_column(\array_column($announced, 'data'), 'target'));
        foreach ($announced as $envelope) {
            self::assertSame($sessionId, $envelope['data']['sessionId']);
        }

        // The once law PER (root, target): a reload re-states the same rows.
        $again = $this->sources($client, $sessionId)['trusts'];
        self::assertSame(\array_column($rows, 'askId'), \array_column($again, 'askId'));
        self::assertCount(3, $this->trustEvents($client), 'a reload broadcasts nothing new');
    }

    public function testGrantingOneRowWritesOnlyThatDoorAndLeavesTheOthersAsked(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $root = (string) realpath($this->fixture->root);

        $this->declareLedgerServer();
        $this->declareHooks();
        $this->declareSettings();
        $askId = $this->trustRowFor($client, $sessionId, 'settings')['askId'];

        $result = $client->call('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant', 'remember' => 'user']);
        self::assertSame(['askId' => $askId, 'status' => 'granted', 'persisted' => true], $result);

        // The fixture workspace arms a sandboxed SettingsWriter (the same
        // door the settings.* methods write through), so the grant lands in
        // the fixture's user-config copy, not the HOME one.
        $config = \json_decode((string) \file_get_contents($this->fixture->dir . '/config.json'), true);
        self::assertSame([$root], $config['trustedProjectSettings'] ?? null, 'the settings door, exactly');
        self::assertArrayNotHasKey('trustedProjectMcp', $config, 'answering one row never opens another door');
        self::assertArrayNotHasKey('trustedProjectHooks', $config);

        $rows = \array_column($this->sources($client, $sessionId)['trusts'], null, 'target');
        self::assertSame('granted', $rows['settings']['status']);
        self::assertTrue($rows['settings']['persisted']);
        self::assertSame('asked', $rows['mcp']['status'], 'the other prompts stay open');
        self::assertSame('asked', $rows['hooks']['status']);

        $again = $client->request('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant', 'remember' => 'user']);
        self::assertSame(-32009, $again['error']['code']);
        self::assertSame('already_resolved', $again['error']['data']['kind']);
    }

    public function testADriftedPinOnATrustedRootMintsItsOwnRowAndTheGrantReRecords(): void
    {
        $root = (string) realpath($this->fixture->root);
        \file_put_contents($this->homeRoot . '/.candy-code/config.json', \json_encode(['trustedProjectMcp' => [$root]], JSON_THROW_ON_ERROR));
        $this->declareLedgerServer();
        $this->plantRefusingClient(['ledger']);

        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $rows = $this->sources($client, $sessionId)['trusts'];
        self::assertSame(['mcp-pin'], \array_column($rows, 'target'), 'the root is trusted on the list door; only the drifted fingerprint asks');
        self::assertStringContainsString('fingerprint', $rows[0]['reason']);
        self::assertCount(1, $this->trustEvents($client));

        $result = $client->call('context.trust', ['sessionId' => $sessionId, 'askId' => $rows[0]['askId'], 'decision' => 'grant', 'remember' => 'user']);
        self::assertSame('granted', $result['status']);
        self::assertTrue($result['persisted'], 'the MCP door lands list and record together — a pin cannot ride without its list entry');

        $pins = McpTrustPins::load($this->homeRoot . '/.candy-code/' . McpTrustPins::FILENAME)->forRoot($root);
        self::assertIsArray($pins, 'the grant re-recorded the fingerprints');
        self::assertArrayHasKey('ledger', $pins);

        $config = \json_decode((string) \file_get_contents($this->homeRoot . '/.candy-code/config.json'), true);
        self::assertSame([$root], $config['trustedProjectMcp'], 'a root already on the list is not appended twice');

        $restated = $this->sources($client, $sessionId)['trusts'];
        self::assertSame('granted', $restated[0]['status'], 'the settled pin row rides later measurements, asked once');
        self::assertCount(1, $this->trustEvents($client));
    }

    /** @return array<string, mixed> */
    private function sources(WireClient $client, string $sessionId): array
    {
        $answer = $client->call('context.sources', ['sessionId' => $sessionId]);
        self::assertArrayHasKey('trusts', $answer, 'the answer carries the root\'s trust prompt rows');

        return $answer;
    }

    /**
     * @return array<string, mixed> the row for $target
     */
    private function trustRowFor(WireClient $client, string $sessionId, string $target): array
    {
        foreach ($this->sources($client, $sessionId)['trusts'] as $row) {
            if ($row['target'] === $target) {
                return $row;
            }
        }
        self::fail('no ' . $target . ' row in the trust list');
    }

    /**
     * @return list<array<string, mixed>> the trust prompts this client was told about
     */
    private function trustEvents(WireClient $client): array
    {
        return \array_values(\array_filter(
            $client->events('permission.asked'),
            static fn (array $envelope): bool => ($envelope['data']['kind'] ?? null) === 'trust',
        ));
    }

    private function declareLedgerServer(): void
    {
        \file_put_contents(
            $this->fixture->root . '/.mcp.json',
            "{\"mcpServers\":{\"ledger\":{\"command\":\"true\",\"args\":[]}}}\n",
        );
    }

    private function declareHooks(): void
    {
        $this->ensureProjectConfigDir();
        \file_put_contents($this->fixture->root . '/.candy-code/hooks.yaml', "hooks:\n");
    }

    private function declareSettings(): void
    {
        $this->ensureProjectConfigDir();
        \file_put_contents($this->fixture->root . '/' . LayeredSettings::SHARED_PATH, "{}\n");
    }

    private function ensureProjectConfigDir(): void
    {
        if (!\is_dir($this->fixture->root . '/.candy-code')) {
            \mkdir($this->fixture->root . '/.candy-code', 0o700, true);
        }
    }

    /**
     * The memo client the pin census reads — planted exactly like
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapProjectTrustGrantTest}: the
     * honest alternative is starting a real server whose fingerprint drifted,
     * and a census that launches its own subject is the MCP-3 hole reopened.
     *
     * @param list<string> $refused
     */
    private function plantRefusingClient(array $refused): void
    {
        $client = new McpClient($this->fixture->root . '/' . Bootstrap::MCP_CONFIG_FILENAME);
        (new \ReflectionProperty(McpClient::class, 'refusedServers'))->setValue($client, $refused);

        $key = McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($this->fixture->root));
        $memo = self::memo('mcpClients');
        $memo[getmypid() ?: 0][$key] = $client;
        self::setMemo('mcpClients', $memo);
    }

    /** @return array<int, array<string, McpClient>> */
    private static function memo(string $property): array
    {
        $ref = new \ReflectionProperty(LaunchContext::class, $property);

        return (array) $ref->getValue();
    }

    /** @param array<int, array<string, McpClient>> $value */
    private static function setMemo(string $property, array $value): void
    {
        (new \ReflectionProperty(LaunchContext::class, $property))->setValue(null, $value);
    }

    private function purgeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || (is_dir($path) && !is_link($path))) {
                $this->purgeTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
