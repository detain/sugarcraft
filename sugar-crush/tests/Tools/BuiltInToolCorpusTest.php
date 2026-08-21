<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\Tool;

/**
 * The scanner three test files trust, tested itself.
 *
 * {@see BuiltInToolCorpus} is not collected by PHPUnit (no `Test` suffix) and had
 * no tests of its own, so both of its defects were invisible to the suite that
 * depends on it: it saw one flat directory, and its interface/trait guard sat
 * behind a `class_exists()` that throws first.
 *
 * TWO KINDS OF TEST HERE, kept apart on purpose:
 *  - against the REAL `src/` — the figures the corpus doc-block quotes, derived
 *    so they cannot drift from the tree they describe;
 *  - against a SYNTHETIC tree under `sys_get_temp_dir()`, for every escape and
 *    edge shape. Probe files are NOT written into `src/`: two other suites read
 *    that tree concurrently, and a probe that fatals leaves residue behind in it.
 */
final class BuiltInToolCorpusTest extends TestCase
{
    private const PROBE_PREFIX = 'CorpusProbe\\';

    private string $srcDir;

    /** The synthetic `src/` the shape tests scan. */
    private string $probeDir;

    /** @var callable|null */
    private $probeAutoloader = null;

    protected function setUp(): void
    {
        $this->srcDir = \dirname(__DIR__, 2) . '/src';
        $this->probeDir = sys_get_temp_dir() . '/builtin_tool_corpus_probe_' . uniqid();
        mkdir($this->probeDir, 0o777, true);

        // The scanner resolves a file to a class name and asks PHP whether that
        // symbol exists, so a synthetic tree needs its own autoloader or every
        // probe would read as "declares no symbol".
        $this->probeAutoloader = function (string $class): void {
            if (!str_starts_with($class, self::PROBE_PREFIX)) {
                return;
            }

            $file = $this->probeDir . '/'
                . str_replace('\\', '/', substr($class, \strlen(self::PROBE_PREFIX))) . '.php';

            // require_ONCE, and it is load-bearing: the mis-namespaced probe
            // declares a symbol OTHER than the one asked for, so `class_exists()`
            // comes back false and the scanner then asks `interface_exists()` and
            // `trait_exists()` for the same name — three autoload attempts on one
            // file, which a plain `require` turns into a redeclaration fatal.
            if (is_file($file)) {
                require_once $file;
            }
        };
        spl_autoload_register($this->probeAutoloader);
    }

