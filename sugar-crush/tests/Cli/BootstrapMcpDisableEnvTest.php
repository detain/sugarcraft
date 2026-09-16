<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * E737 (residual 1): the `SUGARCRUSH_MCP_DISABLE` opt-out hatch.
 *
 * WHAT THE GATE Owes THE CALLERS. `mcpConfigDecision()` is THE one discovery
 * path — `mcpClient()`, `mcpServerInventory()`, `mcpLivenessSnapshot()` and
 * `mcpConfigChangedSinceLaunch()` all route through it — so the disable branch
 * must answer the EXACT shape of a config-less miss: a well-formed array with
 * the canonical `path` present and `status === MCP_ABSENT`. A null, a missing
 * key, or a fifth status none of the consumers special-case would turn an
 * operator's opt-out into a crash in their own tools panel. This class pins
 * that contract for every reader, plus the never-builds witness (a TRUSTED
 * project whose only server would leave a mark if it ever ran), plus the
 * narrower truthy vocabulary — `1`, `true`, `yes` case-insensitive DISABLE;
 * unset, `0`, empty and every other value stay DEFAULT-ON, so a launcher that
 * inherits a dirty environment still gets the documented behavior.
 *
 * THE THIRD WITNESS IS THE SHELL, NOT PHP. `scripts/parallel-tests.sh` sets
 * the variable as a COMMAND PREFIX on the shard launch and nowhere else
 * (E737: K=8 shards sit at the checkout root, where the operator's own trusted
 * `.mcp.json` is live); {@see testTheShardLauncherPrefixesTheGateOnThePhpunitLine()}
 * pins that spelling, and {@see testTheShardLauncherStaysTheOnlyScriptThatSetsTheGate()}
 * proves no sibling script — and no bare `export` — ever does. A serial run
 * never goes through the script, so serial stays default-on by construction.
 *
 * Helpers are byte-copied from {@see BootstrapMcpLivenessTest()} under the
 * drift guard's own definition (identical name + identical body = one helper,
 * reported as nothing); lane-df's precedent.
 */
final class BootstrapMcpDisableEnvTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var array<int, array<string, McpClient>> */
    private array $memoBefore = [];

    /** @var string|false the env value as this process found it, false = unset */
    private string|false $gateBefore;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateBefore = getenv(Bootstrap::MCP_DISABLE_ENV);
        $this->tmpDir = sys_get_temp_dir() . '/sc_mcp_disable_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);

        $this->memoBefore = $this->readMemo();
    }

    protected function tearDown(): void
    {
        if ($this->gateBefore === false) {
            putenv(Bootstrap::MCP_DISABLE_ENV);
        } else {
            putenv(Bootstrap::MCP_DISABLE_ENV . '=' . $this->gateBefore);
        }

        $this->writeMemo($this->memoBefore);
        $this->purgeGateFixtureTree($this->tmpDir);
        $this->restoreHomeSandbox();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // (a) the truthy side — disable wins over a TRUSTED, present config
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function truthyGateValues(): array
    {
        return [
            'one' => ['1'],
            'lower true' => ['true'],
            'lower yes' => ['yes'],
            'upper TRUE' => ['TRUE'],
            'mixed Yes' => ['Yes'],
            'mixed TrUe' => ['TrUe'],
        ];
    }

    /**
     * THE OVERRIDE, at its strongest: config present, root granted trust in
     * the HOME freeze — and the decision still answers the absent shape with
     * the canonical path intact. This is what makes the hatch an override of
     * the trust gate rather than a peer of it.
     *
     * @dataProvider truthyGateValues
     */
    public function testATruthyGateSilencesEvenATrustedPresentConfig(string $value): void
    {
        putenv(Bootstrap::MCP_DISABLE_ENV . '=' . $value);
        $root = $this->makeLivenessRoot();
        $this->writeConfigNaming($root, ['ledger' => ['type' => 'git']]);
        $this->grantProjectTrust($root);

        $decision = Bootstrap::mcpConfigDecision($root);

        self::assertSame(Bootstrap::MCP_ABSENT, $decision['status'], 'the gate must answer the config-less miss shape, not a fifth status');
        self::assertIsString($decision['path']);
        self::assertStringEndsWith('/' . Bootstrap::MCP_CONFIG_FILENAME, $decision['path']);
        self::assertIsString($decision['root']);
        self::assertArrayHasKey('canonicalRoot', $decision, 'the decision array must stay well-formed for every reader');
    }

    /**
     * THE NEVER-BUILDS WITNESS — three witnesses on the only reader that
     * spawns: under the gate, `mcpClient()` answers null for a TRUSTED root
     * whose single declared server is `touch mark`; the memo never gains the
     * key; the mark never exists. r4's pattern applied to the environment
     * door: the gate does not merely downgrade the status, it prevents the
     * client from ever existing.
     */
    public function testTheGateKeepsTheLaunchDoorShutWithoutMemoisingAnything(): void
    {
        putenv(Bootstrap::MCP_DISABLE_ENV . '=1');
        $root = $this->makeLivenessRoot();
        $mark = $this->tmpDir . '/never-ran.mark';
        $this->writeConfigNaming($root, ['sneaky' => ['command' => '/bin/sh', 'args' => ['-c', 'touch ' . $mark]]]);
        $this->grantProjectTrust($root);

        self::assertNull(Bootstrap::mcpClient($root), 'the gate must refuse the build, not return a client that starts nothing');
        self::assertArrayNotHasKey(
            $this->canonicalDecisionPath($root),
            $this->readMemo()[getmypid()] ?? [],
            'a gated cold read must not memoise a client it never built',
        );
        self::assertFileDoesNotExist($mark, 'a gated launch is the exact defect E737 closes');
    }

    /**
     * THE OTHER READERS, fed the gated decision: the listing says absent with
     * no rows, the liveness readout says null, the change verdict says silent
     * — every consumer walks its existing non-trusted arm on the well-formed
     * array. (This is the "no null-shape crash" half of the ruling.)
     */
    public function testEveryReaderWalksItsConfiglessArmUnderTheGate(): void
    {
        putenv(Bootstrap::MCP_DISABLE_ENV . '=yes');
        $root = $this->makeLivenessRoot();
        $this->writeConfigNaming($root, ['ledger' => ['type' => 'git']]);
        $this->grantProjectTrust($root);

        $inventory = Bootstrap::mcpServerInventory($root);
        self::assertSame(Bootstrap::MCP_ABSENT, $inventory['status']);
        self::assertSame([], $inventory['servers']);
        self::assertNull(Bootstrap::mcpLivenessSnapshot($root));
        self::assertNull(Bootstrap::mcpConfigChangedSinceLaunch($root));
    }

    // -------------------------------------------------------------------------
    // (b) the default-on polarity pair
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function nonTruthyGateValues(): array
    {
        return [
            'zero' => ['0'],
            'empty string' => [''],
            'two' => ['2'],
            'the word false' => ['false'],
            'the word no' => ['no'],
            'the surprise word disabled' => ['disabled'],
            'unset' => [null],
        ];
        // NOTE 'disabled' is deliberately listed: the narrow vocabulary means
        // it does NOTHING, and that is the documented, pinned contract.
    }

    /**
     * THE POLARITY PAIR of every truthy test above: the very same
     * trusted-present root must answer MCP_TRUSTED unless one of the three
     * words is set — a gate that read `0` or garbage as truthy would silently
     * MCP-off every shard inheriting an operator shell that merely exported
     * the name with a falsy value.
     *
     * @dataProvider nonTruthyGateValues
     */
    public function testAnythingButTheThreeWordsLeavesTheDecisionAtItsNormalVerdict(?string $value): void
    {
        if ($value === null) {
            putenv(Bootstrap::MCP_DISABLE_ENV);
        } else {
            putenv(Bootstrap::MCP_DISABLE_ENV . '=' . $value);
        }
        $root = $this->makeLivenessRoot();
        $this->writeConfigNaming($root, ['ledger' => ['type' => 'git']]);
        $this->grantProjectTrust($root);

        self::assertSame(Bootstrap::MCP_TRUSTED, Bootstrap::mcpConfigDecision($root)['status']);
    }

    // -------------------------------------------------------------------------
    // (c) the shell side — the shard launcher's command prefix
    // -------------------------------------------------------------------------

    /**
     * THE LAUNCH SHAPE. The gate must appear EXACTLY once on a live (non-comment)
     * line of the script, as a command prefix on the `timeout` invocation, so
     * it scopes to phpunit and its children — never exported into the runner
     * shell. The comment line mentioning it is allowed; a second live
     * occurrence (an export for the parent, say) would change serial-visible
     * behavior of the runner itself and is a red.
     */
    public function testTheShardLauncherPrefixesTheGateOnThePhpunitLine(): void
    {
        $script = self::readShardLauncher();

        $live = self::nonCommentLines($script);
        $setters = array_values(array_filter(
            $live,
            static fn (string $line): bool => str_contains($line, 'SUGARCRUSH_MCP_DISABLE='),
        ));

        self::assertCount(1, $setters, 'exactly one live line may set the gate: ' . implode(' | ', $setters));
        self::assertMatchesRegularExpression('/^\t+SUGARCRUSH_MCP_DISABLE=1 timeout "/', $setters[0], 'the gate must ride the phpunit launch as a command prefix');
        self::assertStringNotContainsString('export SUGARCRUSH_MCP_DISABLE', $script, 'a bare export would leak the gate into the runner shell and beyond');
    }

    /**
     * NOTHING ELSE SETS IT. Every file under scripts/ is walked; only the
     * shard launcher carries the variable at all, so the export cannot creep
     * into a CI-facing helper that serial runs also source.
     */
    public function testTheShardLauncherStaysTheOnlyScriptThatSetsTheGate(): void
    {
        $scriptsDir = \dirname(__DIR__, 3) . '/scripts';
        $carriers = [];
        foreach (scandir($scriptsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_file($scriptsDir . '/' . $entry)) {
                continue;
            }
            $text = (string) file_get_contents($scriptsDir . '/' . $entry);
            if (self::nonCommentLines($text) !== [] && preg_grep('/SUGARCRUSH_MCP_DISABLE=/', self::nonCommentLines($text)) !== []) {
                $carriers[] = $entry;
            }
        }

        self::assertSame(['parallel-tests.sh'], $carriers, 'scripts/ must gain exactly one setter of the gate: the shard launcher');
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

    /**
     * THE SCRIPT, read through the class file, not the cwd — the shard runner
     * itself sits at the repo root, but a serial developer run sits anywhere;
     * tests/Cli walks up to the checkout root that owns scripts/.
     */
    private static function readShardLauncher(): string
    {
        $path = \dirname(__DIR__, 3) . '/scripts/parallel-tests.sh';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The script text with whole-line comments dropped — the pin polices what
     * EXECUTES, not what prose mentions the gate.
     *
     * @return list<string>
     */
    private static function nonCommentLines(string $text): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if (ltrim($line, " \t") !== '' && !str_starts_with(ltrim($line), '#')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function purgeGateFixtureTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->purgeGateFixtureTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
