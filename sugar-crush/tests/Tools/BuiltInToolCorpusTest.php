<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\RepoMapBlock;
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
        $this->probeDir = sys_get_temp_dir() . '/builtin_tool_corpus_probe_' . uniqid((string) getmypid(), true);
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
     * THE RESOLUTION, IN THE FAILURE TEXT, because the person reading it is
     * usually resolving a merge rather than debugging a test.
     *
     * WHAT THIS SAID: "php files under src/ — a CENSUS OF THE TREE, not a
     * policy. Re-derive it; never resolve a conflict here by choosing a side.
     * Two lanes that each added one file both read +1, and the merge needs +2."
     * WHAT IS TRUE NOW: no assertion in this file is a function of how many
     * files `src/` happens to contain, so there is no literal left to re-derive
     * and no side left to choose — adding a source file is GREEN here, and that
     * is pinned in both polarities rather than asserted. WHY THIS STILL EARNS
     * ITS PLACE: the advice was never wrong, it was unsatisfiable, and saying
     * why is the whole argument for the shape this file now has. Three lanes
     * each re-deriving the same count in three worktrees produce three answers
     * that are each correct locally and wrong at the merge, and a count merges
     * textually clean while being arithmetically wrong afterwards. The stronger
     * conclusion, which is what replaced the literals: a test that counts the
     * tree and asserts the tree's count is a CHANGE-DETECTOR, not an invariant.
     * It reds on every honest addition — a false positive that cost this repo
     * five separate cross-lane collisions — and it stays green through any
     * change that preserves the total, which is the false negative nobody was
     * counting. So this sentence survives as the standing instruction to the
     * next person tempted to add one back: write the INVARIANT the count was
     * standing in for, not the count.
     */
    private const CENSUS_RESOLUTION = 'this file deliberately asserts NO cardinality over src/. '
        . 'If you are resolving a merge by re-deriving a count here, stop: '
        . 'the claim wanted is the invariant the count stood in for, not the count.';

    /**
     * THE SYMBOL-KIND POLICY, which is what was left when the census was taken
     * out of it.
     *
     * WHAT THIS TEST SAID: an eleven-scalar vector — a file count, and one
     * count per symbol kind — asserted as exact literals against the real
     * `src/`, under a doc-block carrying a bump-by-bump history of which lane
     * had moved which scalar by how much. WHAT IS TRUE NOW: ten of those eleven
     * scalars were arithmetic. They were a function of how many files `src/`
     * happens to contain, so every one of them redded on any honest addition
     * and none of them would have moved for a change that swapped one concrete
     * class for another. WHY THE TEST STILL EARNS ITS PLACE: the eleventh
     * scalar was never a census at all. `abstract => 0` is a POLICY claim about
     * this codebase — no source file's PSR-4 symbol is an abstract class — and
     * `none => 0` is a reachability claim: every file under `src/` resolves to
     * the symbol its path names. Both are true of a tree of any size, both go
     * red for a real reason, and neither has anything to do with the total.
     *
     * WHY THE BUMP HISTORY IS GONE RATHER THAN CORRECTED. It existed for one
     * purpose: to tell the next lane which of the eleven literals its new file
     * had moved, and by how much. Read as prose it was a record of the defect
     * rather than of the tree — the paragraph itself said it had restated the
     * census wrong three times, named the wrong file for one bump, and
     * contradicted itself about which paragraph held the newest arrival. With
     * no literals to maintain, a bookkeeping note about maintaining them is not
     * history worth keeping; the argument it was making is the paragraph above.
     *
     * ⚠️ THE TWO ZEROS ARE AN ABSENCE, so on their own they are exactly what a
     * DEAD classifier reports (rule 15/25). {@see classifyPsr4Symbol()} is a
     * method rather than an inline `if` chain precisely so the fixture in
     * {@see testTheSymbolKindClassifierStillNamesEveryShapeIncludingTheTwoPinnedAtZero()}
     * can push a known abstract class and a known missing symbol through THE
     * SAME instrument. The four `assertGreaterThan(0, ...)` calls below are the
     * second half of that: a classifier that answered `concrete` to everything
     * would satisfy both zeros and fail those.
     */
    public function testEverySourceFileResolvesToASymbolAndNoneOfThemIsAbstract(): void
    {
        $counts = ['concrete' => 0, 'enum' => 0, 'abstract' => 0, 'interface' => 0, 'trait' => 0, 'none' => 0];
        $abstract = [];
        $unresolved = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($this->srcDir) + 1);
            $class = 'SugarCraft\\Crush\\' . str_replace('/', '\\', substr($relative, 0, -4));

            $kind = $this->classifyPsr4Symbol($class);
            ++$counts[$kind];

            if ($kind === 'abstract') {
                $abstract[] = $relative;
            } elseif ($kind === 'none') {
                $unresolved[] = $relative;
            }
        }

        sort($abstract);
        sort($unresolved);

        $this->assertSame(
            [],
            $abstract,
            'src/ declares no abstract class as a file\'s own PSR-4 symbol — this is a policy, '
            . 'not a count, and it is the one claim the old class_exists()-only guard got right: '
            . implode(', ', $abstract),
        );
        $this->assertSame(
            [],
            $unresolved,
            'every src/ file must resolve to the symbol its path names: ' . implode(', ', $unresolved),
        );

        // The known-positive half of the two zeros above (rule 15): a
        // classifier that had stopped discriminating would report both
        // absences just as happily. These are bounds, not counts — they do not
        // move when a file is added.
        foreach (['concrete', 'enum', 'interface', 'trait'] as $kind) {
            $this->assertGreaterThan(
                0,
                $counts[$kind],
                "src/ contains at least one {$kind} symbol; zero here means the classifier stopped answering, "
                . 'not that the tree changed shape',
            );
        }
    }

    /**
     * The classifier's own fixture, so the two zeros next door are an
     * observation rather than the reading of a dead instrument (rule 15).
     *
     * `abstract` and `none` are the load-bearing rows: they are the two answers
     * the census asserts NOBODY gives, so they are the two the census can never
     * demonstrate the classifier is still capable of giving. The other four
     * rows are covered twice over — here, and by the `assertGreaterThan(0, ...)`
     * bounds over the real tree.
     */
    public function testTheSymbolKindClassifierStillNamesEveryShapeIncludingTheTwoPinnedAtZero(): void
    {
        $this->writeProbe('Kinds/Solid.php', "namespace CorpusProbe\\Kinds;\n\nfinal class Solid\n{\n}\n");
        $this->writeProbe('Kinds/Shade.php', "namespace CorpusProbe\\Kinds;\n\nenum Shade\n{\n    case One;\n}\n");
        $this->writeProbe('Kinds/Base.php', "namespace CorpusProbe\\Kinds;\n\nabstract class Base\n{\n}\n");
        $this->writeProbe('Kinds/Seam.php', "namespace CorpusProbe\\Kinds;\n\ninterface Seam\n{\n}\n");
        $this->writeProbe('Kinds/Mixin.php', "namespace CorpusProbe\\Kinds;\n\ntrait Mixin\n{\n}\n");

        $this->assertSame('concrete', $this->classifyPsr4Symbol('CorpusProbe\\Kinds\\Solid'));
        $this->assertSame('enum', $this->classifyPsr4Symbol('CorpusProbe\\Kinds\\Shade'));
        $this->assertSame('abstract', $this->classifyPsr4Symbol('CorpusProbe\\Kinds\\Base'));
        $this->assertSame('interface', $this->classifyPsr4Symbol('CorpusProbe\\Kinds\\Seam'));
        $this->assertSame('trait', $this->classifyPsr4Symbol('CorpusProbe\\Kinds\\Mixin'));

        // No file was written for this one, so the probe autoloader returns
        // without declaring anything — the shape `none` names.
        $this->assertSame('none', $this->classifyPsr4Symbol('CorpusProbe\\Kinds\\Absent'));
    }

    /**
     * ONE instrument, shared by the tree census and by its fixture.
     *
     * Inline in the census this was six lines of `if` inside the walk, which is
     * where rule 15 bites: mutate those six lines to always answer `concrete`
     * and both of the census's zero assertions still pass, because `abstract`
     * and `none` are absences. A method can be pushed a known abstract class.
     *
     * `class_exists()` is true for an enum and for an abstract class, so the
     * kind has to come off the reflection rather than off which `*_exists()`
     * answered; interfaces and traits are invisible to `class_exists()`, which
     * is the ordering defect this scanner's own history is about.
     *
     * @return 'concrete'|'enum'|'abstract'|'interface'|'trait'|'none'
     */
    private function classifyPsr4Symbol(string $class): string
    {
        if (class_exists($class)) {
            $reflection = new \ReflectionClass($class);

            return $reflection->isEnum() ? 'enum' : ($reflection->isAbstract() ? 'abstract' : 'concrete');
        }

        if (interface_exists($class)) {
            return 'interface';
        }

        return trait_exists($class) ? 'trait' : 'none';
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
     * THE SECONDARY-DECLARATION MAP. `classNames()` derived one class per
     * FILENAME, so "the SOURCE TREE is the corpus … a new tool class is in every
     * one of these tests the moment it exists" held only for one-type-per-file —
     * and `src/` ships counterexamples. Derived with `token_get_all()` rather
     * than `class_exists()`, because a secondary symbol is not autoloadable by
     * its own name.
     *
     * PINNED PER FILE, WHICH IS THE POINT: this map NAMES the eight files that
     * declare more than their own PSR-4 symbol, so a second declaration
     * arriving in a scanned file reds this test with the file named. It is not
     * a cardinality over `src/` — adding a source file that declares one type
     * does not move a single row — so it survives the merge that a count does
     * not.
     *
     * WHAT THIS DOC-BLOCK SAID: a running narrative of two figures (files and
     * top-level declarations) that it had spelled "288" and "307" for two
     * rounds after the tree left those values behind, then "267/286", then
     * "+2 when Phase 5 items 8/9 added two files". WHAT IS TRUE NOW: both
     * figures, and the `assertSame()` calls that had been added to stop them
     * rotting, are gone from this test — they were counts of the tree asserted
     * against the tree. WHY THE RELATION STILL EARNS ITS PLACE: what does not
     * rot is that a MINORITY of declarations are secondary and that they live
     * in a handful of files, and the balance between the two totals is a real
     * invariant with its own test next door.
     *
     * `src/ToolRegistry.php` declaring `SugarCraft\\Crush\\Tool` is reported
     * rather than moved: it is one `use` away from colliding with
     * `SugarCraft\\Crush\\Tools\\Tool`, and `tests/ToolRegistryTest.php`
     * already imports it.
     */
    public function testTheSecondaryDeclarationMap(): void
    {
        $secondary = $this->secondaryDeclarations($this->srcDir, 'SugarCraft\\Crush\\');

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

        $this->assertContains('SugarCraft\\Crush\\Tool', $secondary['ToolRegistry.php']);
    }

    /**
     * THE BALANCE, and it is not the tautology it looks like.
     *
     * `declarations - files` equals the secondary total ONLY IF every file
     * declares its own PSR-4 symbol: a file that declares something else
     * contributes to the left side without its own name being subtracted, and
     * the two sides part. So this is the token-stream statement of the same
     * policy {@see testEverySourceFileDeclaresItsPsr4Symbol()} makes through
     * `nonClassSources()` and
     * {@see testEverySourceFileResolvesToASymbolAndNoneOfThemIsAbstract()}
     * makes through reflection — three independent instruments over one claim,
     * which is the arrangement that survives one of them going quiet.
     *
     * It replaces `assertSame(316, $declarations)` and `assertSame(297, count($files))`,
     * which were the same two numbers written down instead of related. Neither
     * literal could fail for a reason anyone wanted to hear about; this can,
     * and {@see testTheDeclarationBalanceSeesAFileThatDoesNotDeclareItsPsr4Symbol()}
     * shows it doing so.
     */
    public function testTheDeclarationBalanceHoldsAcrossTheWholeSourceTree(): void
    {
        [$files, $declarations] = $this->declarationTotals($this->srcDir);
        $secondary = $this->secondaryDeclarations($this->srcDir, 'SugarCraft\\Crush\\');

        $this->assertSame(
            $declarations - $files,
            array_sum(array_map('count', $secondary)),
            'top-level declarations minus files must equal the secondary total, which it does only '
            . 'while every src/ file declares its own PSR-4 symbol — ' . self::CENSUS_RESOLUTION,
        );
    }

    /**
     * The balance's known positive (rule 15): the SAME arithmetic over a
     * synthetic tree, first well-formed and then with one mis-namespaced file,
     * so the assertion next door is shown to be capable of parting rather than
     * merely observed not to have.
     */
    public function testTheDeclarationBalanceSeesAFileThatDoesNotDeclareItsPsr4Symbol(): void
    {
        $this->writeProbe('Kinds/Solid.php', "namespace CorpusProbe\\Kinds;\n\nfinal class Solid\n{\n}\n");

        [$files, $declarations] = $this->declarationTotals($this->probeDir);
        $secondary = $this->secondaryDeclarations($this->probeDir, self::PROBE_PREFIX);
        $this->assertSame($declarations - $files, array_sum(array_map('count', $secondary)));

        // Declares `CorpusProbe\Elsewhere\Skewed` from a path that names
        // `CorpusProbe\Kinds\Skewed`, so its own name is never subtracted.
        $this->writeProbe('Kinds/Skewed.php', "namespace CorpusProbe\\Elsewhere;\n\nfinal class Skewed\n{\n}\n");

        [$files, $declarations] = $this->declarationTotals($this->probeDir);
        $secondary = $this->secondaryDeclarations($this->probeDir, self::PROBE_PREFIX);
        $this->assertNotSame(
            $declarations - $files,
            array_sum(array_map('count', $secondary)),
            'a file that does not declare its PSR-4 symbol must part the two sides of the balance',
        );
    }

    /**
     * @return array<string, list<string>> relative path => its non-primary declarations
     */
    private function secondaryDeclarations(string $dir, string $prefix): array
    {
        $secondary = [];
        foreach (BuiltInToolCorpus::sourceFiles($dir) as $relative) {
            $primary = $prefix . str_replace('/', '\\', substr($relative, 0, -4));
            $extra = array_values(array_diff(
                BuiltInToolCorpus::declaredTypes($dir . '/' . $relative),
                [$primary],
            ));

            if ($extra !== []) {
                $secondary[$relative] = $extra;
            }
        }

        return $secondary;
    }

    /** @return array{0: int, 1: int} file count, top-level declaration count */
    private function declarationTotals(string $dir): array
    {
        $files = BuiltInToolCorpus::sourceFiles($dir);
        $declarations = 0;
        foreach ($files as $relative) {
            $declarations += count(BuiltInToolCorpus::declaredTypes($dir . '/' . $relative));
        }

        return [count($files), $declarations];
    }

    /**
     * THE TWO ARGUMENTS {@see RepoMapBlock} MAKES ABOUT THIS TREE, checked as
     * arguments rather than as the digits they used to be written with.
     *
     * WHAT THIS WAS: two `assertStringContainsString()` calls asserting that
     * `RepoMapBlock`'s prose spelled today's file count and today's declaration
     * count. They were derived — `sprintf()` against the walk, not literals —
     * and they were still the tightest coupling in the repository, because they
     * made a PRODUCTION doc-block a file every lane adding a source file had to
     * edit. WHAT IS TRUE NOW: the prose carries no figure, and what is asserted
     * is the claim the figure was supporting. WHY THIS STILL EARNS ITS PLACE:
     * removing the assertion and leaving the prose would have left an unpinned
     * sentence that rots with nothing going red, which is the failure mode this
     * whole change is about — so the prose lost its figures in the same commit,
     * and both halves are checked here.
     *
     * ARGUMENT 1, from WHAT WAS DELIBERATELY NOT BUILT: a per-CLASS listing was
     * rejected because it does not fit. Derived below at fully-qualified width
     * against {@see RepoMapBlock::MAX_SECTION_BYTES}, which is the constant the
     * block's own renderer measures a section against.
     *
     * ⚠️ THE OLD PROSE WAS WRONG ABOUT THIS, and nothing caught it because only
     * the digit was asserted, never the claim the digit supported. It said such
     * a listing would be "several times this whole block's budget". MEASURED on
     * this tree, on PHP 8.3.6: at fully-qualified width it is about one and a
     * half times the cap — over it, but not "several times" it — and at BARE
     * SHORT-NAME width it comes to roughly half the cap and would comfortably
     * FIT. So the WIDTH the claim is made at is load-bearing and the sentence
     * never stated it. The assertion below names the width explicitly. (Neither
     * measured byte figure is written into `src/`: it is a cardinality over the
     * tree, and this test is the generator.)
     *
     * ARGUMENT 2, from {@see RepoMapBlock::MAX_SOURCE_FILES}: the walk's
     * backstop is generous because it is a backstop and not a policy, and a
     * package this size sits well under it. Asserted as ORDER OF MAGNITUDE
     * rather than as the exact multiple — the multiple moves with every file
     * added, which is what kept sending that paragraph stale, while the order
     * of magnitude survives the tree roughly septupling.
     */
    public function testTheTwoDesignArgumentsRepoMapBlockMakesAboutThisTreeStillHold(): void
    {
        $fullyQualifiedListing = 0;
        foreach (BuiltInToolCorpus::sourceFiles($this->srcDir) as $relative) {
            foreach (BuiltInToolCorpus::declaredTypes($this->srcDir . '/' . $relative) as $type) {
                $fullyQualifiedListing += \strlen($type) + 1;
            }
        }

        $this->assertGreaterThan(
            RepoMapBlock::MAX_SECTION_BYTES,
            $fullyQualifiedListing,
            'RepoMapBlock declines to emit a per-class listing because one would not fit; at one '
            . 'fully-qualified name per line it must still overrun MAX_SECTION_BYTES, or that '
            . 'design note has outlived its reason and the note is what needs rewriting',
        );

        [$files] = $this->declarationTotals($this->srcDir);
        $this->assertGreaterThan(
            $files * 10,
            RepoMapBlock::MAX_SOURCE_FILES,
            'MAX_SOURCE_FILES is argued as a backstop a normal package sits more than an order of '
            . 'magnitude under; if this package no longer does, the constant is now a policy and '
            . 'its doc-block says otherwise',
        );

        // And the prose still MAKES both arguments. Flattened first: a
        // doc-block wraps at 80 columns with ' * ' on every continuation, so a
        // sentence is never those bytes in a row (rule 17) — the previous
        // version of this pin only worked because both fragments it matched
        // happened to fall inside one line.
        $prose = $this->flattenedSource($this->srcDir . '/Context/RepoMapBlock.php');

        $this->assertStringContainsString(
            "overruns this block's whole byte budget",
            $prose,
            'ARGUMENT 1 has left RepoMapBlock; the assertion above now pins nothing anyone can read',
        );
        $this->assertStringContainsString(
            'more than an order of magnitude under it',
            $prose,
            'ARGUMENT 2 has left RepoMapBlock; the assertion above now pins nothing anyone can read',
        );
    }

    /**
     * THE OTHER HALF, AND THE ONE THE BRIEF PREDICTED WOULD FAIL SILENTLY: with
     * the census assertions gone, nothing stops a future edit restating the
     * count in `RepoMapBlock` again. It has been restated there, and been
     * wrong, three times — once shipping `284/303` in the very commit that
     * moved the tree to `285/304` thirty lines away in its own message.
     *
     * So the restatement is now FORBIDDEN rather than checked. This is an
     * assertion of absence, so it runs a known positive through the SAME
     * scanner in the same test (rule 15): a fixture that does restate the file
     * count must come back reported. The fixture is built by concatenation so
     * the offending digits are never spelled in this file — a blanket sweep for
     * a pattern must not trip over the guard that documents it (rule 26).
     *
     * RESIDUAL DOMAIN, stated because a guard that cannot say what it misses is
     * a licence: this matches the two derived figures as standalone integers
     * anywhere in the file, so a coincidence — `src/` growing to exactly the
     * value of one of `RepoMapBlock`'s own constants — would report a false
     * positive. The resolution then is to reword the collision, NOT to weaken
     * the scanner. The historical figures the file preserves under rule 7 are
     * untouched by construction: they are stale by definition, so they are not
     * today's derivation.
     */
    public function testRepoMapBlockNoLongerRestatesTheSourceCensus(): void
    {
        [$files, $declarations] = $this->declarationTotals($this->srcDir);
        $block = (string) file_get_contents($this->srcDir . '/Context/RepoMapBlock.php');

        $this->assertSame(
            [],
            $this->restatedFigures($block, [$files, $declarations]),
            'RepoMapBlock restates a census of src/ again — ' . self::CENSUS_RESOLUTION,
        );

        $fixture = '     * because it is a backstop and not a policy: `src/` here is '
            . $files . ' files, so' . "\n" . '     * a normal package is comfortably under it.';

        $this->assertSame(
            [$files],
            $this->restatedFigures($fixture, [$files, $declarations]),
            'the scanner asserting the absence above must still be able to SEE a restatement',
        );
    }

    /**
     * @param  list<int>  $figures
     * @return list<int>  those of $figures that appear in $source as standalone integers
     */
    private function restatedFigures(string $source, array $figures): array
    {
        $found = [];
        foreach (array_values(array_unique($figures)) as $figure) {
            if (preg_match('/(?<![0-9])' . $figure . '(?![0-9])/', $source) === 1) {
                $found[] = $figure;
            }
        }

        return $found;
    }

    /**
     * $file with every doc-block continuation marker removed and all runs of
     * whitespace collapsed, so a wrapped sentence can be matched as a sentence
     * (rule 17).
     */
    private function flattenedSource(string $file): string
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $lines = array_map(static fn (string $line): string => preg_replace('/^\s*\*\s?/', '', $line) ?? $line, $lines);

        return trim((string) preg_replace('/\s+/', ' ', implode(' ', $lines)));
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