    protected function tearDown(): void
    {
        if ($this->probeAutoloader !== null) {
            spl_autoload_unregister($this->probeAutoloader);
        }

        $this->removeTree($this->probeDir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function writeProbe(string $relative, string $code): void
    {
        $path = $this->probeDir . '/' . $relative;
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $code);
    }

    /** @return list<string> */
    private function scanProbe(): array
    {
        return BuiltInToolCorpus::classNames($this->probeDir, self::PROBE_PREFIX);
    }

    /**
     * A dispatchable tool, so the synthetic tree is never empty — `classNames()`
     * throws on an empty result, which is a contract of its own and would
     * otherwise mask every assertion below.
     */
    private function writeOneRealTool(): void
    {
        $this->writeProbe('Tools/BuiltIn/Anchor.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Anchor implements Tool
            {
                public function name(): string
                {
                    return 'anchor';
                }

                public function description(): string
                {
                    return 'anchor';
                }

                public function inputSchema(): array
                {
                    return [];
                }

                public function execute(array $args): ToolResult
                {
                    return ToolResult::error('anchor');
                }
            }
            PHP);
    }

    // ─── against the real src/ ──────────────────────────────────────

    /**
     * What the widening from `src/Tools/BuiltIn/*.php` to all of `src/` actually
     * finds, DERIVED on both sides.
     *
     * THIS TEST USED TO ASSERT THE TWO SETS WERE EQUAL, on the premise that the
     * widening was purely latent insurance. That premise expired: `src/Tools/`
     * now holds {@see \SugarCraft\Crush\Tools\McpToolBridge}, the adapter that
     * makes a project's MCP tools dispatchable, and under the OLD flat glob it
     * would have been invisible to all four corpus consumers — the exact defect
     * the widening exists for, landing for real one round after being called
     * hypothetical.
     *
     * So the assertion is now the DIFFERENCE, and it is pinned in both
     * directions: the wide sweep must be the flat glob plus exactly
     * {@see BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES}, so neither an unrecorded
     * tool outside the wired directory nor a recorded one that has quietly
     * MOVED into it can pass. A plain `assertGreaterThan` or a one-sided
     * `assertContains` here would let both through.
     */
    public function testTheWideSweepFindsTheFlatGlobPlusExactlyTheRecordedDynamicTools(): void
    {
        $flat = [];
        foreach ((array) glob($this->srcDir . '/Tools/BuiltIn/*.php') as $file) {
            $class = 'SugarCraft\\Crush\\Tools\\BuiltIn\\' . basename((string) $file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if (!$reflection->isAbstract() && $reflection->implementsInterface(Tool::class)) {
                $flat[] = $class;
            }
        }
        sort($flat);

        $expected = [...$flat, ...BuiltInToolCorpus::dynamicToolClasses()];
        sort($expected);

        $this->assertSame($expected, BuiltInToolCorpus::classNames());
        $this->assertCount(11, $flat, 'eleven wired built-in tools on this tree');

        // And the recorded exemptions must genuinely live OUTSIDE the wired
        // directory: an entry that has moved into `src/Tools/BuiltIn/` would be
        // counted twice above and is a stale exemption either way.
        foreach (BuiltInToolCorpus::dynamicToolClasses() as $dynamic) {
            $this->assertNotContains(
                $dynamic,
                $flat,
                "{$dynamic} is in the wired directory, so it must be listed in Bootstrap::tools() rather than exempted",
            );
        }
    }

    /**
     * THE EXEMPTION'S OWN DISCIPLINE, WHICH WAS PROSE. The constant says "each
     * entry needs its own end-to-end reachability test naming it", and that
     * sentence was asserted nowhere. MEASURED on the flat-list version: adding one
     * line for a `Tool` that `Bootstrap::tools()` does not wire and `Runtime`
     * cannot dispatch made it pass
     * {@see testTheWideSweepFindsTheFlatGlobPlusExactlyTheRecordedDynamicTools()},
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()},
     * `BuiltInToolTest` and `ToolSchemaEncodingTest` — every consumer green, an
     * exemption that granted itself. The ACCIDENTAL case was already safe (an
     * unwired tool that is not listed reds two of those and names it); this is the
     * deliberate one-line case.
     *
     * So the entry must NAME the test method that proves reachability, and the
     * method must resolve. Reflection rather than `method_exists()` because a
     * PHPUnit test method must also be public and non-static to run at all, and an
     * entry pointing at a private helper would otherwise satisfy the guard while
     * proving nothing.
     *
     * WHAT THIS CANNOT DO, so it is not read as more than it is: it cannot tell
     * that the named test actually drives the named class. It closes the
     * write-one-line-and-pass hole, and the named test is then a thing a reviewer
     * can open.
     */
    public function testEveryDynamicToolExemptionNamesAReachabilityTestThatExists(): void
    {
        $this->assertNotSame([], BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES, 'the map may not be empty while a tool is exempt');

        foreach (BuiltInToolCorpus::DYNAMIC_TOOL_CLASSES as $exempt => $evidence) {
            $this->assertStringContainsString('::', $evidence, "{$exempt}'s evidence must be Class::method");
            [$class, $method] = explode('::', $evidence, 2);

            $this->assertTrue(
                class_exists($class),
                "{$exempt} names reachability test class {$class}, which does not exist",
            );
            $this->assertTrue(
                (new \ReflectionClass($class))->hasMethod($method),
                "{$exempt} names reachability test {$class}::{$method}, which does not exist",
            );

            $reflected = new \ReflectionMethod($class, $method);
            $this->assertTrue($reflected->isPublic(), "{$evidence} is not public, so PHPUnit never runs it");
            $this->assertFalse($reflected->isStatic(), "{$evidence} is static, so it is not a test method");
            $this->assertStringStartsWith('test', $method, "{$evidence} is not a test method");
        }
    }

    /**
     * The doc-block's symbol-kind census, derived. The load-bearing number is the
     * LAST one: `abstract` is the only shape the old `class_exists()`-only guard
     * classified correctly, and there are none — while the 17 interfaces and 6
     * traits it would have thrown on are already here.
     *
     * DOMAIN: one symbol per FILE — the PSR-4-named one. These 285 files declare
     * 304 top-level types, so this is not a census of the tree's types and never
     * was; see {@see testTheSecondaryDeclarationCensus()} for the other 19 and
     * for the blind spot that equating the two produced.
     *
     * The most recent file is `Context/RepoMapBlock` — the `<repo-map>` system
     * prompt block that maps a workspace's Composer sub-packages and PSR-4
     * source directories (crush_code.md P8.8). One `final readonly class` and
     * nothing else, so this bump is +1 on the file count and +1 on `concrete`,
     * with every other kind and the whole per-file map below unmoved; its 19
     * SECONDARY declarations in 8 files are untouched by it.
     *
     * Before it, THREE files arrived at once from three lanes that landed in
     * the same window, for a +3 rather than the usual +1:
     * `Tui/Components/AgentSplitColumn` — the live-agent pane the split-pane
     * compositor lays beside the shell band (crush_code.md Phase 8 item 4);
     * `Cli/HeadlessPermissionPrompt`, the console approver the `-p` one-shot
     * path and the background-session daemon attach to the engine, which is the
     * first caller
     * {@see \SugarCraft\Crush\Backend\EngineBackend::withPermissionApprover()}
     * has had in `src/`; and `Commands/TranscriptTable`, the shared column
     * layout `AgentsCommand` and `McpAuthCommand` moved onto when their
     * hand-built `strlen()` columns became a candy-sprinkles `Table`
     * (crush_code.md P3.4). Each was a single concrete class too. The file
     * before that trio was `Providers/ToolCallParser/MarkupScanner`.
     *
     * THE PREVIOUS BUMP NAMED THE WRONG CAUSE. This paragraph attributed the
     * last +1 to `Config/LayeredSettings` after the numbers had already been
     * moved by a different file, so the prose and the literal disagreed about
     * what changed. Re-deriving a census is cheap; re-deriving it from a
     * sentence that names the wrong file is how the next reader gets the next
     * bump wrong. Ordinals are deliberately not quoted
     * here: the walk below is `RecursiveDirectoryIterator` order, so "the Nth
     * file" is a fact about the filesystem, not about the tree.
     */
    public function testTheSymbolKindCensusTheDocBlockQuotes(): void
    {
        $counts = ['concrete' => 0, 'enum' => 0, 'abstract' => 0, 'interface' => 0, 'trait' => 0, 'none' => 0];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        $files = 0;
        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$files;

            $relative = substr($file->getPathname(), \strlen($this->srcDir) + 1);
            $class = 'SugarCraft\\Crush\\' . str_replace('/', '\\', substr($relative, 0, -4));

            if (class_exists($class)) {
                $reflection = new \ReflectionClass($class);
                $counts[$reflection->isEnum() ? 'enum' : ($reflection->isAbstract() ? 'abstract' : 'concrete')]++;
            } elseif (interface_exists($class)) {
                ++$counts['interface'];
            } elseif (trait_exists($class)) {
                ++$counts['trait'];
            } else {
                ++$counts['none'];
            }
        }

        $this->assertSame(285, $files, 'php files under src/');
        $this->assertSame(
            ['concrete' => 236, 'enum' => 26, 'abstract' => 0, 'interface' => 17, 'trait' => 6, 'none' => 0],
            $counts,
        );
    }

    /**
     * Pinned at zero on this tree. Derived, so the figure cannot drift; and it is
     * ONE test rather than a throw inside corpus construction, which is the whole
     * difference between a PSR-4 exemption arriving and the suite refusing to
     * enumerate.
     */
    public function testEverySourceFileDeclaresItsPsr4Symbol(): void
    {
        $exempt = BuiltInToolCorpus::nonClassSources();

        $this->assertSame(
            [],
            $exempt,
            'PSR-4-exempt src/ files (skipped by the corpus scan): ' . implode(', ', $exempt),
        );
    }

    /**
     * THE CENSUS THE SCAN USED TO EQUATE WITH FILES. `classNames()` derived one
     * class per FILENAME, so "the SOURCE TREE is the corpus … a new tool class is
     * in every one of these tests the moment it exists" held only for
     * one-type-per-file — and `src/` ships nineteen counterexamples.
     *
     * Derived with `token_get_all()` rather than `class_exists()`, because the
     * secondary symbols are not autoloadable by their own names: 285 `.php`
     * files, 304 top-level declarations, 19 of them secondary in 8 files. Pinned
     * per file, so a second declaration arriving in a scanned file reds THIS test
     * with the file named rather than silently widening the blind spot.
     *
     * `src/ToolRegistry.php` declaring `SugarCraft\Crush\Tool` is reported rather
     * than moved: `src/ToolRegistry.php` is outside this change-set's ownership.
     * It is one `use` away from colliding with `SugarCraft\Crush\Tools\Tool`, and
     * `tests/ToolRegistryTest.php` already imports it.
     *
     * The two file/declaration figures above are measured (`sourceFiles()` and
     * `declaredTypes()` over `src/`), not restated: they read 267/286 before
     * crush_code.md Phase 5 items 4/5 added three files, which was already one
     * low against {@see testTheSymbolKindCensusTheDocBlockQuotes()}'s own count
     * of the same tree. Nothing asserted them, which is how they drifted — so
     * both are asserted below now, and both moved again by +2 when Phase 5
     * items 8/9 added `Providers/TransientFailure` and `Context/MemoryBlock`. The declaration count in particular was quoted
     * in BOTH census docblocks and enforced by neither, so adding a file redded
     * only the sibling test's FILE count and left the declaration count to rot.
     * The figures in that sentence are deliberately named rather than numbered:
     * the numbers move with the tree, and writing today's beside a past-tense
     * narrative about yesterday's is the drift this paragraph is describing.
     */
    public function testTheSecondaryDeclarationCensus(): void
    {
        $secondary = [];
        foreach (BuiltInToolCorpus::sourceFiles($this->srcDir) as $relative) {
            $primary = 'SugarCraft\\Crush\\' . str_replace('/', '\\', substr($relative, 0, -4));
            $extra = array_values(array_diff(
                BuiltInToolCorpus::declaredTypes($this->srcDir . '/' . $relative),
                [$primary],
            ));

            if ($extra !== []) {
                $secondary[$relative] = $extra;
            }
        }

        $this->assertSame(
            [
                'App/App.php' => 11,
                'Cli/ArgvParser.php' => 1,
                'CommandParser.php' => 1,
                'Compactor.php' => 1,
                'MCP/McpAuthStore.php' => 1,
                'MCP/OAuthClientRegistration.php' => 1,
                'ToolRegistry.php' => 2,
                'Tui/StallDetector.php' => 1,
            ],
            array_map('count', $secondary),
        );

        $this->assertSame(19, array_sum(array_map('count', $secondary)));
        $this->assertContains('SugarCraft\\Crush\\Tool', $secondary['ToolRegistry.php']);

        // The two figures the docblock quotes, derived here rather than trusted.
        $files = BuiltInToolCorpus::sourceFiles($this->srcDir);
        $declarations = 0;
        foreach ($files as $relative) {
            $declarations += count(BuiltInToolCorpus::declaredTypes($this->srcDir . '/' . $relative));
        }
        $this->assertSame(285, count($files), 'php files under src/');
        $this->assertSame(304, $declarations, 'top-level declarations in them');
        $this->assertSame(
            $declarations - count($files),
            array_sum(array_map('count', $secondary)),
            'and the two figures must stay consistent with the per-file census above',
        );

        // `src/Context/RepoMapBlock` argues about the size of a per-class
        // listing and about how far under MAX_SOURCE_FILES a normal package
        // sits, so it restates BOTH figures — and it shipped restating 284/303
        // in the very commit that moved them to 285/304 thirty lines away in
        // its own message. A restated census no test asserts is this file's
        // recurring defect; the restatement is asserted here rather than
        // deleted, because the argument it supports needs the number.
        $block = (string) file_get_contents($this->srcDir . '/Context/RepoMapBlock.php');

        $this->assertStringContainsString(
            sprintf('`src/` here declares %d top-level', $declarations),
            $block,
            'RepoMapBlock restates the declaration census and has drifted from it',
        );
        $this->assertStringContainsString(
            sprintf('`src/` here is %d files', count($files)),
            $block,
            'RepoMapBlock restates the file census and has drifted from it',
        );
    }

    /**
     * THE INVARIANT, which is the load-bearing half: whatever the census says,
     * no secondary declaration may be a dispatchable tool. This is the assertion
     * that would have caught the blind spot without anybody having to notice the
     * census first, and it stays correct when the census legitimately changes.
     */
    public function testNoSecondaryDeclarationIsADispatchableTool(): void
    {
        $offenders = [];
        foreach (BuiltInToolCorpus::sourceFiles($this->srcDir) as $relative) {
            $primary = 'SugarCraft\\Crush\\' . str_replace('/', '\\', substr($relative, 0, -4));

            foreach (BuiltInToolCorpus::declaredTypes($this->srcDir . '/' . $relative) as $declared) {
                if ($declared === $primary || !class_exists($declared)) {
                    continue;
                }

                $reflection = new \ReflectionClass($declared);
                if (!$reflection->isAbstract() && $reflection->implementsInterface(Tool::class)) {
                    $offenders[] = "{$declared} (src/{$relative})";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'a Tool implementor declared as a secondary symbol is dispatchable by nothing: '
            . implode(', ', $offenders),
        );
    }

    /**
     * The scanner half of the same fix, on a synthetic tree: a `Tool`
     * implementor declared as a SECOND type in a file is now in the corpus. Under
     * the filename-derived scan it was invisible, and — this is what made it
     * dangerous rather than merely incomplete — `nonClassSources()` still
     * returned `[]`, because the file's primary symbol does exist.
     */
    public function testAToolImplementorDeclaredAsASecondarySymbolIsSeen(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Support/Bundle.php', <<<'PHP'
            namespace CorpusProbe\Support;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Bundle
            {
            }

            final class HiddenTool implements Tool
            {
                public function name(): string
                {
                    return 'hidden';
                }

                public function description(): string
                {
                    return 'hidden';
                }

                public function inputSchema(): array
                {
                    return [];
                }

                public function execute(array $args): ToolResult
                {
                    return ToolResult::error('hidden');
                }
            }
            PHP);

        $this->assertSame(
            ['CorpusProbe\\Support\\HiddenTool', 'CorpusProbe\\Tools\\BuiltIn\\Anchor'],
            $this->scanProbe(),
        );

        // The silence that made it dangerous, pinned: nothing was exempt.
        $this->assertSame([], BuiltInToolCorpus::nonClassSources($this->probeDir, self::PROBE_PREFIX));
    }

    /**
     * The declaration scanner's own edges, so a token walk that quietly stopped
     * matching would not make every census above read as zero-drift.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function declarationShapes(): array
    {
        return [
            'two top-level classes' => [
                "<?php\nnamespace N;\nfinal class A {}\nfinal class B {}\n",
                ['N\\A', 'N\\B'],
            ],
            'every kind' => [
                "<?php\nnamespace N;\nclass A {}\ninterface B {}\ntrait C {}\nenum D {}\n",
                ['N\\A', 'N\\B', 'N\\C', 'N\\D'],
            ],
            'a nested class is not top-level' => [
                "<?php\nnamespace N;\nfinal class A { public function f() { return new class {}; } }\n",
                ['N\\A'],
            ],
            '::class is not a declaration' => [
                "<?php\nnamespace N;\nfinal class A { public function f(): string { return \\stdClass::class; } }\n",
                ['N\\A'],
            ],
            'no namespace' => ["<?php\nfinal class A {}\n", ['A']],
            'a braced string does not unbalance the walk' => [
                "<?php\nnamespace N;\nfinal class A { public function f(\$b) { return \"{\$b}/x\"; } }\nfinal class B {}\n",
                ['N\\A', 'N\\B'],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('declarationShapes')]
    public function testTheDeclarationScannerSeesExactlyTheTopLevelTypes(string $code, array $expected): void
    {
        $path = $this->probeDir . '/decl_' . md5($code) . '.php';
        file_put_contents($path, $code);

        $this->assertSame($expected, BuiltInToolCorpus::declaredTypes($path));
    }

    public function testEveryCorpusInstanceIsATool(): void
    {
        $instances = BuiltInToolCorpus::instances();

        $this->assertCount(\count(BuiltInToolCorpus::classNames()), $instances);
        foreach ($instances as $tool) {
            $this->assertInstanceOf(Tool::class, $tool);
        }
    }

    // ─── constructibility ───────────────────────────────────────────

    /**
     * THE ENUM CLAUSE'S SIBLING, and it is this repo's own MANDATED class shape:
     * `final` + `private function __construct()` + a `::new()` factory, which
     * `CLAUDE.md` and `.claude/rules/model-pattern.md` both require.
     *
     * `getNumberOfRequiredParameters() === 0` is true of a zero-argument PRIVATE
     * constructor, so the `match` fallback never fired and `new $class()` ran
     * anyway. Measured before the fix:
     *
     *     Error: Call to private CorpusProbe\Tools\BuiltIn\Factory::__construct()
     *     tests/Tools/BuiltInToolTest.php            -> Tests: 80, Errors: 33
     *     tests/Providers/ToolSchemaEncodingTest.php -> abort inside TestSuiteBuilder->build()
     *
     * The tool is DISPATCHABLE — `::new()` builds it — so the fix is to build it
     * through the factory, not to drop it from the corpus.
     */
    public function testAToolWrittenToTheMandatedPrivateConstructorShapeIsBuiltThroughItsFactory(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Factory.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Factory implements Tool
            {
                private function __construct() {}

                public static function new(): self
                {
                    return new self();
                }

                public function name(): string { return 'factory'; }
                public function description(): string { return 'factory'; }
                public function inputSchema(): array { return []; }
                public function execute(array $args): ToolResult { return ToolResult::error('factory'); }
            }
            PHP);

        $this->assertSame(
            ['CorpusProbe\\Tools\\BuiltIn\\Anchor', 'CorpusProbe\\Tools\\BuiltIn\\Factory'],
            $this->scanProbe(),
        );

        $instances = BuiltInToolCorpus::instances($this->probeDir, self::PROBE_PREFIX);

        $this->assertCount(2, $instances);
        $this->assertSame(
            ['anchor', 'factory'],
            array_map(static fn (Tool $t): string => $t->name(), $instances),
        );
    }

    /**
     * THE SILENT DROP, INVERTED. This test asserted that a private constructor
     * with no factory left the class OUT of the corpus — "not a dispatchable tool
     * and left out rather than fatalled on" — and that is exactly how a would-be
     * built-in tool disappeared from every built-in-tool test with no exception
     * and no diagnostic. {@see BuiltInToolCorpus::instances()}'s own throw says
     * "rather than silently skipping it", and the filter above it made that
     * unreachable for the one shape `CLAUDE.md` mandates writing.
     *
     * So the class is now IN the corpus and building it is a named failure. Two
     * assertions, because the pair is the finding: it is seen, and it is loud.
     */
    public function testAToolWithAPrivateConstructorAndNoFactoryFailsLoudlyRatherThanVanishing(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Sealed.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Sealed implements Tool
            {
                private function __construct() {}

                public function name(): string { return 'sealed'; }
                public function description(): string { return 'sealed'; }
                public function inputSchema(): array { return []; }
                public function execute(array $args): ToolResult { return ToolResult::error('sealed'); }
            }
            PHP);

        $this->assertSame(
            ['CorpusProbe\\Tools\\BuiltIn\\Anchor', 'CorpusProbe\\Tools\\BuiltIn\\Sealed'],
            $this->scanProbe(),
            'an unbuildable Tool implementor must be SEEN, not filtered away',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CorpusProbe\\Tools\\BuiltIn\\Sealed implements Tool but cannot be built');

        BuiltInToolCorpus::instances($this->probeDir, self::PROBE_PREFIX);
    }

    /**
     * THE SECOND DROPPED SHAPE, and it is a one-keyword authoring slip rather than
     * an exotic case: `public function new()` instead of `public static function
     * new()`. {@see BuiltInToolCorpus::zeroArgumentFactory()} requires `isStatic()`
     * — correctly, since `instances()` invokes it with no object — so the class
     * used to be dropped for a reason nobody was told.
     */
    public function testANonStaticNewIsNotAFactoryAndFailsLoudly(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Instanced.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Instanced implements Tool
            {
                private function __construct() {}

                public function new(): self
                {
                    return $this;
                }

                public function name(): string { return 'instanced'; }
                public function description(): string { return 'instanced'; }
                public function inputSchema(): array { return []; }
                public function execute(array $args): ToolResult { return ToolResult::error('instanced'); }
            }
            PHP);

        $this->assertContains('CorpusProbe\\Tools\\BuiltIn\\Instanced', $this->scanProbe());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CorpusProbe\\Tools\\BuiltIn\\Instanced implements Tool but cannot be built');

        BuiltInToolCorpus::instances($this->probeDir, self::PROBE_PREFIX);
    }

