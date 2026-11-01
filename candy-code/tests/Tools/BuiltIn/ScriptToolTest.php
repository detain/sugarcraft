<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\SuiteSkipRoster;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\BuiltIn\ScriptTool;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\DispatchesNestedTools;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * C-TOOL-6.d pins for the `Script` catalog tool: the registration entry, the
 * argument schema, the timeout clamp, the §6.3 jail floor (every arm that
 * cannot show a working jail REFUSES — there is no ungated fallback), the
 * call ledger every result ends with, and the end-to-end legs that run a real
 * scripted child through the fd-3 bridge: one through a stand-in backend that
 * always answers "the jail starts here" (so the wiring is pinned on every
 * host), and one under the real bubblewrap jail wherever it starts, where the
 * script's nested calls actually Read and Write a temp file through the
 * bridge while the child itself stays confined.
 */
final class ScriptToolTest extends TestCase
{
    /** @var list<string> temp trees to remove */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::wipeDir($dir);
        }
        $this->dirs = [];

        parent::tearDown();
    }

    public function testTheCatalogEntryCarriesTheDesignRegistration(): void
    {
        $entry = null;
        foreach (ToolCatalog::entries() as $candidate) {
            if ($candidate->name === ScriptTool::NAME) {
                self::assertNull($entry, 'the catalog named Script twice');
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry, 'Script is not in the built-in catalog');
        self::assertSame(46, $entry->position, 'positions 44 and 45 are Advisor and Canvas on disk; the drafted 44 was stale');
        self::assertSame(ToolPermissionClass::Write, $entry->permission);
        self::assertSame(
            'run a short PHP script whose only effects are tool calls, each permissioned like a direct call',
            $entry->gloss,
        );
        self::assertSame(ToolPermissionClass::Write, ToolCatalog::permissionOf(ScriptTool::NAME));

        $concerns = class_implements(ScriptTool::class);
        self::assertIsArray($concerns);
        foreach ([Tool::class, BuildsFromCatalog::class, AcceptsHeartbeat::class, AcceptsWorktreeJail::class, DispatchesNestedTools::class] as $interface) {
            self::assertContains($interface, $concerns, 'Script must carry ' . $interface);
        }
        // A script is one call the engine must not fan out: without this the
        // concurrent scheduler could run two bridge children side by side.
        self::assertNotContains('SugarCraft\Code\Tools\ParallelSafe', $concerns);
    }

    public function testTheSchemaRequiresCodeAndCarriesTheCeilings(): void
    {
        $schema = (new ScriptTool())->inputSchema();

        self::assertSame(['code'], $schema['required']);
        self::assertSame('string', $schema['properties']['code']['type']);
        self::assertSame('number', $schema['properties']['timeout']['type']);
        self::assertSame('string', $schema['properties']['description']['type']);
        self::assertStringContainsString((string) ScriptTool::MAX_CODE_BYTES, $schema['properties']['code']['description']);
        self::assertStringContainsString((string) (int) ScriptTool::MAX_TIMEOUT_SECONDS, $schema['properties']['timeout']['description']);
    }

    public function testTheNestedBindingIsPerExecutionAndImmutable(): void
    {
        $tool = new ScriptTool();
        $bound = $tool->withNestedDispatch(
            static fn (string $name, array $arguments): ToolResult => new ToolResult(toolCallId: '', content: 'x'),
            static fn (): array => [],
        );

        self::assertNotSame($tool, $bound);
        // The original stays UNBOUND — if the bound pair rode shared state,
        // two sessions reusing one built tool would leak dispatch across them.
        $unbound = $tool->execute(['code' => 'return 1;']);
        self::assertTrue($unbound->isError());
        self::assertStringContainsString('without its nested dispatch binding', $unbound->content());
    }

    public function testAnEmptyOrBlankScriptRefusesBeforeAnythingRuns(): void
    {
        foreach (['', '   ', "\n\t"] as $sent) {
            $result = $this->bridgeTool()->execute(['code' => $sent]);
            self::assertTrue($result->isError());
            self::assertStringContainsString('a non-empty `code` string is required', $result->content());
        }
    }

    public function testAnOversizedScriptRefusesWithTheMeasuredCount(): void
    {
        $code = str_repeat('a', ScriptTool::MAX_CODE_BYTES + 1);
        $result = $this->bridgeTool()->execute(['code' => $code]);

        self::assertTrue($result->isError());
        self::assertStringContainsString(
            sprintf('the script is %d bytes, over the %d-byte ceiling', strlen($code), ScriptTool::MAX_CODE_BYTES),
            $result->content(),
        );
    }

    public function testAPlatformWithNoBackendRefusesRatherThanRunUnconfined(): void
    {
        // §6.3 floor: a null jail is a REFUSAL, never a bare run. The only
        // arm allowed past this point is one with a working jail, so the
        // message must be the refusal, not a child result.
        $tool = $this->bridgeTool(jail: null);
        $result = $tool->execute(['code' => 'return 1;']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('no sandbox backend exists for this platform', $result->content());
        self::assertStringContainsString('unconfined', $result->content());
    }

    public function testAProbeThatCannotStartRefusesQuotingItsOwnDescribe(): void
    {
        $sentinel = 'DESCRIBED-' . uniqid((string) getmypid(), true);
        $jail = new class ($sentinel) implements SandboxBackend {
            public function __construct(private readonly string $describeAnswer) {}

            public function name(): string
            {
                return 'testjail';
            }

            public function mode(): string
            {
                return SandboxBackend::MODE_NO_NETWORK;
            }

            public function resolvedBinary(): string
            {
                return '/usr/bin/true';
            }

            /** @return list<string> */
            public function argv(string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): array
            {
                return [];
            }

            public function wrap(string $shellCommand, string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): string
            {
                return $shellCommand;
            }

            public function probe(\Closure $run): ?string
            {
                return 'the kernel refuses the namespaces this jail needs';
            }

            public function refusalRemedy(): string
            {
                return 'nothing to remedy in a test double';
            }

            public function describe(string $writableRoot): string
            {
                return $this->describeAnswer;
            }
        };

        $result = $this->bridgeTool(jail: $jail)->execute(['code' => 'return 1;']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('the testjail jail cannot start on this host', $result->content());
        self::assertStringContainsString('the kernel refuses the namespaces this jail needs', $result->content());
        self::assertStringContainsString('refuses rather than run the child unconfined', $result->content());
        self::assertStringContainsString($sentinel, $result->content(), 'the refusal quotes the backend describe(), the same discipline as Bash');
    }

    /**
     * @param array<array-key, mixed> $sent
     */
    #[DataProvider('timeoutSentences')]
    public function testTheTimeoutClampFloorsCapsAndDefaults(mixed $sent, float $expected): void
    {
        self::assertSame($expected, ScriptTool::clampTimeoutSeconds($sent));
    }

    /**
     * @return array<string, array{0: mixed, 1: float}>
     */
    public static function timeoutSentences(): array
    {
        return [
            'absent' => [null, 60.0],
            'nonsense' => ['soon', 60.0],
            'zero floors at one second, not an immediate timeout' => [0, 1.0],
            'negative floors too' => [-5, 1.0],
            'string number parses and rides' => ['42.5', 42.5],
            'over the ceiling caps' => [601, 600.0],
            'the ceiling itself stands' => [600.0, 600.0],
        ];
    }

    public function testARealBridgeRunLedgersEveryCallWithItsVerdict(): void
    {
        // The child is real (PHP_BINARY through the runner and the fd-3
        // bridge); the jail is a double that answers "it starts here" with an
        // empty prefix, so the wiring under test is identical on hosts without
        // bwrap. The confined arm proper is testAJailedEndToEndRunReadsAndEditsThroughTheBridge.
        $tool = $this->bridgeTool(
            nested: static function (string $name, array $arguments): ToolResult {
                if ($name === 'Probe') {
                    return new ToolResult(toolCallId: '', content: 'probe-ok');
                }
                if ($name === 'Boom') {
                    return new ToolResult(toolCallId: '', content: 'boom went wrong', isError: true);
                }

                return new ToolResult(
                    toolCallId: '',
                    content: 'Permission denied: the rule says no',
                    isError: true,
                    denial: DenialKind::Refused,
                );
            },
            catalog: static fn (): array => [['name' => 'Probe', 'signature' => 'Probe(file_path: string)']],
        );

        $result = $tool->execute([
            'code' => '$a = tool("Probe", ["file_path" => "/tmp/probe-target.txt"]); '
                . '$b = tool("Boom", ["cmd" => "explode"]); '
                . '$c = tool("Write", ["file_path" => "/tmp/no.txt"]); '
                . '$t = tools(); '
                . 'return ["ack" => $a["content"], "denied" => $c["isError"], "listed" => count($t) === 1];',
            'description' => 'ledger sweep',
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringStartsWith('ledger sweep', $result->content());
        self::assertStringContainsString('exit 0', $result->content());
        self::assertStringContainsString('"ack":"probe-ok"', $result->content(), 'the child read its frame content through the bridge');
        self::assertStringContainsString('"denied":true', $result->content(), 'a gate refusal reaches the script as an error frame, not a silent empty one');
        self::assertStringContainsString('"listed":true', $result->content(), 'tools() answers the bound catalog rows');
        self::assertStringContainsString('Probe /tmp/probe-target.txt -> ok', $result->content());
        self::assertStringContainsString('Boom explode -> error', $result->content());
        self::assertStringContainsString('Write /tmp/no.txt -> denied (refused)', $result->content());
        $calls = explode('--- calls', $result->content());
        self::assertCount(2, $calls, 'the ledger is the tail section');
        self::assertSame(
            ['Probe /tmp/probe-target.txt -> ok', 'Boom explode -> error', 'Write /tmp/no.txt -> denied (refused)'],
            array_values(explode("\n", trim($calls[1]))),
            'one row per bridge call, in order, and nothing else',
        );
    }

    public function testTheLedgerEndsWithNoneWhenTheScriptCalledNothing(): void
    {
        $result = $this->bridgeTool()->execute(['code' => 'echo "printed"; return 7;']);

        self::assertFalse($result->isError());
        self::assertStringContainsString('returned: 7', $result->content());
        self::assertStringContainsString('--- stdout', $result->content());
        self::assertStringContainsString('printed', $result->content());
        self::assertStringEndsWith('--- calls' . "\n" . 'none', $result->content());
    }

    public function testTheLedgerKeepsTheNewestRowsAndNamesWhatItDropped(): void
    {
        $tool = $this->bridgeTool(
            nested: static fn (string $name, array $arguments): ToolResult
                => new ToolResult(toolCallId: '', content: 'tick'),
        );

        // The count is MAX_LEDGER_ROWS + 6 spelled through the constant: a bare
        // literal at or over 60 in this body reads to {@see FixtureLifetimeCensusTest}'s
        // scanner as a fixture lifetime, and the assertion below is about the
        // ledger's own arithmetic, not a wall clock.
        $result = $tool->execute([
            'code' => '$i = 0; while ($i < ' . (ScriptTool::MAX_LEDGER_ROWS + 6) . ') { tool("Tick", ["n" => $i]); $i++; } return $i;',
        ]);

        self::assertFalse($result->isError());
        self::assertStringContainsString('(+6 earlier calls not shown)', $result->content());
        self::assertStringContainsString('Tick 69 -> ok', $result->content());
        self::assertStringNotContainsString('Tick 5 -> ok', $result->content(), 'the oldest rows collapsed, they are not still printed');
        $rows = explode("\n", trim(explode('--- calls', $result->content())[1]));
        self::assertCount(ScriptTool::MAX_LEDGER_ROWS + 1, $rows, 'the kept rows plus the collapse note');
    }

    public function testTheWallClockCeilingEndsTheRunAndSaysSo(): void
    {
        // `timeout: 0` floors to one second (clamp pinned above); the loop
        // would run forever otherwise. A ceiling the tool never honours is
        // the E646-shaped defect: this must come back TIMED OUT, not hung.
        $result = $this->bridgeTool()->execute([
            'code' => 'while (true) { }',
            'timeout' => 0,
        ]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('timed out', $result->content());
    }

    public function testTheHeartbeatRidesTheWaitOnTheChild(): void
    {
        $beats = 0;
        $tool = $this->bridgeTool();
        // 0.7s of waiting spans select slices of 0.2s — the runner forwards
        // each one, and this is the seam that keeps the UI row alive.
        $result = $tool->executeWithHeartbeat(
            ['code' => 'usleep(700000); return 1;'],
            static function () use (&$beats): void {
                $beats++;
            },
        );

        self::assertFalse($result->isError());
        self::assertGreaterThan(0, $beats, 'a script that waits must still beat');
    }

    public function testTheChildStartsInTheBoundWorkspace(): void
    {
        $dir = $this->makeTempDir('scripttool-cwd-');
        $result = $this->bridgeTool(root: $dir)->execute(['code' => 'return getcwd();']);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('returned: ' . json_encode(realpath($dir), JSON_UNESCAPED_SLASHES), $result->content());
    }

    public function testFromCatalogBuildsTheToolWithANoNetworkJailChoice(): void
    {
        $built = ScriptTool::fromCatalog(self::catalogContext());

        self::assertInstanceOf(ScriptTool::class, $built);
        self::assertSame(ScriptTool::NAME, $built->name());
        // The jail comes from the factory, not the bashSandbox setting; on
        // Linux the factory answer is a Bubblewrap instance regardless of
        // what the user set for Bash (the design floor — a script's child is
        // confined by a DIFFERENT question than Bash's mode).
        $jail = (new \ReflectionProperty(ScriptTool::class, 'jail'))->getValue($built);
        if (PHP_OS_FAMILY === 'Linux') {
            self::assertInstanceOf(Bubblewrap::class, $jail);
        }
    }

    public function testAJailedEndToEndRunReadsAndEditsThroughTheBridge(): void
    {
        if (!SuiteSkipRoster::scriptJailRuns()) {
            self::markTestSkipped(
                'bubblewrap cannot start on this host (no bwrap, or the kernel refuses the user '
                    . 'namespaces it needs), so the jailed arm has nothing to assert',
            );
        }

        $dir = $this->makeTempDir('scripttool-jail-');
        $file = $dir . '/target.txt';
        file_put_contents($file, "one\n");

        $tool = $this->bridgeTool(
            root: $dir,
            jail: Bubblewrap::new(network: false),
            nested: static function (string $name, array $arguments) use ($file): ToolResult {
                if ($name === 'Read') {
                    return new ToolResult(toolCallId: '', content: (string) file_get_contents($arguments['file_path']));
                }
                if ($name === 'Write') {
                    file_put_contents($arguments['file_path'], (string) $arguments['content']);

                    return new ToolResult(toolCallId: '', content: 'wrote ' . $arguments['file_path']);
                }

                return new ToolResult(toolCallId: '', content: 'unknown', isError: true);
            },
        );

        $result = $tool->execute([
            'code' => '$r = tool("Read", ["file_path" => ' . var_export($file, true) . ']); '
                . 'tool("Write", ["file_path" => ' . var_export($file, true) . ', "content" => "edited by script"]); '
                . 'return ["before" => $r["content"]];',
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('edited by script', file_get_contents($file), 'the nested Write landed');
        self::assertStringContainsString('"before":"one', $result->content(), 'the nested Read came back through the bridge inside the jailed child');
        self::assertStringContainsString('Read ' . $file . ' -> ok', $result->content());
        self::assertStringContainsString('-> ok', substr($result->content(), (int) strrpos($result->content(), 'Write')));
    }

    /**
     * A tool instance wired through a working bridge: the stand-in jail
     * answers "starts here" with an empty spawn prefix, so the SCRIPT side of
     * the seam — staging, the fd-3 bridge, dispatch, ledger — runs for real
     * without requiring this host to run bubblewrap.
     *
     * @param SandboxBackend|'STANDIN'|null $jail
     */
    private function bridgeTool(
        ?string $root = null,
        SandboxBackend|string|null $jail = 'STANDIN',
        ?\Closure $nested = null,
        ?\Closure $catalog = null,
    ): ScriptTool {
        return new ScriptTool(
            root: $root,
            jail: $jail === 'STANDIN' ? $this->standInJail() : $jail,
            nestedCall: $nested ?? static fn (string $name, array $arguments): ToolResult
                => new ToolResult(toolCallId: '', content: 'ack'),
            catalogRows: $catalog ?? static fn (): array => [],
        );
    }

    private function standInJail(): SandboxBackend
    {
        return new class implements SandboxBackend {
            public function name(): string
            {
                return 'standin';
            }

            public function mode(): string
            {
                return SandboxBackend::MODE_NO_NETWORK;
            }

            public function resolvedBinary(): string
            {
                return '/usr/bin/true';
            }

            /** @return list<string> */
            public function argv(string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): array
            {
                return [];
            }

            public function wrap(string $shellCommand, string $writableRoot, bool $interactive = false, ?string $tmpDir = null, ?string $chdir = null, array $readOnlyBinds = []): string
            {
                return $shellCommand;
            }

            public function probe(\Closure $run): ?string
            {
                return null;
            }

            public function refusalRemedy(): string
            {
                return '';
            }

            public function describe(string $writableRoot): string
            {
                return 'the stand-in jail confines nothing; it exists to answer the arms that only ask whether it starts';
            }
        };
    }

    private static function catalogContext(): ToolBuildContext
    {
        $skills = new SkillRegistry();

        return new ToolBuildContext(
            root: sys_get_temp_dir(),
            loader: new InstructionFileLoader(sys_get_temp_dir()),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
        );
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid((string) getmypid(), true);
        mkdir($dir, 0700);
        $this->dirs[] = $dir;

        return $dir;
    }

    private static function wipeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::wipeDir($path) : unlink($path);
        }
        @rmdir($dir);
    }
}
