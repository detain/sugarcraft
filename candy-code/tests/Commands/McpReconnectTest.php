<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use GuzzleHttp\Client;
use SugarCraft\Code\Commands\McpAuthCommand;
use SugarCraft\Code\MCP\McpAuthStore;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\OAuthClientRegistration;

/**
 * Roadmap VIS-3b (MCP-6 folded): the fifth `/mcp` verb, `reconnect`.
 *
 * The command-level pins use the constructor seams — resolver, drift probe,
 * reconnector — so no server process is ever spawned here (E646: the verb
 * itself carries no timers either). What those pins hold shut is the ORDER
 * the seams are called in, which is the actual law: the E703-α byte-identity
 * gate must fire BEFORE anything touches a server, because
 * `McpClient::startServers()` re-reads `.mcp.json` from disk on every call —
 * a reconnect against drifted bytes would start servers this session's
 * admission decisions never saw. A missing launch digest fails the same
 * closed: absence of proof is refusal, not consent.
 */
final class McpReconnectTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush-mcprc-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    // ---- the gate: refusal paths ----------------------------------------------

    public function testReconnectWithoutAClientSaysSoAndTouchesNothing(): void
    {
        $ran = 0;
        $out = $this->runReconnect(
            fn (): ?McpClient => null,
            fn (): ?bool => false,
            function () use (&$ran): array {
                ++$ran;

                return [[], [], [], [], [], null];
            },
        );

        self::assertSame(0, $ran, 'no client means nothing to reconnect — the reconnector must never run');
        self::assertStringContainsString('No MCP client this session', $out);
    }

    public function testDriftedConfigRefusesBeforeTouchingServers(): void
    {
        $ran = 0;
        $out = $this->runReconnect(
            fn (): ?McpClient => $this->client(),
            fn (): ?bool => true,
            function () use (&$ran): array {
                ++$ran;

                return [[], [], [], [], [], null];
            },
        );

        self::assertSame(0, $ran, 'E703-α: a drifted .mcp.json is restart-only — the pair must never run');
        self::assertStringContainsString('reconnect refused', $out);
        self::assertStringContainsString('only apply path', $out);
    }

    public function testMissingLaunchDigestFailsClosed(): void
    {
        $ran = 0;
        $out = $this->runReconnect(
            fn (): ?McpClient => $this->client(),
            fn (): ?bool => null,
            function () use (&$ran): array {
                ++$ran;

                return [[], [], [], [], [], null];
            },
        );

        self::assertSame(0, $ran, 'no digest cannot PROVE the bytes match launch — refusal is the honest answer');
        self::assertStringContainsString('refused', $out);
    }

    // ---- the gate open: report rendering ----------------------------------------

    public function testCleanReconnectRendersTransitionsAndReportsToolDriftWithoutReDeriving(): void
    {
        $out = $this->runReconnect(
            fn (): ?McpClient => $this->client(),
            fn (): ?bool => false,
            fn (): array => [
                [
                    'alpha' => ['transport' => 'stdio', 'up' => true, 'tools' => 3],
                    'beta' => ['transport' => 'http', 'up' => true, 'tools' => 1],
                ],
                [
                    'alpha' => ['transport' => 'stdio', 'up' => true, 'tools' => 5],
                    'gamma' => ['transport' => 'stdio', 'up' => false, 'tools' => 0],
                ],
                ['delta'],
                ['epsilon'],
                ['zeta' => ['url' => 'https://z/mcp', 'status' => 'needs_auth']],
                null,
            ],
        );

        self::assertStringContainsString('**Reconnected MCP servers**', $out);
        self::assertStringContainsString('alpha', $out);
        self::assertStringContainsString('up', $out);
        self::assertStringContainsString('offers 5 tools; this session was built with 3', $out, 'the cache law speaks: drift is REPORTED, the roster is never re-derived');
        self::assertSame(1, substr_count($out, 'offers '), 'only alpha changed tool count — beta went down and gamma is new, neither may borrow the drift line');
        self::assertStringContainsString('beta', $out);
        self::assertStringContainsString('down', $out, 'a server that came back gone is named, not hidden');
        self::assertStringContainsString('gamma', $out);
        self::assertStringContainsString('refused at admission', $out);
        self::assertStringContainsString('deny pattern', $out);
        self::assertStringContainsString('needs an OAuth login', $out);
    }

    public function testNewServerRowOmitsTheToolDriftLineItCannotHave(): void
    {
        $out = $this->runReconnect(
            fn (): ?McpClient => $this->client(),
            fn (): ?bool => false,
            fn (): array => [
                [],
                ['fresh' => ['transport' => 'stdio', 'up' => true, 'tools' => 9]],
                [],
                [],
                [],
                null,
            ],
        );

        self::assertStringContainsString('fresh', $out);
        self::assertStringNotContainsString('offers 9 tools', $out, 'launch never saw this server — there is no "was" to compare against, and inventing 0 would lie');
    }

    public function testStartFailureIsReportedInlineNotThrown(): void
    {
        $out = $this->runReconnect(
            fn (): ?McpClient => $this->client(),
            fn (): ?bool => false,
            fn (): array => [
                ['alpha' => ['transport' => 'stdio', 'up' => true, 'tools' => 1]],
                ['alpha' => ['transport' => 'stdio', 'up' => true, 'tools' => 1]],
                [],
                [],
                [],
                'config vanished mid-flight',
            ],
        );

        self::assertStringContainsString('config vanished mid-flight', $out);
        self::assertStringContainsString('alpha', $out, 'the servers that DID come back stay on the report');
    }

    public function testEmptyChainSaysNone(): void
    {
        $out = $this->runReconnect(
            fn (): ?McpClient => $this->client(),
            fn (): ?bool => false,
            fn (): array => [[], [], [], [], [], null],
        );

        self::assertStringContainsString('  - no servers are started', $out);
    }

    // ---- dispatch + wording fences ---------------------------------------------

    public function testFifthArmReachesReconnectThroughExecuteAndBogusStillNamesIt(): void
    {
        $ran = 0;
        $command = new McpAuthCommand(
            new McpAuthStore(new OAuthClientRegistration(new Client(), $this->tempDir . '/auth.json')),
            null,
            fn (): ?McpClient => $this->client(),
            fn (): ?bool => false,
            function () use (&$ran): array {
                ++$ran;

                return [[], [], [], [], [], null];
            },
        );

        ob_start();
        $rc = $command->execute(new Chat([]), ['reconnect']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $rc);
        self::assertSame(1, $ran, 'the match arm dispatches to the reconnect path');

        ob_start();
        $command->execute(new Chat([]), ['bogus']);
        $unknown = (string) ob_get_clean();
        self::assertStringContainsString('Use: list, add, remove, login, reconnect', $unknown);
    }

    // ---- structural pins: the order IS the law ------------------------------------

    public function testTheGateRunsBeforeTheReconnectorAndThePairIsSnapshotStopStartSnapshot(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Commands/McpAuthCommand.php');

        $reconnectBody = substr($source, (int) strpos($source, 'private function reconnect('));
        $reconnectBody = substr($reconnectBody, 0, (int) strpos($reconnectBody, 'private static function reconnectPair('));
        $gate = strpos($reconnectBody, '($this->driftProbe)($root)');
        $pair = strpos($reconnectBody, '($this->reconnector)($client)');
        self::assertIsInt($gate);
        self::assertIsInt($pair);
        self::assertLessThan($pair, $gate, 'the byte-identity gate must decide BEFORE any server is touched');

        $pairBody = substr($source, (int) strpos($source, 'private static function reconnectPair('));
        $snap = strpos($pairBody, '$client->startedSnapshot()');
        $stop = strpos($pairBody, '$client->stopServers()');
        $start = strpos($pairBody, '$client->startServers()');
        self::assertLessThan($stop, $snap, 'the launch-side snapshot must precede the stop or there is no "was" to report');
        self::assertLessThan($start, $stop, 'stop before start — starting atop live servers double-spawns the stdio children');

        // Production defaults ride the memo and the shared verifier — never a
        // fresh McpClient build, which would mint servers outside the session's
        // admission set entirely.
        self::assertMatchesRegularExpression('/Bootstrap::mcpClient\(/', $source, 'the resolver default must be the session memo');
        self::assertStringContainsString('mcpConfigChangedSinceLaunch', $source, 'the drift probe default is the E703-α verifier');
    }

    // ---- helpers --------------------------------------------------------------------

    private function runReconnect(callable $resolver, callable $probe, callable $reconnector): string
    {
        $command = new McpAuthCommand(
            new McpAuthStore(new OAuthClientRegistration(new Client(), $this->tempDir . '/auth.json')),
            null,
            $resolver,
            $probe,
            $reconnector,
        );

        ob_start();
        $rc = $command->execute(new Chat([]), ['reconnect']);
        $out = (string) ob_get_clean();

        self::assertSame(0, $rc, 'reconnect answers; it never fails the transcript path');

        return $out;
    }

    /**
     * A real, never-started client: McpClient is final, so the seams carry it
     * by identity only — the reconnector seam means its sockets never open.
     */
    private function client(): McpClient
    {
        return new McpClient($this->tempDir . '/.mcp.json');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