    /**
     * THE RESIDUAL THE MOVE LEFT, closed in the same change: reflection can see
     * that `::new()` is public, static and zero-argument, and cannot see what it
     * RETURNS. `instances()` used to append whatever came back.
     *
     * A factory returning a builder or `null` therefore entered a `list<Tool>` and
     * failed inside whichever consumer first called a `Tool` method on it — a
     * defect reported nowhere near its cause.
     */
    public function testAFactoryThatDoesNotReturnATheToolIsRefusedAtTheCorpus(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/WrongReturn.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class WrongReturn implements Tool
            {
                private function __construct() {}

                public static function new(): ?self
                {
                    return null;
                }

                public function name(): string { return 'wrongreturn'; }
                public function description(): string { return 'wrongreturn'; }
                public function inputSchema(): array { return []; }
                public function execute(array $args): ToolResult { return ToolResult::error('wrongreturn'); }
            }
            PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WrongReturn::new() returned null rather than a Tool');

        BuiltInToolCorpus::instances($this->probeDir, self::PROBE_PREFIX);
    }

    /**
     * A `new()` that itself needs arguments is not a zero-argument factory, so
     * the class is not constructible standalone — the guard must not be satisfied
     * by the METHOD NAME alone.
     *
     * ASSERTED ON THE THROW rather than on the class's absence, for the reason the
     * two tests above are: this shape is the ordinary consequence of a factory
     * growing a dependency, and its old answer was to vanish from the corpus.
     */
    public function testAFactoryThatNeedsArgumentsIsNotAFactoryAndFailsLoudly(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/NeedsArgs.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class NeedsArgs implements Tool
            {
                private function __construct(private readonly string $root) {}

                public static function new(string $root): self
                {
                    return new self($root);
                }

                public function name(): string { return 'needsargs'; }
                public function description(): string { return 'needsargs'; }
                public function inputSchema(): array { return []; }
                public function execute(array $args): ToolResult { return ToolResult::error('needsargs'); }
            }
            PHP);

