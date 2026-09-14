<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\MCP\ClaudeCodeMcpServer;
use SugarCraft\Crush\MCP\GitMcpServer;
use SugarCraft\Crush\MCP\HttpMcpServer;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * E699 — the `type` switch stays the switch: `buildServer` grows exactly one
 * arm, and that arm's ONLY inputs are the entry's shape and the operator
 * tier. Reflection drives the factory directly so no spawn and no launch
 * machinery is implicated; the adapter's behavior over a live child is
 * {@see ClaudeCodeMcpServerTest}'s half.
 */
final class McpClientClaudeTypeFactoryTest extends TestCase
{
    use HomeSandboxTrait;

    private string $ocFactoryHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ocFactoryHome = $this->useHomeSandbox(
            sys_get_temp_dir() . '/oc_factory_' . bin2hex(random_bytes(6)),
        );
        mkdir($this->ocFactoryHome . '/.sugar-crush', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->ocFactoryDeleteHome();
        $this->restoreHomeSandbox();

        parent::tearDown();
    }

    public function testTheArmBuildsTheAdapterFromTheOperatorGrant(): void
    {
        $this->ocOperatorGrants(PHP_BINARY);

        $server = $this->ocBuild('cc', 'claude-mcp', ['type' => 'claude-mcp']);

        self::assertInstanceOf(ClaudeCodeMcpServer::class, $server);
        self::assertSame(ClaudeCodeMcpServer::DEFAULT_ARGS, $server->spawnArgs);
    }

    /**
     * Ungated: the throw names the KEY so the operator can act on it, and
     * the build — which never spawned anything — fails as a CONFIG error,
     * the family the launch catch reports rather than swallows.
     */
    public function testTheArmWithoutAGrantNamesTheOperatorKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('claudeMcpBinary');

        $this->ocBuild('cc', 'claude-mcp', ['type' => 'claude-mcp']);
    }

    /**
     * Order of the two doors: an entry that names a spawn key costs itself
     * the server EVEN AGAINST A VALID GRANT — and with no grant at all, the
     * ENTRY-side message still wins, because "the repository does not name
     * this binary" must never be diagnosable only by first configuring a
     * binary the operator did not intend to.
     */
    public function testTheRepositoryKeyCheckPrecedesTheGrantCheck(): void
    {
        $named = null;
        try {
            $this->ocBuild('greedy', 'claude-mcp', [
                'type' => 'claude-mcp',
                'command' => '/somewhere/else',
            ]);
        } catch (\RuntimeException $e) {
            $named = $e;
        }
        self::assertNotNull($named, 'an entry naming command must cost the server');
        self::assertStringContainsString('does not name this binary', $named->getMessage());

        $this->ocOperatorGrants('/bin/sh');
        $namedAgain = null;
        try {
            $this->ocBuild('greedy2', 'claude-mcp', [
                'type' => 'claude-mcp',
                'args' => ['--dangerous'],
            ]);
        } catch (\RuntimeException $e) {
            $namedAgain = $e;
        }
        self::assertNotNull($namedAgain, 'an entry naming args must cost the server even under a valid grant');
        self::assertStringContainsString('does not name this binary', $namedAgain->getMessage());
    }

    /**
     * The neighbours are untouched: the existing three types keep their
     * classes, the unknown-type throw keeps its exact grammar — and its
     * near-miss `claude` (the provider-id family) still lands in `default`,
     * which is what makes `claude-mcp` a NEW spelling rather than a
     * tolerated typo.
     */
    public function testTheSiblingsAndTheDefaultArmKeepTheirShapes(): void
    {
        $stdio = $this->ocBuild('s', 'stdio', ['command' => '/bin/true']);
        self::assertInstanceOf(StdioMcpServer::class, $stdio);

        $http = $this->ocBuild('h', 'http', ['url' => 'https://example.invalid/mcp']);
        self::assertInstanceOf(HttpMcpServer::class, $http);

        $git = $this->ocBuild('g', 'git', []);
        self::assertInstanceOf(GitMcpServer::class, $git);

        $unknown = null;
        try {
            $this->ocBuild('c?', 'claude', []);
        } catch (\RuntimeException $e) {
            $unknown = $e;
        }
        self::assertNotNull($unknown, 'the provider-id spelling must not be silently accepted');
        self::assertStringContainsString('Unknown MCP server type: claude', $unknown->getMessage());
    }

    /**
     * The panel/liveness surface learns the transport WITHOUT a launch:
     * a built-but-unstarted adapter reports its type, `up=false` via the
     * widened narrowing, and its empty start-time tool count — and
     * pumpStderr() reaches the new instanceof arm without throwing on a
     * server that never connected.
     */
    public function testStartedSnapshotAndPumpCoverTheNewTransportWithoutLaunching(): void
    {
        $this->ocOperatorGrants(PHP_BINARY);
        /** @var ClaudeCodeMcpServer $server */
        $server = $this->ocBuild('cc', 'claude-mcp', ['type' => 'claude-mcp']);

        $client = new McpClient($this->ocFactoryHome . '/mcp-config-unused.json');
        $seed = new \ReflectionProperty(McpClient::class, 'servers');
        $seed->setAccessible(true);
        $seed->setValue($client, ['cc' => $server]);

        $snapshot = $client->startedSnapshot();
        self::assertSame('claude-mcp', $snapshot['cc']['transport']);
        self::assertFalse($snapshot['cc']['up']);
        self::assertSame(0, $snapshot['cc']['tools']);

        $client->pumpStderr(); // widened arm: no-throw on a never-connected server
        $client->stopServers();
    }

    // -- harness ------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     *
     * @return \SugarCraft\Crush\MCP\McpServer
     */
    private function ocBuild(string $name, string $type, array $config)
    {
        $client = new McpClient($this->ocFactoryHome . '/mcp-config-unused.json');
        $build = new \ReflectionMethod(McpClient::class, 'buildServer');
        $build->setAccessible(true);

        try {
            return $build->invoke($client, $name, $type, $config);
        } finally {
            unset($client);
        }
    }

    private function ocOperatorGrants(string $binary): void
    {
        file_put_contents(
            $this->ocFactoryHome . '/.sugar-crush/config.json',
            json_encode(['claudeMcpBinary' => $binary], JSON_THROW_ON_ERROR),
        );
        // The reader rides permissionConfig(); no process-global memo feeds it,
        // but the settings ROOT cache does exist in Bootstrap — reset it so a
        // previous test's refused read cannot ride into this grant.
        $roots = new \ReflectionProperty(Bootstrap::class, 'trustedSettingsRoots');
        $roots->setAccessible(true);
        $roots->setValue(null, []);
    }

    private function ocFactoryDeleteHome(): void
    {
        $dir = $this->ocFactoryHome . '/.sugar-crush';
        if (is_file($dir . '/config.json')) {
            @unlink($dir . '/config.json');
        }
        @rmdir($dir);
        @rmdir($this->ocFactoryHome);
    }
}
