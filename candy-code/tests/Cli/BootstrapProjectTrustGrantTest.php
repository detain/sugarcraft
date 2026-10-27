<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTrustPins;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * C-SEC-5.a: the census wired to the real trust doors, and the one grant door
 * that answers its rows. The pure shape lives in
 * {@see \SugarCraft\Code\Tests\Config\Trust\ProjectTrustCensusTest}; what this
 * file owns is what the closures ACTUALLY read — the project files a clone
 * leaves behind, the frozen per-process trust lists, and the memo client that
 * is the only honest source of a pin refusal (asking an absent client would
 * have to BUILD one, and building starts servers).
 *
 * The security property the grant tests pin is the uncomfortable one: a grant
 * made during a session does NOT take effect during that session
 * ({@see Bootstrap::trustedRootsForThisProcess()} — the grant may not be made
 * by the thing it gates), so "granted and persisted" means the OPERATOR's
 * config file on disk, and the census stays frozen until a relaunch. A test
 * that asserted the flip would be asserting the vulnerability.
 */
final class BootstrapProjectTrustGrantTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /** @var array<int, array<string, McpClient>> */
    private array $memoBefore = [];

    private string $tmp;
    private string $root;
    private string $home;

    protected function setUp(): void
    {
        $this->armMcpLaunchEnabled();
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_trust_census_' . bin2hex(random_bytes(6));
        $this->root = $this->tmp . '/project';
        $this->home = $this->tmp . '/home';
        mkdir($this->root, 0o700, true);
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        file_put_contents($this->home . '/.candy-code/config.json', json_encode(['theme' => 'kept'], JSON_THROW_ON_ERROR));

        $this->memoBefore = self::memo('mcpClients');
    }

    protected function tearDown(): void
    {
        self::setMemo('mcpClients', $this->memoBefore);
        @chmod($this->home . '/.candy-code', 0o755);
        $this->restoreHomeSandbox();
        $this->restoreMcpLaunchEnabled();
        self::rmrf($this->tmp);
    }

    public function testTheCensusListsEveryDoorAnUntrustedProjectDeclares(): void
    {
        $this->declareEverything();

        self::assertSame(
            [TrustTarget::Mcp, TrustTarget::Hooks, TrustTarget::Commands, TrustTarget::Settings],
            Bootstrap::pendingProjectTrust($this->root),
            'a cold process has nothing to pin-refuse, so no McpPin row',
        );
    }

    public function testAPlainProjectCommandFileDoesNotDeclareTheCommandsDoor(): void
    {
        $this->declareEverything(shellCommand: false);

        self::assertSame(
            [TrustTarget::Mcp, TrustTarget::Hooks, TrustTarget::Settings],
            Bootstrap::pendingProjectTrust($this->root),
            'the risk is the `!` form, so only the `!` form asks',
        );
    }

    public function testAGrantedRootIsNotPendingOnAnyDoor(): void
    {
        $this->declareEverything();
        file_put_contents($this->home . '/.candy-code/config.json', json_encode([
            'trustedProjectMcp' => [$this->root],
            'trustedProjectHooks' => [$this->root],
            'trustedProjectCommands' => [$this->root],
            'trustedProjectSettings' => [$this->root],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], Bootstrap::pendingProjectTrust($this->root));
    }

    public function testAnUndeclaredRootIsNotPendingOnAnyDoor(): void
    {
        self::assertSame([], Bootstrap::pendingProjectTrust($this->root));
    }

    /**
     * The pin row appears ONLY while a refusal stands against this launch's
     * memo client — planted here, because the only other way to make a
     * refusal is to start a real server whose fingerprint drifted, and a
     * census that launched its own subject would be the MCP-3 hole reopened
     * through the question "what is pending?".
     */
    public function testThePinRowAppearsOnlyWhileAMemoRefusalStands(): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode([
            'trustedProjectMcp' => [$this->root],
        ], JSON_THROW_ON_ERROR));
        $this->writeServers(['ledger' => ['type' => 'git']]);

        self::assertSame([], Bootstrap::pendingProjectTrust($this->root), 'cold memo refuses nothing');

        $this->plantRefusingClient(['shell']);
        self::assertSame([TrustTarget::McpPin], Bootstrap::pendingProjectTrust($this->root));
        self::assertSame(['shell'], Bootstrap::mcpPinRefusals($this->root));

        $this->plantRefusingClient([]);
        self::assertSame([], Bootstrap::pendingProjectTrust($this->root), 're-recording retires the row');
    }

    public function testGrantingAListDoorWritesTheOperatorsConfigAndStaysFrozenThisProcess(): void
    {
        $this->declareEverything();
        self::assertContains(TrustTarget::Hooks, Bootstrap::pendingProjectTrust($this->root));

        $report = Bootstrap::grantProjectTrust(TrustTarget::Hooks, $this->root);

        self::assertSame(['granted' => true, 'persisted' => true, 'error' => null], $report);
        $config = json_decode((string) file_get_contents($this->home . '/.candy-code/config.json'), true);
        self::assertSame(['theme' => 'kept', 'trustedProjectHooks' => [$this->root]], $config);

        self::assertContains(
            TrustTarget::Hooks,
            Bootstrap::pendingProjectTrust($this->root),
            'effective next launch — the grant may not take effect in the session it gates',
        );
    }

    public function testGrantingTheMcpDoorRecordsTheListAndThePinsTogether(): void
    {
        $this->declareEverything();

        $report = Bootstrap::grantProjectTrust(TrustTarget::Mcp, $this->root);

        self::assertSame(['granted' => true, 'persisted' => true, 'error' => null], $report);
        $config = json_decode((string) file_get_contents($this->home . '/.candy-code/config.json'), true);
        self::assertContains($this->root, (array) $config['trustedProjectMcp']);
        self::assertNotNull(
            McpTrustPins::load($this->home . '/.candy-code/' . McpTrustPins::FILENAME)->forRoot($this->root),
            'the door that grants MCP is the door that records its fingerprints',
        );
    }

    /**
     * The pin case answers through the SAME door: re-trust re-records. The
     * row it retires is asserted next to the refusal, above.
     */
    public function testGrantingThePinDoorGoesThroughTheMcpDoor(): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode([
            'trustedProjectMcp' => [$this->root],
        ], JSON_THROW_ON_ERROR));
        $this->writeServers(['ledger' => ['type' => 'git']]);
        $this->plantRefusingClient(['shell']);

        $report = Bootstrap::grantProjectTrust(TrustTarget::McpPin, $this->root);

        self::assertTrue($report['granted']);
        self::assertTrue($report['persisted']);
        self::assertNull($report['error']);
    }

    /**
     * Round-2 review fix: the door's early returns — ABSENT, OUTSIDE_TREE,
     * DISABLED — answered `granted:false` with a null error, the swallowed
     * reason the door's own docblock forbids. An empty project root hits
     * the ABSENT shape, so the failure must still carry the WHY: the
     * existing `cli.mcp.trust.not_granted` row naming the root and the
     * operator config the grant would have landed in.
     */
    public function testAnAbsentConfigStillExplainsTheRefusalThroughTheDoor(): void
    {
        $report = Bootstrap::grantProjectTrust(TrustTarget::Mcp, $this->root);

        self::assertFalse($report['granted']);
        self::assertFalse($report['persisted']);
        self::assertIsString($report['error']);
        self::assertNotSame('', $report['error']);
        self::assertStringContainsString($this->root, (string) $report['error']);
        self::assertStringContainsString(Bootstrap::userConfigPath(), (string) $report['error']);
    }

    /**
     * Never throws: the surfaces that use the door must be able to SHOW the
     * user why an answer failed. With the config directory sealed, the
     * report says so and nothing else moves.
     */
    public function testAnUnwritableConfigIsReportedThroughTheDoorNotThrownPastIt(): void
    {
        $this->declareEverything();
        chmod($this->home . '/.candy-code', 0o555);

        try {
            $report = Bootstrap::grantProjectTrust(TrustTarget::Hooks, $this->root);
        } finally {
            chmod($this->home . '/.candy-code', 0o755);
        }

        self::assertFalse($report['granted']);
        self::assertFalse($report['persisted']);
        self::assertIsString($report['error']);
        self::assertNotSame('', $report['error']);
    }

    /** A root carrying content for every door at once. */
    private function declareEverything(bool $shellCommand = true): void
    {
        $this->writeServers(['ledger' => ['type' => 'git']]);
        mkdir($this->root . '/.candy-code', 0o700, true);
        file_put_contents($this->root . '/.candy-code/hooks.yaml', "hooks:\n");
        file_put_contents($this->root . '/' . LayeredSettings::SHARED_PATH, "{}\n");
        mkdir($this->root . '/.candy-code/commands', 0o700, true);
        file_put_contents(
            $this->root . '/.candy-code/commands/probe.md',
            $shellCommand ? "# probe\n\n!`echo hi`\n" : "# probe\n\nnothing here could ever run\n",
        );
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
     * Plant a client holding the given refusals under the key this launch's
     * decision reads — the memo slot {@see LaunchContext::mcpClientFor()}
     * answers from. The client is constructed pointed at the config and
     * NOTHING is started: refusal state is written through reflection because
     * the honest way to fill it is to drift a real server's fingerprint, and
     * that belongs to the pin tests, not to a census question.
     *
     * @param list<string> $refused
     */
    private function plantRefusingClient(array $refused): void
    {
        $client = new McpClient($this->root . '/' . Bootstrap::MCP_CONFIG_FILENAME);
        (new \ReflectionProperty(McpClient::class, 'refusedServers'))->setValue($client, $refused);

        $key = McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($this->root));
        $memo = self::memo('mcpClients');
        $memo[getmypid() ?: 0][$key] = $client;
        self::setMemo('mcpClients', $memo);
    }

    /** @return array<mixed> */
    private static function memo(string $property): array
    {
        $ref = new \ReflectionProperty(LaunchContext::class, $property);

        return (array) $ref->getValue();
    }

    /** @param array<mixed> $value */
    private static function setMemo(string $property, array $value): void
    {
        (new \ReflectionProperty(LaunchContext::class, $property))->setValue(null, $value);
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