        $this->assertContains('CorpusProbe\\Tools\\BuiltIn\\NeedsArgs', $this->scanProbe());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CorpusProbe\\Tools\\BuiltIn\\NeedsArgs implements Tool but cannot be built');

        BuiltInToolCorpus::instances($this->probeDir, self::PROBE_PREFIX);
    }

    /**
     * THE SHAPE REFLECTION CANNOT SEE COMING: a public zero-argument constructor
     * that THROWS. It produced the same uncatchable abort inside
     * `TestSuiteBuilder->build()` as the private one, and the only thing that can
     * be fixed about it is the legibility of the failure. Asserted on the
     * MESSAGE, because "it still throws" is the design and "it names the class
     * and says what to do" is the change.
     */
    public function testAConstructorThatThrowsFailsWithANamedMessageRatherThanAnOpaqueAbort(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Exploding.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Exploding implements Tool
            {
                public function __construct()
                {
                    throw new \RuntimeException('needs a live connection');
                }

                public function name(): string { return 'exploding'; }
                public function description(): string { return 'exploding'; }
                public function inputSchema(): array { return []; }
                public function execute(array $args): ToolResult { return ToolResult::error('exploding'); }
            }
            PHP);

        // Reflection sees a public zero-arg constructor, so it IS in the corpus.
        $this->assertContains('CorpusProbe\\Tools\\BuiltIn\\Exploding', $this->scanProbe());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CorpusProbe\\Tools\\BuiltIn\\Exploding could not be constructed standalone');

        BuiltInToolCorpus::instances($this->probeDir, self::PROBE_PREFIX);
    }

    /**
     * The pre-existing contract, re-driven now that a `catch (\Throwable)` sits
     * around it: a tool with REQUIRED constructor arguments must still produce
     * the "add it to instances()" message and not be swallowed into the generic
     * one.
     */
    public function testAToolWithRequiredConstructorArgumentsStillNamesTheFixture(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Wired.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Wired implements Tool
            {
                public function __construct(private readonly string $root) {}

                public function name(): string { return 'wired'; }
                public function description(): string { return 'wired'; }
                public function inputSchema(): array { return []; }
                public function execute(array $args): ToolResult { return ToolResult::error('wired'); }
            }
            PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs constructor arguments');

        BuiltInToolCorpus::instances($this->probeDir, self::PROBE_PREFIX);
    }

    // ─── against the synthetic tree ─────────────────────────────────

    /**
     * Finding #90(a), driven with the shape plan step #17 will create: a `Tool`
     * implementor OUTSIDE the wired tool directory. The flat glob returned the
     * anchor alone; the sweep returns both.
     */
    public function testAToolImplementorOutsideTheWiredDirectoryIsSeen(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('LSP/LspTool.php', <<<'PHP'
            namespace CorpusProbe\LSP;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class LspTool implements Tool
            {
                public function name(): string
                {
                    return 'lsp';
                }

                public function description(): string
                {
                    return 'lsp';
                }

                public function inputSchema(): array
                {
                    return [];
                }

                public function execute(array $args): ToolResult
                {
                    return ToolResult::error('lsp');
                }
            }
            PHP);

        $this->assertSame(
            ['CorpusProbe\\LSP\\LspTool', 'CorpusProbe\\Tools\\BuiltIn\\Anchor'],
            $this->scanProbe(),
        );
    }

    /**
     * Finding #90(b): the guard's comment claimed three shapes and covered one. An
     * INTERFACE in the wired tool directory used to make `classNames()` throw
     * inside corpus CONSTRUCTION, which took `phpunit --list-tests` down with it —
     * the suite could not enumerate, let alone run.
     */
    public function testAnInterfaceInTheWiredToolDirectoryIsSkippedNotThrownOn(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/NotifierInterface.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            interface NotifierInterface
            {
                public function notify(string $message): void;
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /**
     * The nastiest of the three shapes, because it satisfies HALF the filter:
     * `implementsInterface(Tool::class)` is TRUE for an interface that extends
     * `Tool`. What keeps it out is the other half — measured on PHP 8.3.6, an
     * interface with at least one method (own OR inherited) reports
     * `isAbstract() === true`, so `interface ProgressTool extends Tool` is
     * abstract and rejected. Were it to get through,
     * {@see BuiltInToolCorpus::instances()} would fatal trying to `new` it.
     *
     * WHAT THIS TEST DOES NOT SHOW, stated because an earlier draft of this
     * doc-block claimed the opposite: it does not demonstrate a need for a
     * separate "not a class" clause. Measured, no such clause is reachable —
     * every interface and trait is caught by the two filters — which is why
     * {@see BuiltInToolCorpus::classNames()} does not carry one.
     */
    public function testAnInterfaceExtendingToolDoesNotEnterTheCorpus(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/ProgressTool.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;

            interface ProgressTool extends Tool
            {
                public function progress(): int;
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    public function testATraitInTheWiredToolDirectoryIsSkippedNotThrownOn(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Notifies.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            trait Notifies
            {
                public function notify(string $message): void
                {
                }
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /**
     * The one shape the OLD guard already handled, kept handled: an abstract base
     * reaches the filter and is rejected there. There are none in `src/` today —
     * see {@see testTheSymbolKindCensusTheDocBlockQuotes()} — which is exactly why
     * the two shapes above went unnoticed.
     */
    public function testAnAbstractToolBaseIsSkipped(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/AbstractTool.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;

            abstract class AbstractTool implements Tool
            {
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /**
     * THE ONE DANGEROUS SHAPE, and the one that was untested. The guard's
     * doc-block asserted that enums "are rejected here on the interface clause".
     * MEASURED on PHP 8.3.6 for `enum ModeTool: string implements Tool`:
     *
     *     class_exists = true   isAbstract = false   implementsInterface(Tool) = true
     *
     * — BOTH clauses passed. It entered the corpus, and
     * {@see BuiltInToolCorpus::instances()} then raised
     * `Error: Cannot instantiate enum …` inside data-provider construction: an
     * uncatchable abort of the whole suite's enumeration, which is verbatim the
     * failure mode this class was refactored to remove.
     *
     * {@see testAnEnumIsSkipped()} probed a PLAIN enum, which the interface
     * clause rejects for the same reason it rejects any unrelated class — so the
     * test that looked like coverage of this was coverage of something else.
     */
    public function testAnEnumImplementingToolNeitherEntersTheCorpusNorFatals(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/ModeTool.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            enum ModeTool: string implements Tool
            {
                case Fast = 'fast';

                public function name(): string
                {
                    return 'mode';
                }

                public function description(): string
                {
                    return 'mode';
                }

                public function inputSchema(): array
                {
                    return [];
                }

                public function execute(array $args): ToolResult
                {
                    return ToolResult::error('mode');
                }
            }
            PHP);

        $names = $this->scanProbe();

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $names);

        // The consequence, driven rather than argued: every name the corpus
        // returns must be `new`-able, because instances() does exactly that.
        foreach ($names as $class) {
            $this->assertFalse((new \ReflectionClass($class))->isEnum());
        }
    }

    /** An enum in the tree is neither a tool nor a reason to abort. */
    public function testAnEnumIsSkipped(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Mode.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            enum Mode: string
            {
                case Fast = 'fast';
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /** A plain class that is not a Tool is not a tool. */
    public function testANonToolClassIsSkipped(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Support/Helper.php', <<<'PHP'
            namespace CorpusProbe\Support;

            final class Helper
            {
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /**
     * The strictness that is KEPT, and kept narrow: inside the wired tool
     * directory a file whose namespace disagrees with its path is still a throw,
     * because a tool nobody can autoload is a tool nobody can dispatch.
     */
    public function testAMisnamespacedFileInTheWiredToolDirectoryStillThrows(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Misnamespaced.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn\Elsewhere;

            final class Misnamespaced
            {
            }
            PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/namespace and directory must agree/');

        $this->scanProbe();
    }

    /**
     * The other half of that decision: OUTSIDE the wired directory the same file
     * is skipped rather than thrown on, so a PSR-4-exempt file arriving cannot
     * abort suite construction — and the skip is REPORTED rather than swallowed,
     * which is what stops the fix being a new blindness.
     */
    public function testAnExemptFileElsewhereIsReportedRatherThanThrownOn(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Support/functions.php', "namespace CorpusProbe\\Support;\n\nreturn 1;\n");

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
        $this->assertSame(
            ['Support/functions.php'],
            BuiltInToolCorpus::nonClassSources($this->probeDir, self::PROBE_PREFIX),
        );
    }

    /**
     * TWO EMPTINESSES, and telling them apart is what stops
     * {@see BuiltInToolCorpus::nonClassSources()} passing vacuously. An empty
     * DIRECTORY means nothing was scanned; a scanned tree with no tools in it
     * means the corpus is empty. The first used to be indistinguishable from the
     * second here, and indistinguishable from success in `nonClassSources()`.
     */
    public function testAnExistingButEmptyTreeThrowsBecauseNothingWasScanned(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No PHP sources found/');

        $this->scanProbe();
    }

    public function testATreeWithSourcesButNoToolsThrowsRatherThanHandingBackAnEmptyCorpus(): void
    {
        $this->writeProbe('Support/Helper.php', "namespace CorpusProbe\\Support;\n\nfinal class Helper\n{\n}\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No built-in tool sources found/');

        $this->scanProbe();
    }

    /**
     * The other half of the same guard, and the reason it exists: pointed at an
     * existing-but-empty root, `nonClassSources()` used to return `[]` and make
     * `assertSame([], $exempt)` pass having scanned NOTHING. "0 of 267" was never
     * asserted to have looked at 267 files.
     */
    public function testNonClassSourcesRefusesToReportZeroOutOfNothing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No PHP sources found/');

        BuiltInToolCorpus::nonClassSources($this->probeDir, self::PROBE_PREFIX);
    }

    /** Deterministic order is a contract three providers name their cases from. */
    public function testTheCorpusIsSortedRegardlessOfDirectoryOrder(): void
    {
        $this->writeOneRealTool();
        foreach (['Zebra', 'Alpha', 'Middle'] as $name) {
            $this->writeProbe("Tools/BuiltIn/{$name}.php", <<<PHP
                namespace CorpusProbe\\Tools\\BuiltIn;

                use SugarCraft\\Crush\\Tools\\Tool;
                use SugarCraft\\Crush\\Tools\\ToolResult;

                final class {$name} implements Tool
                {
                    public function name(): string
                    {
                        return '{$name}';
                    }

                    public function description(): string
                    {
                        return '{$name}';
                    }

                    public function inputSchema(): array
                    {
                        return [];
                    }

                    public function execute(array \$args): ToolResult
                    {
                        return ToolResult::error('{$name}');
                    }
                }
                PHP);
        }

        $names = $this->scanProbe();

        $this->assertSame($names, array_values($names));
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $this->assertCount(4, $names);
    }

    /**
     * The one input on which sorting FILE PATHS and sorting CLASS NAMES disagree,
     * so `classNames()`'s own `sort()` is pinned rather than shadowed by the sort
     * `sourceFiles()` already does. `/` is 0x2F and `\` is 0x5C, so any character
     * between them — a digit, an uppercase letter — inverts the two orders:
     *
     *     path order : Tools/A/B.php, Tools/A0.php
     *     class order: …\Tools\A0,    …\Tools\A\B
     *
     * Three providers name their cases off this list, so the order is a contract
     * and not a nicety.
     */
    public function testTheSortIsOnClassNamesNotOnFilePaths(): void
    {
        foreach (['A/B' => 'CorpusProbe\\Tools\\A', 'A0' => 'CorpusProbe\\Tools'] as $relative => $namespace) {
            $short = basename($relative);
            $this->writeProbe("Tools/{$relative}.php", <<<PHP
                namespace {$namespace};

                use SugarCraft\\Crush\\Tools\\Tool;
                use SugarCraft\\Crush\\Tools\\ToolResult;

                final class {$short} implements Tool
                {
                    public function name(): string
                    {
                        return '{$short}';
                    }

                    public function description(): string
                    {
                        return '{$short}';
                    }

                    public function inputSchema(): array
                    {
                        return [];
                    }

                    public function execute(array \$args): ToolResult
                    {
                        return ToolResult::error('{$short}');
                    }
                }
                PHP);
        }

        $this->assertSame(
            ['CorpusProbe\\Tools\\A0', 'CorpusProbe\\Tools\\A\\B'],
            $this->scanProbe(),
        );
    }
}
