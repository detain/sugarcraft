<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\McpClient;

/**
 * C-MCP-1: where a `stdio` server's child process starts, decided by tier.
 *
 * Before this fix every stdio server inherited the launch cwd, so a
 * checked-out project's `.mcp.json` (whose bytes are not a safety boundary)
 * could move every server it names — plus every server the OPERATOR file
 * names — into whichever directory whoever typed `candy-code` happened to
 * stand in, and a server resolving `.` or its config-relative files read
 * from there. Now the tier the entry was read from decides: the project
 * entry spawns at the root holding its `.mcp.json` (never a deeper
 * subdirectory), the user entry at the home the trust gate verified, and an
 * entry may pin an absolute directory of its own to win either way. A
 * relative or missing `cwd` is a config error costing only its own entry.
 *
 * The roots carry this pid plus a random nonce, so an unmoved child could
 * never produce their names by accident, and the launch happens from a
 * third directory (repo/sub) so "inherited", "project root" and "home" are
 * three distinguishable answers at every assert.
 */
final class StdioMcpUserTierCwdTest extends TestCase
{
    private string $root;
    private string $repo;
    private string $sub;
    private string $homeRoot;
    private string $pinned;
    private string $script;
    private string $projectPath;
    private string $userPath;
    private ?McpClient $client = null;
    private readonly string $savedCwd;

