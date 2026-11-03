<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\StdioMcpServer;

/**
 * Trust boundary, pinned as BEHAVIOUR (review C1).
 *
 * A configured stdio server entry is arbitrary code execution as the invoking
 * user, and the child's environment is the sharp edge: the default connection
 * passes NO env map (proc_open inherits everything), and the `env:` option is
 * documented — and pinned here — as ADDITIVE ONLY: entries override or add
 * names, and nothing in this library ever removes one. Whatever ambient
 * secrets (SSH agent paths, cloud credentials, provider keys) live in the
 * parent's environment ride to every third-party server unless the embedder
 * narrows them through a `spawnPlanner`, which may return its own complete
 * env map.
 *
 * The child's own getenv() dump is the ground truth — not a mock's memory of
 * an argument. The probe name carries this pid plus a nonce so no unrelated
 * process environment could answer for it by accident.
 */
final class StdioMcpServerTrustBoundaryTest extends TestCase
{
    /** Upper bound any bounded give-up must complete under. */
    private const BOUND_SECONDS = 6.0;

    private readonly string $probeName;

    /** @var list<StdioMcpServer> servers still up when the test ends */
    private array $live = [];

    protected function setUp(): void
    {
        $this->probeName = 'MCP_TRUST_PROBE_' . getmypid() . '_' . bin2hex(random_bytes(4));

        if (putenv($this->probeName . '=visible-to-child') === false) {
            $this->fail('could not export the trust-boundary probe into the test process environment');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];

        putenv($this->probeName); // the unset form: a bare name removes it
    }

    private static function fixture(): string
    {
        return __DIR__ . '/Fixtures/env_dump_server.php';
    }

    /**
     * @param array<string,string> $env the constructor's `env:` map
     * @return array<array-key,mixed> the environment the child reported for itself
     */
    private function childEnvironment(array $env): array
    {
        $server = new StdioMcpServer(
            'trust-boundary',
            PHP_BINARY,
            [self::fixture()],
            env: $env,
            startTimeoutSeconds: self::BOUND_SECONDS,
        );
        $this->live[] = $server;
        $server->start();

        $result = $server->callTool('envdump', []);
        $text = (string) ($result['content'][0]['text'] ?? '');
        self::assertNotSame('', $text, 'the envdump tool must answer with a payload');

        $decoded = json_decode($text, true);
        self::assertIsArray($decoded, 'the envdump payload must be a JSON object of names');

        /** @var array<array-key,mixed> $decoded */
        return $decoded;
    }

    public function testTheDefaultConnectionHandsTheChildTheWholeParentEnvironment(): void
    {
        // `env: []` means inherit — the null proc_open map. A parent export the
        // server was never told about must still land in the child: this is
        // the hazard the README's Trust boundary section exists to disclose.
        $childEnv = $this->childEnvironment([]);

        self::assertArrayHasKey(
            $this->probeName,
            $childEnv,
            'with no env map the child inherits the FULL parent environment, ambient secrets included',
        );
        self::assertSame('visible-to-child', $childEnv[$this->probeName]);
    }

    public function testTheEnvOptionIsAdditiveAndNeverScrubsParentNames(): void
    {
        // With a map, spawnPlan copies getenv() and merges the overrides on
        // top: the caller can add and overwrite, but cannot REMOVE a name by
        // omitting it. Pinning both halves of that contract is what makes the
        // README sentence ("narrow through spawnPlanner") the only honest
        // remedy rather than advice about a door that already closes.
        $added = $this->probeName . '_ADDED';
        $childEnv = $this->childEnvironment([$added => 'explicit-override', 'PATH' => '/pinned-by-option']);

        self::assertSame('explicit-override', $childEnv[$added], 'the option must add its names');
        self::assertSame('/pinned-by-option', $childEnv['PATH'], 'the option must overwrite existing names');
        self::assertArrayHasKey(
            $this->probeName,
            $childEnv,
            'a name the option never mentions must STILL reach the child — env: cannot scrub, only add',
        );
    }
}
