<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\MCP\GitMcpServer;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * E698: {@see Bootstrap::mcpLivenessSnapshot()} reads the memo and NOTHING
 * else — above all, it never calls {@see Bootstrap::mcpClient()}, because the
 * only other way to answer "what is up" is to build the client, and building
 * it STARTS every server the repository names. A status readout that can
 * launch arbitrary programs is the exact defect the trust gate exists to
 * prevent, arriving through the side door of an innocuous panel.
 *
 * The memo is a private process-global (lane-ce's precedent): each test
 * snapshots it via reflection, installs exactly the state it means to probe,
 * and restores the WHOLE bucket array afterwards so nothing leaks into the
 * sibling suites that share this pid.
 */
final class BootstrapMcpLivenessTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var array<int, array<string, McpClient>> */
    private array $memoBefore = [];

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/sc_mcp_liveness_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);

        $this->memoBefore = $this->readMemo();
    }

    protected function tearDown(): void
    {
        $this->writeMemo($this->memoBefore);
        $this->removeLeftoverTree();
        $this->restoreHomeSandbox();

        parent::tearDown();
    }

    /**
     * THE NEVER-LAUNCHES PIN, in its strongest shape: a TRUSTED project whose
     * only declared server is a command that would leave a mark if it ever
     * ran. The cold snapshot must answer null AND the memo must still be
     * empty AND the mark must not exist — three witnesses that the readout
     * read the memo rather than the filesystem's willingness to spawn.
     */
    public function testAColdSnapshotAnswersNullAndLaunchesNothing(): void
    {
        $root = $this->makeLivenessRoot();
        $mark = $this->tmpDir . '/never-ran.mark';
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => ['sneaky' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $mark]]],
        ], JSON_THROW_ON_ERROR));
        $this->grantProjectTrust($root);

        self::assertNull(Bootstrap::mcpLivenessSnapshot($root));
        self::assertSame([], $this->readMemo()[getmypid()] ?? [], 'the snapshot must not memoise a client it found missing');
        self::assertFileDoesNotExist($mark, 'a liveness readout that spawned the declared server is the defect this method forbids');
    }

    /**
     * A project with no `.mcp.json` at all answers null through the same
     * keying — the decision path runs (it owns the canonical path), the memo
     * simply has nothing under it.
     */
    public function testAnAbsentProjectSnapshotsNull(): void
    {
        $root = $this->makeLivenessRoot();

        self::assertNull(Bootstrap::mcpLivenessSnapshot($root));
    }

    /**
     * Memo HIT with a started client: the panel gets the client's own rows,
     * byte-for-byte what startedSnapshot() produced — the Bootstrap seam adds
     * and filters nothing.
     */
    public function testAMemoisedClientFlowsThroughUntouched(): void
    {
        $root = $this->makeLivenessRoot();
        $this->writeConfigNaming($root, ['ledger' => ['type' => 'git']]);
        $this->grantProjectTrust($root);

        $git = new GitMcpServer(handlers: new \SugarCraft\Crush\MCP\GitCommandHandlers(cwd: $root));
        $git->start();
        $client = new McpClient($this->canonicalDecisionPath($root));
        $this->plantServers($client, ['ledger' => $git]);
        $this->plantInMemo($root, $client);

        $snapshot = Bootstrap::mcpLivenessSnapshot($root);

        self::assertIsArray($snapshot);
        self::assertSame($client->startedSnapshot(), $snapshot);
        self::assertSame('git', $snapshot['ledger']['transport']);
        self::assertTrue($snapshot['ledger']['up']);

        $git->stop();
    }

    /**
     * THE TWO DIFFERENT NOTHINGS: a client exists but zero servers came up —
     * the panel must still render its block (every declared row gets
     * ` · not up`), so the method answers the EMPTY MAP, never a null that
     * would collapse "started nothing" into "never asked".
     */
    public function testAStartedClientWithNoServersSnapshotsTheEmptyMap(): void
    {
        $root = $this->makeLivenessRoot();
        $this->writeConfigNaming($root, ['doomed' => ['command' => '/nonexistent/binary-for-mbp']]);
        $this->grantProjectTrust($root);

        $client = new McpClient($this->canonicalDecisionPath($root));
        $this->plantInMemo($root, $client);

        self::assertSame([], Bootstrap::mcpLivenessSnapshot($root));
    }

    /**
     * KEYING IS THE CANONICAL DECISION PATH, not the spelled root: planting
     * the memo under a different path — even one naming the same file through
     * a `..` hop — must NOT be visible to a snapshot asked for this root. A
     * miss here is correct-by-design: this process has no client for THIS
     * decision path.
     */
    public function testTheSnapshotIsKeyedByTheCanonicalDecisionPathNotTheSpelling(): void
    {
        $root = $this->makeLivenessRoot();
        $this->writeConfigNaming($root, ['solo' => ['type' => 'git']]);
        $this->grantProjectTrust($root);

        $spelledRoot = $root . '/./';
        $client = new McpClient($this->tmpDir . '/some-other-corner.json');
        $git = new GitMcpServer(handlers: new \SugarCraft\Crush\MCP\GitCommandHandlers(cwd: $root));
        $this->plantServers($client, ['solo' => $git]);
        $this->plantInMemo($root, $client);

        // Same canonical path via a different spelling: the decision
        // canonicalises, so the snapshot finds the planted client.
        self::assertIsArray(Bootstrap::mcpLivenessSnapshot($spelledRoot));

        // A root this process never memoised: null, not the other project's map.
        $other = $this->makeLivenessRoot();
        self::assertNull(Bootstrap::mcpLivenessSnapshot($other));
    }

    /**
     * UNTRUSTED project: the snapshot answers what the memo says WITHOUT
     * re-deciding — but the memo can only hold a client that mcpClient() built
     * WHILE the root was trusted, and the panel suppresses the whole block on
     * a non-trusted inventory anyway (pinned in McpPanelTest). Here the cold
     * untrusted path answers null, which is the truth: this process started
     * nothing for it.
     */
    public function testAnUntrustedColdProjectSnapshotsNull(): void
    {
        $root = $this->makeLivenessRoot();
        $this->writeConfigNaming($root, ['hidden' => ['command' => '/bin/true']]);
        // Deliberate: NO grantProjectTrust() call.

        self::assertNull(Bootstrap::mcpLivenessSnapshot($root));
        self::assertSame([], $this->readMemo()[getmypid()] ?? []);
    }

    // -------------------------------------------------------------------------
    // Fixture plumbing
    // -------------------------------------------------------------------------

    /**
     * @param array<string, array<string, string>> $servers
     */
    private function writeConfigNaming(string $root, array $servers): void
    {
        file_put_contents(
            $root . '/' . Bootstrap::MCP_CONFIG_FILENAME,
            json_encode(['mcpServers' => $servers], JSON_THROW_ON_ERROR),
        );
    }

    private function makeLivenessRoot(): string
    {
        $root = $this->tmpDir . '/live-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);

        return $root;
    }

    /**
     * Grant trust the shipped way — the per-process freeze reads the HOME
     * config once, so every fresh root needs its own sandbox home (lane-ce
     * law: snapshot statics, restore them; here the trait owns HOME and the
     * memo is handled below).
     */
    private function grantProjectTrust(string $root): void
    {
        $home = $this->tmpDir . '/home-' . bin2hex(random_bytes(4));
        $this->useHomeSandbox($home);
        mkdir($home . '/.sugar-crush', 0o700, true);

        $canonical = realpath($root);
        self::assertIsString($canonical);
        file_put_contents($home . '/.sugar-crush/config.json', json_encode([
            'trustedProjectMcp' => [$canonical],
        ], JSON_THROW_ON_ERROR));
    }

    private function canonicalDecisionPath(string $root): string
    {
        $decision = Bootstrap::mcpConfigDecision($root);

        return (string) $decision['path'];
    }

    /**
     * @param array<string, \SugarCraft\Crush\MCP\McpServer> $servers
     */
    private function plantServers(McpClient $client, array $servers): void
    {
        $ref = new \ReflectionProperty(McpClient::class, 'servers');
        $ref->setAccessible(true);
        $ref->setValue($client, $servers);
    }

    private function plantInMemo(string $root, McpClient $client): void
    {
        $memo = $this->readMemo();
        $pid = getmypid() ?: 0;
        $memo[$pid][$this->canonicalDecisionPath($root)] = $client;
        $this->writeMemo($memo);
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

    private function removeLeftoverTree(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }
}