    protected function setUp(): void
    {
        $this->savedCwd = (string) getcwd();
        $this->root = sys_get_temp_dir() . '/cc-mcp-cwd-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->repo = $this->root . '/repo';
        $this->sub = $this->repo . '/sub';
        $this->homeRoot = $this->root . '/home';
        $this->pinned = $this->root . '/pinned';
        $this->script = $this->root . '/where_server.php';
        $this->projectPath = $this->repo . '/.mcp.json';
        // The shape McpDecision::mcpUserConfigPath() composes from the
        // owned home — two directories below the file IS the home root,
        // which is exactly what stdioSpawnCwd() derives.
        $this->userPath = $this->homeRoot . '/.candy-code/mcp.json';

        foreach ([$this->root, $this->repo, $this->sub, $this->pinned, dirname($this->userPath)] as $dir) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                $this->fail('could not build the tier fixture tree');
            }
        }

        file_put_contents($this->script, <<<'PHP'
            <?php

            declare(strict_types=1);

            // Tier-fixture server: answers the handshake and reports its own
            // getcwd() from tools/call "where" — the child's ground truth of
            // the directory proc_open started it in.

            $born = microtime(true);

            while (($line = fgets(STDIN)) !== false) {
                if (microtime(true) - $born > 30.0) {
                    exit(0);
                }

                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['method'], $msg['id'])) {
                    continue;
                }

                $reply = static function (array $result) use ($msg): void {
                    echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
                    fflush(STDOUT);
                };

                switch ($msg['method']) {
                    case 'initialize':
                        $reply([
                            'protocolVersion' => '2024-11-05',
                            'capabilities' => new stdClass(),
                            'serverInfo' => ['name' => 'tier-fixture', 'version' => '0.0.1'],
                        ]);
                        break;

                    case 'tools/list':
                        $reply(['tools' => [[
                            'name' => 'where',
                            'description' => 'Reports this process\'s working directory',
                            'inputSchema' => ['type' => 'object', 'properties' => []],
                        ]]]);
                        break;

                    case 'tools/call':
                        $reply(['content' => [['type' => 'text', 'text' => (string) getcwd()]]]);
                        break;

                    default:
                        echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'],
                            'error' => ['code' => -32601, 'message' => 'Method not found']]), "\n";
                        fflush(STDOUT);
                }
            }

            exit(0);
            PHP);
    }

    protected function tearDown(): void
    {
        $this->client?->stopServers();
        $this->client = null;

        chdir($this->savedCwd);
        self::removeTree($this->root);
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function writeTier(string $path, array $servers): void
    {
        file_put_contents($path, (string) json_encode(['mcpServers' => $servers]));
    }

    /** @return array<string, mixed> */
    private function whereEntry(?string $cwd = null): array
    {
        $entry = ['command' => PHP_BINARY, 'args' => [$this->script]];
        if ($cwd !== null) {
            $entry['cwd'] = $cwd;
        }

        return $entry;
    }

    private function launchClient(string $projectPath, string $userPath): McpClient
    {
        $client = new McpClient($projectPath, null, null, true, userConfigPath: $userPath);
        $this->client = $client;

        return $client;
    }

    /** What the live child of $serverName says its own working directory is. */
    private function where(McpClient $client, string $serverName): string
    {
        $result = $client->callTool($serverName, 'where', []);

        return (string) ($result['content'][0]['text'] ?? json_encode($result));
    }

    public function testProjectTierSpawnsAtTheRootHoldingItsConfig(): void
    {
        $this->writeTier($this->projectPath, ['proj' => $this->whereEntry()]);
        $this->writeTier($this->userPath, []);

        // The launch stands in a subdirectory: a child that inherited the
        // cwd would answer $this->sub, which is neither expected value.
        chdir($this->sub);
        $client = $this->launchClient($this->projectPath, $this->userPath);
        $client->startServers();

        self::assertSame($this->repo, $this->where($client, 'proj'));
    }

    public function testUserTierSpawnsAtTheHomeNotTheCheckout(): void
    {
        $this->writeTier($this->projectPath, []);
        $this->writeTier($this->userPath, ['usr' => $this->whereEntry()]);

        chdir($this->sub);
        $client = $this->launchClient($this->projectPath, $this->userPath);
        $client->startServers();

        // The headline fix: the operator's own server never runs "wherever
        // the last checkout happened to be" — it runs at the home the
        // ownership gate verified for the file it was read from.
        self::assertSame($this->homeRoot, $this->where($client, 'usr'));
    }

    public function testExplicitAbsoluteCwdWinsOnEitherTier(): void
    {
        $this->writeTier($this->projectPath, ['proj' => $this->whereEntry($this->pinned)]);
        $this->writeTier($this->userPath, ['usr' => $this->whereEntry($this->pinned)]);

        chdir($this->sub);
        $client = $this->launchClient($this->projectPath, $this->userPath);
        $client->startServers();

        self::assertSame($this->pinned, $this->where($client, 'proj'));
        self::assertSame($this->pinned, $this->where($client, 'usr'));
    }

    public function testRelativeCwdIsAConfigErrorCostingOnlyItsEntry(): void
    {
        $this->writeTier($this->projectPath, [
            'relative' => $this->whereEntry('sub'),
            'sibling' => $this->whereEntry(),
        ]);
        $this->writeTier($this->userPath, []);

        $client = $this->launchClient($this->projectPath, $this->userPath);

        $caught = null;
        try {
            $client->startServers();
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        self::assertNotNull($caught, 'a relative cwd must be refused, not honoured');
        self::assertStringContainsString('relative', (string) $caught);
        self::assertStringContainsString('absolute existing directory', (string) $caught);
        // The per-entry law: the good sibling is up and reachable despite
        // its neighbour's bad spelling — startServers attempts every entry
        // and reports after the loop.
        self::assertSame($this->repo, $this->where($client, 'sibling'));
    }

    public function testMissingDirectoryAndNonStringCwdAreRefusedToo(): void
    {
        $this->writeTier($this->projectPath, [
            'gone' => $this->whereEntry($this->root . '/no-such-dir'),
            'numeric' => ['command' => PHP_BINARY, 'args' => [$this->script], 'cwd' => 7],
        ]);
        $this->writeTier($this->userPath, []);

        $client = $this->launchClient($this->projectPath, $this->userPath);

        $caught = null;
        try {
            $client->startServers();
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('gone', (string) $caught);
        self::assertStringContainsString('numeric', (string) $caught);
        self::assertStringContainsString('got int', (string) $caught);
    }

    public function testNoTierProvenanceKeepsTheInheritedSpawn(): void
    {
        // The structural half: buildServer without a tier path — the shape
        // every pre-MCP-3 construction and reflection caller takes — must
        // hand the library a null cwd, i.e. byte-identical historic spawn.
        $method = new \ReflectionMethod(McpClient::class, 'buildServer');
        $method->setAccessible(true);
        $client = $this->launchClient('', '');

        $server = $method->invoke($client, 'plain', 'stdio', [
            'command' => PHP_BINARY,
            'args' => [$this->script],
        ]);

        $transport = new \ReflectionProperty(\SugarCraft\Code\MCP\StdioMcpServer::class, 'transport');
        $transport->setAccessible(true);
        $library = $transport->getValue($server);

        $cwd = new \ReflectionProperty(\SugarCraft\McpClient\StdioMcpServer::class, 'cwd');
        $cwd->setAccessible(true);
        self::assertNull($cwd->getValue($library));

        // And a project tier path resolves through to the library untouched.
        $server = $method->invoke($client, 'plain', 'stdio', $this->whereEntry(), $this->projectPath);
        self::assertSame($this->repo, $cwd->getValue($transport->getValue($server)));
    }

    /** Remove a directory tree this test created, contents and all. */
    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);

                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }
}
