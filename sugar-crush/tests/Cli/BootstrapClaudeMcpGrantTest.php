<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\McpLaunchEnabledTrait;

/**
 * E699 — the operator tier of the `claude-mcp` transport: what
 * {@see Bootstrap::claudeMcpGrant()} answers, from which tier it may answer
 * at all, and the one process-level law the whole gate hangs on: the grant
 * is consulted INSIDE the launch-frozen client memo, so a config edit after
 * a launch cannot re-spawn anything until the next run.
 */
final class BootstrapClaudeMcpGrantTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    /** @var array<int, array<string, McpClient>> */
    private array $memoBefore;

    /** @var array<int, array<string, array{sha256: string, mtime: int}>> */
    private array $digestsBefore;

    private string $ocGrantHome;

    private string $ocGrantTmp;

    protected function setUp(): void
    {
        parent::setUp();

        // lane-cf law: the trait snapshots HOME on its first touch, so the
        // sandbox goes up BEFORE anything else reads or writes env.
        $this->ocGrantHome = $this->useHomeSandbox(
            sys_get_temp_dir() . '/oc_grant_home_' . bin2hex(random_bytes(6)),
        );
        mkdir($this->ocGrantHome . '/.sugar-crush', 0o700, true);

        $this->ocGrantTmp = sys_get_temp_dir() . '/oc_grant_' . bin2hex(random_bytes(6));
        mkdir($this->ocGrantTmp, 0o700, true);

        // E737: this suite asserts real builds, so it owns its launch gate —
        // armed AFTER the HOME sandbox per lane-cf law, never exported.
        $this->armMcpLaunchEnabled();

        $memo = new \ReflectionProperty(Bootstrap::class, 'mcpClients');
        $memo->setAccessible(true);
        /** @var array<int, array<string, McpClient>> $bucket */
        $bucket = $memo->getValue();
        $this->memoBefore = $bucket;

        // mcpClient() writes the config-digest memo too (E703-α); a launch
        // here leaves rows behind, so the digest bucket is snapshotted and
        // restored exactly like BootstrapMcpLivenessTest does — otherwise
        // this file pollutes the liveness suite's cold-process assertions.
        $digests = new \ReflectionProperty(Bootstrap::class, 'mcpConfigDigests');
        $digests->setAccessible(true);
        /** @var array<int, array<string, array{sha256: string, mtime: int}>> $dig */
        $dig = $digests->getValue();
        $this->digestsBefore = $dig;
    }

    protected function tearDown(): void
    {
        $memo = new \ReflectionProperty(Bootstrap::class, 'mcpClients');
        $memo->setAccessible(true);
        $memo->setValue(null, $this->memoBefore);

        $digests = new \ReflectionProperty(Bootstrap::class, 'mcpConfigDigests');
        $digests->setAccessible(true);
        $digests->setValue(null, $this->digestsBefore);

        $this->ocRemoveTree($this->ocGrantTmp);
        $this->ocRemoveTree($this->ocGrantHome);
        $this->restoreHomeSandbox();
        $this->restoreMcpLaunchEnabled();

        parent::tearDown();
    }

    public function testAnOperatorWhoSaidNothingGrantsNothing(): void
    {
        self::assertNull(Bootstrap::claudeMcpGrant());
    }

    public function testTheTripleRidesThroughVerbatimAndAbsentPartnersStayNull(): void
    {
        $this->ocWriteUserConfig([
            'claudeMcpBinary' => '/opt/claude/bin/claude',
        ]);
        $grant = Bootstrap::claudeMcpGrant();
        self::assertIsArray($grant);
        self::assertSame('/opt/claude/bin/claude', $grant['binary']);
        self::assertNull($grant['args']);
        self::assertNull($grant['env']);

        $this->ocWriteUserConfig([
            'claudeMcpBinary' => '/opt/claude/bin/claude',
            'claudeMcpArgs' => ['--mcp', '--tweaked'],
            'claudeMcpEnv' => ['ANTHROPIC_MODEL' => 'fake'],
        ]);
        $grant = Bootstrap::claudeMcpGrant();
        self::assertSame(['--mcp', '--tweaked'], $grant['args']);
        self::assertSame(['ANTHROPIC_MODEL' => 'fake'], $grant['env']);
    }

    /**
     * Shape errors THROW naming the key — a config error the launch catch
     * reports on both channels — because silently ignoring a malformed
     * operator grant would turn "the operator meant this" into "the
     * operator meant nothing", and the refusal is the operator's signal
     * that their config was not understood.
     *
     * @return array<array{string, mixed}>
     */
    public static function malformedGrantShapes(): array
    {
        return [
            ['claudeMcpBinary', 42],
            ['claudeMcpArgs', ['keyed' => 'not a list']],
            ['claudeMcpArgs', [['nested']]],
            ['claudeMcpEnv', 'string-not-object'],
            ['claudeMcpEnv', ['K' => ['nested']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedGrantShapes')]
    public function testEachMalformedKeyThrowsAndNamesItself(string $key, mixed $value): void
    {
        $this->ocWriteUserConfig([$key => $value] + ['claudeMcpBinary' => '/bin/true']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($key);

        Bootstrap::claudeMcpGrant();
    }

    /**
     * THE TIER PIN: a PROJECT settings layer that names the binary must
     * change nothing — this is the sentence the double opt-in is built on.
     * The repository may REQUEST a claude-mcp entry; only the user files
     * may GRANT the path, and `readUserConfig()`'s merged view (project
     * included) is deliberately NOT what the reader consults.
     */
    public function testAProjectLayerCannotNameTheBinary(): void
    {
        $project = $this->ocGrantTmp . '/project';
        mkdir($project . '/.sugar-crush', 0o700, true);
        file_put_contents($project . '/.sugar-crush/settings.json', json_encode([
            'claudeMcpBinary' => '/from/the/repo/binary',
        ], JSON_THROW_ON_ERROR));
        Bootstrap::useProjectRootForSettings($project);

        self::assertNull(Bootstrap::claudeMcpGrant());
    }

    /**
     * THE LAUNCH FREEZE, at the layer that makes the gate trustworthy: the
     * grant is read from inside the per-(pid, path) client memo, so once a
     * process has answered `claude-mcp` once, editing EITHER config file
     * cannot build a second client, spawn a second child, or change what
     * the first client holds until relaunch. The child here is a silent
     * script — its tools/list never answers, the start is skipped as a
     * runtime failure, and the memo still holds the client, which is
     * exactly the degraded-not-refused posture under test.
     */
    public function testTheGrantIsFrozenForTheProcessByTheClientMemo(): void
    {
        $root = $this->ocGrantTmp . '/trusted-project';
        mkdir($root, 0o700, true);
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => ['cc' => ['type' => 'claude-mcp']],
        ], JSON_THROW_ON_ERROR));

        $silent = $this->ocGrantTmp . '/silent.php';
        file_put_contents($silent, "<?php\nusleep(250000);\n");

        $this->ocWriteUserConfig([
            'trustedProjectMcp' => [realpath($root)],
            'claudeMcpBinary' => PHP_BINARY,
            'claudeMcpArgs' => [$silent],
        ]);

        $first = Bootstrap::mcpClient($root);
        self::assertInstanceOf(McpClient::class, $first);

        // Mid-"session" edits: the operator yanks the grant AND the repo
        // drops the entry. The memo already froze both reads for this pid.
        $this->ocWriteUserConfig(['trustedProjectMcp' => []]);
        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => [],
        ], JSON_THROW_ON_ERROR));

        $second = Bootstrap::mcpClient($root);
        self::assertSame($first, $second, 'a second mcpClient() call rebuilt the launch — the freeze is gone');

        $first->stopServers();
    }

    /**
     * THE PATH-NEVER-LEAKS PIN on the readout surface: with a configured
     * claude-mcp entry (operator path well under /tmp here, which the
     * inventory COULD echo), the inventory row's detail is the fixed label
     * and the operator path appears nowhere in the structure.
     */
    public function testTheInventoryReportsTheFixedLabelAndNeverEchoesTheOperatorPath(): void
    {
        $root = $this->ocGrantTmp . '/inventory-project';
        mkdir($root, 0o700, true);
        $binary = $this->ocGrantTmp . '/operator-claude';
        file_put_contents($binary, "<?php\n");
        chmod($binary, 0o755);

        file_put_contents($root . '/' . Bootstrap::MCP_CONFIG_FILENAME, json_encode([
            'mcpServers' => ['cc' => ['type' => 'claude-mcp']],
        ], JSON_THROW_ON_ERROR));
        $this->ocWriteUserConfig([
            'trustedProjectMcp' => [realpath($root)],
            'claudeMcpBinary' => $binary,
        ]);

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_TRUSTED, $inventory['status'], 'the fixture must actually reach the trusted branch');
        self::assertNull($inventory['error']);

        $row = null;
        foreach ($inventory['servers'] as $candidate) {
            if (($candidate['name'] ?? null) === 'cc') {
                $row = $candidate;
            }
        }
        self::assertIsArray($row, 'the entry must be listed as configured');
        self::assertSame('claude-mcp', $row['type']);
        self::assertSame('(operator-supplied)', $row['detail']);
        self::assertStringNotContainsString($binary, json_encode($inventory, JSON_THROW_ON_ERROR));
    }

    // -- harness ------------------------------------------------------------

    /**
     * @param array<string, mixed> $values
     */
    private function ocWriteUserConfig(array $values): void
    {
        file_put_contents(
            $this->ocGrantHome . '/.sugar-crush/config.json',
            json_encode($values, JSON_THROW_ON_ERROR),
        );

        $roots = new \ReflectionProperty(Bootstrap::class, 'trustedSettingsRoots');
        $roots->setAccessible(true);
        $roots->setValue(null, []);
    }

    private function ocRemoveTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item->getPathname()) : @unlink((string) $item->getPathname());
        }
        @rmdir($dir);
    }
}
