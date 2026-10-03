<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\Support\ProcessContainment;

/**
 * Item 0.14-b, decision D3: an MCP stdio server no longer inherits every
 * credential in the operator's shell. `.mcp.json` is repository content and a
 * trusted server used to get the GitHub token, the cloud keys and the
 * provider keys whether it needed them or not; now it gets what its entry
 * DECLARES in `env` (or `secretEnvAllowlist` releases), the rest of the
 * environment unchanged, and the launch can name what was withheld.
 *
 * Only FAKE credentials are planted, with putenv(), and each is restored.
 *
 * @see ProcessContainment::mcpEnv()
 */
final class StdioMcpEnvScrubTest extends TestCase
{
    private const PLANTED = [
        'GITHUB_TOKEN' => 'ghp_FAKE_d3',
        'ACME_API_KEY' => 'FAKE-d3-acme',
        'OPENAI_API_KEY' => 'sk-FAKE-d3',
        'SUGARCRUSH_D3_PLAIN' => 'plain-survives',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    private string $workDir;

    private ?McpClient $client = null;

    protected function setUp(): void
    {
        foreach (self::PLANTED as $name => $value) {
            $this->saved[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        ProcessContainment::useSecretEnvAllowlist([]);

        $this->workDir = sys_get_temp_dir() . '/sc_mcp_env_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        file_put_contents($this->workDir . '/env.php', <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $msg = json_decode($line, true);
                if (!is_array($msg) || !isset($msg['id'])) { continue; }
                $out = ['jsonrpc' => '2.0', 'id' => $msg['id']];
                if ($msg['method'] === 'initialize') {
                    $out['result'] = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(),
                        'serverInfo' => ['name' => 'env', 'version' => '0']];
                } elseif ($msg['method'] === 'tools/list') {
                    $out['result'] = ['tools' => [['name' => 'env', 'description' => 'reports its env',
                        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]]]];
                } elseif ($msg['method'] === 'tools/call') {
                    $out['result'] = ['content' => [['type' => 'text', 'text' => json_encode(getenv())]]];
                }
                echo json_encode($out), "\n";
                fflush(STDOUT);
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->client?->stopServers();
        foreach ($this->saved as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        ProcessContainment::useSecretEnvAllowlist([]);
        foreach (['/env.php', '/.mcp.json'] as $file) {
            @unlink($this->workDir . $file);
        }
        @rmdir($this->workDir);
    }

    /**
     * @param array<string, string> $env the entry's `env` block
     * @return array<string, string> what the server process actually saw
     */
    private function serverEnv(array $env): array
    {
        $entry = ['command' => PHP_BINARY, 'args' => [$this->workDir . '/env.php']];
        if ($env !== []) {
            $entry['env'] = $env;
        }
        file_put_contents($this->workDir . '/.mcp.json', (string) json_encode(['mcpServers' => ['probe' => $entry]]));

        $this->client = new McpClient($this->workDir . '/.mcp.json', unrestricted: true);
        $this->client->startServers();
        $raw = $this->client->callTool('probe', 'env', []);
        $seen = json_decode((string) ($raw['content'][0]['text'] ?? ''), true);
        self::assertIsArray($seen, 'fixture: the probe server must report its environment');

        return $seen;
    }

    public function testAnUndeclaredInheritedCredentialNeverReachesTheServer(): void
    {
        $seen = $this->serverEnv([]);

        self::assertArrayNotHasKey('GITHUB_TOKEN', $seen);
        self::assertArrayNotHasKey('ACME_API_KEY', $seen);
        self::assertArrayNotHasKey('OPENAI_API_KEY', $seen);
        self::assertSame('plain-survives', $seen['SUGARCRUSH_D3_PLAIN'] ?? null, 'non-credential variables are still inherited');
        self::assertArrayHasKey('PATH', $seen);
    }

    public function testADeclaredCredentialIsTheGrant(): void
    {
        $seen = $this->serverEnv(['GITHUB_TOKEN' => '${GITHUB_TOKEN}']);

        self::assertSame('ghp_FAKE_d3', $seen['GITHUB_TOKEN'] ?? null, 'a ${VAR} declaration passes the inherited value');
        self::assertArrayNotHasKey('ACME_API_KEY', $seen, 'declaring one credential releases only that one');
    }

    public function testTheAllowlistReleasesACredentialToEveryServer(): void
    {
        ProcessContainment::useSecretEnvAllowlist(['ACME_*']);

        $seen = $this->serverEnv([]);

        self::assertSame('FAKE-d3-acme', $seen['ACME_API_KEY'] ?? null);
        self::assertArrayNotHasKey('GITHUB_TOKEN', $seen);
    }

    /** The launch notice's source: names (never values) of what was withheld. */
    public function testTheClientNamesWhatEachServerWasStartedWithout(): void
    {
        $this->serverEnv(['GITHUB_TOKEN' => '${GITHUB_TOKEN}']);

        $stripped = $this->client?->strippedSecretEnv() ?? [];
        self::assertArrayHasKey('probe', $stripped);
        self::assertContains('ACME_API_KEY', $stripped['probe']);
        self::assertContains('OPENAI_API_KEY', $stripped['probe']);
        self::assertNotContains('GITHUB_TOKEN', $stripped['probe'], 'a declared name was not withheld');
        self::assertNotContains('SUGARCRUSH_D3_PLAIN', $stripped['probe']);

        $notice = (string) $this->client?->strippedSecretEnvNotice();
        self::assertStringContainsString('probe (', $notice);
        self::assertStringContainsString('ACME_API_KEY', $notice);
        self::assertStringContainsString('secretEnvAllowlist', $notice);
        self::assertStringNotContainsString('FAKE-d3-acme', $notice, 'the notice must never carry a value');
    }

    public function testTheSpawnPlanAppliesTheScrubAndRecordsIt(): void
    {
        $server = new StdioMcpServer('plan', PHP_BINARY, [], []);
        $plan = (new \ReflectionMethod($server, 'spawnPlan'))->invoke($server, 'plan', [PHP_BINARY], ['GITHUB_TOKEN' => 'declared']);

        self::assertSame('declared', $plan[1]['GITHUB_TOKEN'] ?? null);
        self::assertArrayNotHasKey('ACME_API_KEY', $plan[1]);
        self::assertSame('0', $plan[1]['GIT_TERMINAL_PROMPT'] ?? null, 'the fail-fast block still applies');
        self::assertContains('ACME_API_KEY', $server->strippedSecretEnv());
        self::assertNotContains('GITHUB_TOKEN', $server->strippedSecretEnv());
    }

    public function testNothingWithheldMeansNoNotice(): void
    {
        $client = new McpClient($this->workDir . '/absent.json', unrestricted: true);

        self::assertSame([], $client->strippedSecretEnv());
        self::assertSame('', $client->strippedSecretEnvNotice());
    }
}
