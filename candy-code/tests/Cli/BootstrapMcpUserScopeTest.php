<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\MCP\GitMcpServer;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTrustPins;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * MCP-3: the USER tier — `~/.candy-code/mcp.json`.
 *
 * The behavior half of the user scope: what the decision answers, what
 * actually starts, who wins a shared name, and what the launch-freeze law
 * owes a second file. The old docs sentence (rewritten in this wave) had
 * called the tier's absence "a decision"; this file is the pair of things
 * that decision needed before it could ship: precedence chosen, and the
 * trust boundary drawn so widening DISCOVERY never widens the project
 * trust ceremony (`mcp trust` stays project-only, user entries never
 * reach a pin record or a refusal notice whose fix line is `mcp trust`).
 *
 * Launches are hermetic the way the sibling suites made them hermetic:
 * `git`-type servers run in-process (no child, no network); where the
 * PIN is about which FILE an entry came from, the entries are `stdio`
 * shells that touch marker files, so "started" means "the marker exists"
 * — ground truth outside the client's own bookkeeping.
 */
final class BootstrapMcpUserScopeTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /** @var array<int, array<string, McpClient>> */
    private array $memoBefore = [];

    /** @var array<int, array<string, array{sha256: string, mtime: int}>> */
    private array $digestsBefore = [];

    private string $tmpDir;
    private string $home;
    private ?McpClient $launchedClient = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->armMcpLaunchEnabled();

        $this->tmpDir = sys_get_temp_dir() . '/sc_mcp_user_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);

        $this->home = $this->tmpDir . '/home';
        $this->useHomeSandbox($this->home);
        mkdir($this->home . '/.candy-code', 0o700, true);

        $this->memoBefore = $this->readMemo();
        $this->digestsBefore = $this->readDigests();
    }

    protected function tearDown(): void
    {
        $this->launchedClient?->stopServers();
        $this->writeMemo($this->memoBefore);
        $this->writeDigests($this->digestsBefore);
        $this->restoreHomeSandbox();
        $this->restoreMcpLaunchEnabled();
        $this->purgeTree($this->tmpDir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Discovery: the tier rides beside the project in every decision
    // -------------------------------------------------------------------------

    public function testTheUserTierIsDiscoveredBesideAnAbsentProject(): void
    {
        $root = $this->makeRoot();
        $this->writeUserServers(['usersolo' => ['type' => 'git']]);

        $decision = Bootstrap::mcpConfigDecision($root);

        self::assertSame(Bootstrap::MCP_ABSENT, $decision['status']);
        self::assertSame($this->home . '/.candy-code/' . Bootstrap::MCP_USER_CONFIG_FILENAME, $decision['userPath']);
        self::assertSame(Bootstrap::MCP_TRUSTED, $decision['userStatus']);
    }

    public function testTheUserPathFollowsTheSandboxHome(): void
    {
        $root = $this->makeRoot();
        $this->writeUserServers(['solo' => ['type' => 'git']]);

        $first = Bootstrap::mcpConfigDecision($root)['userPath'];
        self::assertStringStartsWith($this->home . '/', (string) $first, 'the tier is read from THIS process home, not the passwd home');

        // Re-pointing HOME moves the tier with it — the same live read every
        // other user-tier surface gets from HomeDirectory::owned().
        $other = $this->tmpDir . '/home2-' . bin2hex(random_bytes(4));
        mkdir($other . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($other);
        $second = Bootstrap::mcpConfigDecision($root)['userPath'];
        self::assertSame($other . '/.candy-code/mcp.json', $second);
        self::assertSame(Bootstrap::MCP_ABSENT, Bootstrap::mcpConfigDecision($root)['userStatus']);
    }

    public function testAHomeTheUserDoesNotOwnSilencesTheTier(): void
    {
        $root = $this->makeRoot();
        $this->writeUserServers(['solo' => ['type' => 'git']]);
        // The ownership gate IS the ceremony: a world-writable home is not
        // verifiably this user's, so the tier reads as absent — silently, with
        // no notice, because this file is optional by design.
        chmod($this->home, 0o777);

        try {
            $decision = Bootstrap::mcpConfigDecision($root);

            self::assertSame('', $decision['userPath']);
            self::assertSame(Bootstrap::MCP_ABSENT, $decision['userStatus']);
            self::assertNull(Bootstrap::mcpClient($root), 'an unowned home must launch nothing, project or user');
            // KEY-SCOPED (E737): the refusal claim is about THIS root's key,
            // not about the whole pid bucket a shared runner may own.
            self::assertArrayNotHasKey($this->memoKeyFor($root), $this->readMemo()[getmypid() ?: 0] ?? []);
        } finally {
            chmod($this->home, 0o700);
        }
    }

    public function testTheDisabledSwitchAnswersForBothTiers(): void
    {
        $root = $this->makeRoot();
        $this->writeUserServers(['solo' => ['type' => 'git']]);
        putenv(Bootstrap::MCP_DISABLE_ENV . '=1');

        try {
            $decision = Bootstrap::mcpConfigDecision($root);

            self::assertSame(Bootstrap::MCP_DISABLED, $decision['status']);
            self::assertSame('', $decision['userPath'], 'a disabled launch reads no user file either');
            self::assertSame(Bootstrap::MCP_DISABLED, $decision['userStatus']);
        } finally {
            putenv(Bootstrap::MCP_DISABLE_ENV);
        }
    }

    // -------------------------------------------------------------------------
    // The trust boundary: user entries need no repo ceremony, and the
    // project ceremony never swallows them
    // -------------------------------------------------------------------------

    public function testUserServersStartWithoutAnyProjectGrant(): void
    {
        $root = $this->makeRoot();
        // The project has an .mcp.json and is NOT trusted — the operator
        // never ran `mcp trust`. Its server must not start; the user's must.
        $projectMark = $this->tmpDir . '/project-side.mark';
        $this->writeProjectServers($root, ['project-solo' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $projectMark], 'startTimeout' => 0.5]]);
        // No config.json grant at all.
        $this->writeUserServers(['usersolo' => ['type' => 'git']]);

        $client = Bootstrap::mcpClient($root);
        $this->launchedClient = $client;

        self::assertInstanceOf(McpClient::class, $client, 'the launch gate ORs across the two tiers — an untrusted project alone must not veto the operator roster');
        self::assertSame(['usersolo'], array_keys($client->startedSnapshot()));
        self::assertFileDoesNotExist($projectMark, 'the launch of a user-trusted session must never re-open a project file the gate refused');
        self::assertSame([], $client->refusedServers(), 'there is nothing to refuse in a file the operator just wrote');
    }

    public function testUserEntriesNeverReachThePinRecord(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        $this->writeUserServers(['mail' => ['type' => 'git']]);

        $client = Bootstrap::mcpClient($root);
        $this->launchedClient = $client;
        self::assertInstanceOf(McpClient::class, $client);
        self::assertSame(['ledger', 'mail'], array_keys($client->startedSnapshot()), 'both tiers start under one grant...');

        $record = McpTrustPins::load($this->home . '/.candy-code/' . McpTrustPins::FILENAME)
            ->forRoot((string) Bootstrap::mcpConfigDecision($root)['canonicalRoot']);

        self::assertIsArray($record);
        self::assertSame(['ledger'], array_keys($record), '...but only the PROJECT tier is pinned: a user server must never be persisted under a project grant');
    }

    public function testAnAddedUserServerStartsWithoutReTrustWhileAnAddedProjectServerIsRefused(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        $this->writeUserServers(['mail' => ['type' => 'git']]);

        $first = Bootstrap::mcpClient($root);
        self::assertInstanceOf(McpClient::class, $first);
        $first->stopServers();
        Bootstrap::stopMcpServers();

        // One new server in each file. The project one is refused until
        // `mcp trust`; the user one starts — same launch, opposite posture.
        $this->writeProjectServers($root, [
            'ledger' => ['type' => 'git'],
            'late-project' => ['type' => 'git'],
        ]);
        $this->writeUserServers([
            'mail' => ['type' => 'git'],
            'late-user' => ['type' => 'git'],
        ]);

        $second = Bootstrap::mcpClient($root);
        $this->launchedClient = $second;
        self::assertInstanceOf(McpClient::class, $second);

        self::assertSame(['ledger', 'mail', 'late-user'], array_keys($second->startedSnapshot()));
        self::assertSame(['late-project'], $second->refusedServers());
    }

    public function testMcpTrustReportsOnlyTheProjectTier(): void
    {
        $root = $this->makeRoot();
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        $this->writeUserServers(['mail' => ['type' => 'git']]);

        $report = Bootstrap::trustProjectMcp($root);

        self::assertTrue($report['granted']);
        self::assertSame(['ledger'], array_column($report['servers'], 'name'), '`mcp trust` stays project-only: the review must not list entries its record can never carry');
    }

    // -------------------------------------------------------------------------
    // Precedence: the PROJECT wins a shared name, at launch and in listing
    // -------------------------------------------------------------------------

    public function testTheProjectWinsANameCollisionAtLaunch(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $projectMark = $this->tmpDir . '/dup-project.mark';
        $userMark = $this->tmpDir . '/dup-user.mark';

        $this->writeProjectServers($root, ['dup' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $projectMark], 'startTimeout' => 0.5]]);
        $this->writeUserServers([
            'dup' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $userMark], 'startTimeout' => 0.5],
            'usersolo' => ['type' => 'git'],
        ]);

        $client = Bootstrap::mcpClient($root);
        $this->launchedClient = $client;
        self::assertInstanceOf(McpClient::class, $client);

        // Which FILE the winning `dup` build came from is ground truth in the
        // markers, not in the client's own bookkeeping (a touch-and-exit stdio
        // server may or may not survive long enough to appear in the
        // snapshot — the marker answers the precedence question either way).
        self::assertFileExists($projectMark, 'the repo chose the name, so the repo entry is what runs — matching the trust record');
        self::assertFileDoesNotExist($userMark, 'the shadowed user entry must never start');
        self::assertArrayHasKey('usersolo', $client->startedSnapshot(), 'shadowing is per-name: the user tier keeps everything else');
    }

    public function testAShadowedUserEntryIsInvisibleToEveryReport(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        // The user file mis-shapes the name the project owns. "The higher
        // tier owns the name, including the right to mis-shape it": the
        // shadow test runs BEFORE the shape check, so this entry produces no
        // refusal sentence at all.
        $this->writeProjectServers($root, ['dup' => ['type' => 'git']]);
        $this->writeUserServers(['dup' => 'oops', 'quiet' => ['type' => 'git']]);

        $client = Bootstrap::mcpClient($root);
        $this->launchedClient = $client;
        self::assertInstanceOf(McpClient::class, $client);

        self::assertSame(['dup', 'quiet'], array_keys($client->startedSnapshot()));
        self::assertSame([], $client->refusedServers());
        $mine = array_values(array_filter(
            Bootstrap::launchNotices(),
            fn (string $notice): bool => str_contains($notice, $this->tmpDir),
        ));
        self::assertSame([], $mine, 'a shadowed entry may not surface in ANY notice — the whole point is that this launch has nothing to say about the user file\'s dup row');
    }

    public function testTheProjectWinsTheCollisionInTheListingToo(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['dup' => ['type' => 'git']]);
        $this->writeUserServers(['dup' => ['type' => 'http', 'url' => 'https://mcp.invalid.test/mcp'], 'solo' => ['type' => 'git']]);

        $inventory = Bootstrap::mcpServerInventory($root);

        self::assertSame(['dup'], array_column($inventory['servers'], 'name'));
        self::assertSame(['solo'], array_column($inventory['userServers'], 'name'), 'a listing that showed the shadowed row would promise a server the launch will never start');
        self::assertNotContains('https://mcp.invalid.test/mcp', array_column($inventory['userServers'], 'detail'));
    }

    public function testTheListingSaysNothingAboutTheUserTierWhenTheFileIsAbsent(): void
    {
        $root = $this->makeRoot();

        $inventory = Bootstrap::mcpServerInventory($root);

        self::assertSame(Bootstrap::MCP_ABSENT, $inventory['userStatus']);
        self::assertSame([], $inventory['userServers']);
        self::assertSame([], $inventory['userSkipped']);
        self::assertNull($inventory['userError']);
    }

    public function testAProjectFileThatCannotBeDecodedListsTheUserTierButStartsNothing(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, '{not json');
        $userMark = $this->tmpDir . '/user-side.mark';
        $this->writeUserServers(['dup' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $userMark], 'startTimeout' => 0.5]]);

        // The listing cannot know which names the unreadable project file
        // declared, so nothing is shadowed and the user rows ride through —
        // while the LAUNCH aborts on the project file before either tier's
        // loop, so no user server silently survives a broken project config.
        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertNotNull($inventory['error']);
        self::assertSame(['dup'], array_column($inventory['userServers'], 'name'));

        // The LAUNCH reads the project file first and throws there — before
        // any tier's loop runs — so nothing starts, the user's marker is not
        // created, and the client is empty. The partial-start catch fires
        // error_log(); point it at a file so the pin can read the diagnostic
        // (and the suite's stderr stays clean) exactly like the shutdown-seam
        // test does.
        $log = $this->tmpDir . '/errlog';
        $previous = ini_set('error_log', $log);

        try {
            $client = Bootstrap::mcpClient($root);
            $this->launchedClient = $client;
            self::assertInstanceOf(McpClient::class, $client);
            self::assertFileDoesNotExist($userMark, 'a broken project file costs the whole launch, both tiers');
            self::assertSame([], $client->startedSnapshot());
            self::assertStringContainsString('could not be fully started', (string) file_get_contents($log));
        } finally {
            if ($previous === false) {
                ini_restore('error_log');
            } else {
                ini_set('error_log', $previous);
            }
        }
    }

    // -------------------------------------------------------------------------
    // E703-α across two files: the launch-freeze law is unchanged, and the
    // composite memo key is what keeps a mid-session user file from
    // double-building the client
    // -------------------------------------------------------------------------

    public function testBothTierDigestsFreezeAtTheLaunchInstant(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        $userPath = $this->writeUserServers(['mail' => ['type' => 'git']]);

        $client = Bootstrap::mcpClient($root);
        $this->launchedClient = $client;
        self::assertInstanceOf(McpClient::class, $client);

        $projectPath = Bootstrap::mcpConfigDecision($root)['path'];
        $bucket = $this->readDigests()[getmypid() ?: 0] ?? [];
        self::assertSame(
            hash('sha256', (string) file_get_contents($projectPath)),
            $bucket[$projectPath]['sha256'],
            'the project digest is not the launch bytes',
        );
        self::assertSame(
            hash('sha256', (string) file_get_contents($userPath)),
            $bucket[$userPath]['sha256'],
            'the user digest is not the launch bytes — E703-α digests BOTH files it read',
        );
        self::assertFalse(Bootstrap::mcpConfigChangedSinceLaunch($root));
    }

    public function testAMidSessionEditOfEitherTierFreezesTheClientButSpeaksUp(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        $userPath = $this->writeUserServers(['mail' => ['type' => 'git']]);

        $first = Bootstrap::mcpClient($root);
        self::assertInstanceOf(McpClient::class, $first);

        file_put_contents($userPath, "\n", FILE_APPEND);
        self::assertSame($first, Bootstrap::mcpClient($root), 'an edited USER file must not rebuild the client — the freeze is per-process for both files');
        self::assertTrue(Bootstrap::mcpConfigChangedSinceLaunch($root), 'a user-tier drift must speak: the verdict is one across both tiers');

        Bootstrap::stopMcpServers();
        $second = Bootstrap::mcpClient($root);
        $this->launchedClient = $second;
        self::assertNotSame($first, $second, 'a fresh launch after teardown reads the new bytes');
        self::assertFalse(Bootstrap::mcpConfigChangedSinceLaunch($root));
    }

    public function testAUserFileCreatedMidSessionNeitherRebuildsNorRelaunchesNorClaimsDrift(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        // No user file yet: the memo key is ALREADY the composite whenever
        // the home resolves — that is what makes this test's answers the
        // freeze-correct ones. If the key waited on file EXISTENCE, creating
        // the file below would miss the memo, build a SECOND client and
        // start the project servers twice.
        $first = Bootstrap::mcpClient($root);
        self::assertInstanceOf(McpClient::class, $first);

        $this->writeUserServers(['mail' => ['type' => 'git']]);

        self::assertSame($first, Bootstrap::mcpClient($root), 'a user file that appeared mid-session must not double-build the client');
        self::assertSame(['ledger'], array_keys($first->startedSnapshot()), '...and its servers must not appear');
        self::assertFalse(Bootstrap::mcpConfigChangedSinceLaunch($root), 'a tier the launch never read has no launch bytes to drift from — silence here would be a guess about the OTHER file, which is what false means: unchanged since launch');
    }

    public function testAUserOnlyLaunchStoresNoProjectDigest(): void
    {
        $root = $this->makeRoot();
        $this->writeProjectServers($root, ['project-solo' => ['type' => 'git']]);
        // Untrusted project, trusted user file: the refused file has no
        // "since launch" to drift from because the launch read nothing.
        $this->writeUserServers(['mail' => ['type' => 'git']]);

        $client = Bootstrap::mcpClient($root);
        $this->launchedClient = $client;
        self::assertInstanceOf(McpClient::class, $client);

        $bucket = $this->readDigests()[getmypid() ?: 0] ?? [];
        self::assertArrayNotHasKey(Bootstrap::mcpConfigDecision($root)['path'], $bucket, 'a file the gate refused carries no digest');
        self::assertSame(Bootstrap::MCP_TRUSTED, Bootstrap::mcpConfigDecision($root)['userStatus']);
    }

    public function testStopMcpServersDropsBothTierBuckets(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeProjectServers($root, ['ledger' => ['type' => 'git']]);
        $userPath = $this->writeUserServers(['mail' => ['type' => 'git']]);

        $client = Bootstrap::mcpClient($root);
        self::assertInstanceOf(McpClient::class, $client);
        $key = $this->memoKeyFor($root);
        $client->stopServers();

        Bootstrap::stopMcpServers();

        $pid = getmypid() ?: 0;
        // KEY-SCOPED (E737 law): never assert whole-bucket emptiness under a
        // sharded runner — a sibling class may own other keys in this pid.
        self::assertArrayNotHasKey($key, $this->readMemo()[$pid] ?? [], 'the composite-keyed memo entry drains with the pid like the path-keyed one did');
        self::assertArrayNotHasKey($userPath, $this->readDigests()[$pid] ?? []);
    }

    public function testTheCompositeKeySeparatesTwoHomesOnOneProject(): void
    {
        $root = $this->makeRoot();
        $this->grantProjectTrust($root);
        $this->writeUserServers(['mail' => ['type' => 'git']]);
        $first = Bootstrap::mcpClient($root);
        self::assertInstanceOf(McpClient::class, $first);
        $first->stopServers();

        // A different home is a different operator roster ⇒ a different
        // composite key ⇒ a fresh client, while the SAME home keeps its
        // memoised one.
        $other = $this->tmpDir . '/home3-' . bin2hex(random_bytes(4));
        mkdir($other . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($other);
        $this->grantProjectTrustAt($root, $other);
        $this->writeUserServersAt($other, ['other-server' => ['type' => 'git']]);

        $second = Bootstrap::mcpClient($root);
        $this->launchedClient = $second;
        self::assertInstanceOf(McpClient::class, $second);
        self::assertNotSame($first, $second, 'a re-pointed home must not read the previous home\'s memo entry');
        self::assertSame(['other-server'], array_keys($second->startedSnapshot()));
        self::assertSame($second, Bootstrap::mcpClient($root), '...but the new home memoises normally under its own key');
    }

    // -------------------------------------------------------------------------
    // Fixture plumbing
    // -------------------------------------------------------------------------

    private function makeRoot(): string
    {
        $root = $this->tmpDir . '/proj-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);

        return $root;
    }

    private function grantProjectTrust(string $root): void
    {
        $this->grantProjectTrustAt($root, $this->home);
    }

    private function grantProjectTrustAt(string $root, string $home): void
    {
        $canonical = realpath($root);
        self::assertIsString($canonical);
        file_put_contents($home . '/.candy-code/config.json', json_encode([
            'trustedProjectMcp' => [$canonical],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return string the user file's path, for digest arithmetic */
    private function writeUserServers(array $servers): string
    {
        return $this->writeUserServersAt($this->home, $servers);
    }

    /** @return string the user file's path */
    private function writeUserServersAt(string $home, array $servers): string
    {
        $path = $home . '/.candy-code/' . Bootstrap::MCP_USER_CONFIG_FILENAME;
        file_put_contents($path, json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function writeProjectServers(string $root, array $servers): void
    {
        file_put_contents(
            $root . '/' . Bootstrap::MCP_CONFIG_FILENAME,
            json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<int, array<string, McpClient>> */
    private function readMemo(): array
    {
        $ref = new \ReflectionProperty(Bootstrap::class, 'mcpClients');
        $ref->setAccessible(true);

        /** @var array<int, array<string, McpClient>> $value */
        $value = $ref->getValue();

        return $value;
    }

    /** @param array<int, array<string, McpClient>> $memo */
    private function writeMemo(array $memo): void
    {
        $ref = new \ReflectionProperty(Bootstrap::class, 'mcpClients');
        $ref->setAccessible(true);
        $ref->setValue(null, $memo);
    }

    /** @return array<int, array<string, array{sha256: string, mtime: int}>> */
    private function readDigests(): array
    {
        $ref = new \ReflectionProperty(Bootstrap::class, 'mcpConfigDigests');
        $ref->setAccessible(true);

        /** @var array<int, array<string, array{sha256: string, mtime: int}>> $value */
        $value = $ref->getValue();

        return $value;
    }

    /** @param array<int, array<string, array{sha256: string, mtime: int}>> $digests */
    private function writeDigests(array $digests): void
    {
        $ref = new \ReflectionProperty(Bootstrap::class, 'mcpConfigDigests');
        $ref->setAccessible(true);
        $ref->setValue(null, $digests);
    }

    /**
     * MCP-3: the memo is keyed by {@see Bootstrap::mcpConfigMemoKey()}, the
     * composite of the two canonical tier paths — not by the project path
     * alone. Derived through the production builder rather than re-spelled
     * here: a plant this file computed by hand would keep passing while the
     * real keying drifted under it, which is the one lie a keying pin cannot
     * tell. (The DIGESTS stay keyed per file path — a drift verdict is about
     * a file; only the CLIENT memo composites.)
     */
    private function memoKeyFor(string $root): string
    {
        $builder = new \ReflectionMethod(Bootstrap::class, 'mcpConfigMemoKey');

        return (string) $builder->invoke(null, Bootstrap::mcpConfigDecision($root));
    }

    private function purgeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
