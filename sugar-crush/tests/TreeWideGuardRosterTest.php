<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\TestFileWalkTrait;
use SugarCraft\Crush\Tests\Support\TokenFunctionRanges;

/**
 * THE SET OF TESTS THAT MUST RUN ON EVERY STEP IS DERIVED FROM THE TREE, NOT
 * TYPED INTO A PLAN DOCUMENT.
 *
 * WHAT THIS FIXES. `prompt_plan.md` section 1.2 action 7b carries a
 * HAND-MAINTAINED list of nine test files that "walk `src/` and `tests/`
 * wholesale", and every step in that plan is required to run them. A test over a
 * hand-maintained list inherits that list's omissions (section 16.8 rule 15),
 * and this one has now inherited three in a single batch: one member
 * (`InterpolationOpenerTokenTest`) was being run in practice while the list said
 * six, one red a step's FULL suite after five clean review cycles, and one moved
 * another step's assertion total by +23 after twenty-five other guards had been
 * measured individually. The list's own text says "do not treat this list as the
 * set of tree-wide guards - treat it as the ones known to bite". That is an
 * honest disclaimer on a derivable population, which is what this file replaces.
 *
 * MEASURED, and it is one grep: `/usr/bin/grep -rln 'TestFileWalkTrait' tests/`
 * names SEVEN consumers of the SAME shared whole-tree walker, and FIVE of them
 * are outside the list of nine - one of which carries 3,268 assertions on its
 * own, better than a tenth of the whole nine-file set's total.
 *
 * TWO CHANNELS, BOTH STRUCTURAL, AND THEIR PRECISION IS MEASURED RATHER THAN
 * ASSERTED.
 *
 *  - CHANNEL A: the file uses the shared walker trait
 *    {@see \SugarCraft\Crush\Tests\Support\TestFileWalkTrait}, whose
 *    `everyTestFile()` walks the whole of `tests/`. Sound by construction, since
 *    the trait IS the walk.
 *  - CHANNEL B: the file constructs a directory walker whose ROOT resolves to
 *    the package root. Resolution is a small same-file dataflow: an anchor
 *    (`dirname(__DIR__…)`, or `__DIR__` followed by a `/..` segment) written
 *    directly in the walker's own argument, or reaching it through an
 *    assignment, a class constant, a property default, a `foreach` binding, or
 *    a zero-argument helper whose body is itself anchored. Iterated to a
 *    fixpoint, so a chain of any depth resolves.
 *
 * WHY THE ROOT MUST BE IN THE WALKER'S OWN ARGUMENT and not merely somewhere in
 * the file: at file-level co-occurrence resolution - "this file calls `glob()`
 * somewhere and names the package root somewhere" - the detector returns 87
 * files on this tree, because a walk anchored at a temp directory the test just
 * made is indistinguishable from one anchored at a source root. MEASURED, both
 * numbers, on this tree: 182 test files call a walker at all; 87 of those also
 * name the package root; and channel B's per-call-site resolution reduces that
 * to a roster. A superset is not a roster and is not shipped as one.
 *
 * WHAT IS DELIBERATELY *NOT* IN THE DERIVATION, with the measurement that
 * decided it. A rule that taints a function PARAMETER from the arguments of its
 * same-file call sites would resolve three more real guards - but it is
 * flow-INSENSITIVE, so one call passing a source root and another passing a temp
 * directory taint the same parameter. MEASURED on this tree: that rule promotes
 * SEVEN files and only THREE of the seven are genuinely tree-wide, so it buys
 * three members at the price of four wrong ones and of a roster nobody can
 * check by reading. The three it gets right are declared by hand instead, in
 * {@see DECLARED_TREE_WIDE_GUARDS}, each with the reason - which is rule 15's
 * actual instruction: derive what can be derived, and declare the remainder
 * where a human has looked, rather than hand-maintaining the whole list.
 *
 * THE SELF-POLICING HALF, AND IT IS THE HALF THAT BITES. A derivation that
 * silently answers "not tree-wide" for a walk it cannot read is fail-OPEN in
 * exactly the direction that hid the omissions above. So every walker call site
 * in a file that names the package root must land in one of three buckets -
 * derived tree-wide, declared tree-wide, or declared local
 * ({@see WALKS_A_DIRECTORY_THE_TEST_MADE}, keyed on the file AND the flattened
 * expression) - and anything else reds
 * {@see testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor()}
 * and names itself. Section 16.8 rule 32: a guard must report what it cannot
 * read, never silently pass it.
 *
 * WHY THE LOCAL BUCKET IS KEYED ON FILE *AND* EXPRESSION (rule 35: an
 * exemption's key is its scope). Keyed on the expression alone, one row for
 * `scandir($dir)` would absorb unboundedly many future files; keyed on the file
 * alone, it would absorb every future walk in that file. Keyed on both, moving a
 * licensed walk anywhere costs a row, which is the point.
 *
 * WHAT THIS FILE DOES NOT DO. It does not edit `prompt_plan.md` - the roster it
 * derives is reported by
 * {@see testTheHandMaintainedCensusSetIsASubsetOfTheDerivedRoster()}'s failure
 * message and by the P3.audit-fix-2 report, and the plan document is the
 * orchestrator's to update. It also does not claim the roster is COMPLETE: what
 * it claims is that the roster is derived, that the hand-maintained nine are all
 * inside it, and that nothing that walks the tree escapes classification
 * silently.
 *
 * THIS FILE IS ITSELF A MEMBER, through channel A, and
 * {@see testTheDerivationIncludesItselfAndTheTraitsOtherConsumers()} asserts it -
 * a roster of tree-wide guards that did not contain itself would be answering
 * about a population it is not in.
 *
 * @internal
 */
final class TreeWideGuardRosterTest extends TestCase
{
    use TestFileWalkTrait;

    /**
     * The nine files `prompt_plan.md` section 1.2 action 7b names, verbatim,
     * relative to `tests/`.
     *
     * A KNOWN-POSITIVE CONTROL, NOT THE ANSWER (section 16.8 rule 16). An
     * unfired instrument and a dead one produce identical silence, and a
     * derivation that returned the empty list would satisfy every "is derived"
     * claim in this file. These nine are the answers already known: each must
     * come out of the derivation. If one stops coming out, a channel has gone
     * blind and the failure names which file dropped.
     *
     * @var list<string>
     */
    private const HAND_MAINTAINED_CENSUS_SET = [
        'Config/EnvRosterDriftTest.php',
        'Config/GlobFigureDriftTest.php',
        'Support/ChildStderrCaptureTest.php',
        'Support/ChildWallClockBudgetTest.php',
        'Support/DuplicatedTestHelperDriftTest.php',
        'Support/InterpolationOpenerTokenTest.php',
        'SwallowingCatchCensusTest.php',
        'SymbolCitationDriftTest.php',
        'Tools/BuiltInToolCorpusTest.php',
    ];

    /**
     * The five consumers of the shared walker trait that the nine-file list
     * omits.
     *
     * THE FINDING, MADE EXECUTABLE. These are not an alphabet statement and not
     * an exemption: they are the specific omissions the Phase 3 close review
     * measured, pinned so that a future edit which quietly drops one from the
     * derivation reds instead of restoring the original defect. The first of
     * them is the file already recorded as having silently moved a step's total.
     *
     * @var list<string>
     */
    private const OMITTED_BY_THE_HAND_MAINTAINED_SET = [
        'Backend/AwaitPromiseDiagnosticArmTest.php',
        'Backend/ScaledClockHelperSeamTest.php',
        'Support/AssertionSwallowingCatchTest.php',
        'Support/DuplicatedDocBlockLineTest.php',
        'Support/OneSidedHomeSandboxTest.php',
    ];

    /**
     * Tree-wide guards the derivation cannot reach, and why each one is out of
     * reach.
     *
     * EVERY ROW HAS THE SAME CAUSE, and it is stated rather than implied: the
     * root reaches the walker through a function PARAMETER, and parameter taint
     * is not in the derivation for the measured reason the class doc-block
     * gives. A human has read each of these three and confirmed the walk is over
     * the package's own files.
     *
     * THIS IS NOT AN EXEMPTION LIST. An exemption removes something from a
     * verdict; each of these ADDS a file to the roster, so the direction is
     * toward more work rather than less, and a wrong row here costs a test run
     * rather than a missed guard.
     *
     * @var array<string, string>
     */
    private const DECLARED_TREE_WIDE_GUARDS = [
        // markdownPagesUnder(\dirname(__DIR__, 2)) - walks README.md plus the
        // whole of docs/ recursively; the root is the function's parameter.
        'Cli/BootstrapLaunchFormatConstantsTest.php' => 'markdownPagesUnder() takes the package root as a parameter and walks docs/ under it',
        // dotPathsIn(\dirname(__DIR__, 2) . '/src') at three call sites.
        'Cli/ProjectTierRefusalInventoryTest.php' => 'dotPathsIn() takes src/ as a parameter and walks it recursively',
        // phpFilesUnder($root . '/src') where $root = \dirname(__DIR__).
        'DenialPrefixRosterTest.php' => 'phpFilesUnder() takes src/ as a parameter and walks it recursively',
    ];

    /**
     * Walker call sites whose root the derivation cannot resolve, and which a
     * human has classified as a directory the TEST created rather than part of
     * the package.
     *
     * WHAT IS IN HERE, uniformly: a recursive-teardown helper taking the
     * directory as a parameter (`$dir`, `$directory`, `$probe`, `$cache`), a
     * glob over a temp sandbox the test made (`$this->tempDir`,
     * `$this->tmpDir`, `$this->tempHome`, `$resultDir`, `$this->worktreesBase`),
     * or a fixture COPY whose output feeds a temp repository rather than a
     * verdict. None of them is a census over the package's own files, so none of
     * them makes its test's verdict a function of the file population - which is
     * the whole membership criterion.
     *
     * THE ONE ROW WORTH A SECOND LOOK is `BaseSystemPromptTest.php`'s
     * `scandir($source)`: its caller does pass a fixed fixture directory that
     * lives inside `tests/`. It is classified LOCAL because the walk COPIES that
     * directory into a temp repository and the test's verdict is about the
     * prompt built from the copy, not about the fixture population. If a step
     * ever adds a file under that fixture tree, the golden prompt tests are what
     * catch it.
     *
     * MAINTENANCE, said plainly so nobody guesses: a new row here is a
     * one-line HUMAN classification, and it is required precisely because the
     * alternative is the silent pass. The rows are the flattened token text of
     * the walker call - whitespace removed, so a reformat does not red this.
     *
     * @var array<string, list<string>>
     */
    private const WALKS_A_DIRECTORY_THE_TEST_MADE = [
        'Agents/AgentPresetRegistryTest.php' => ['scandir($dir)'],
        'Agents/WorktreeConfigTest.php' => ['scandir($dir)'],
        'Agents/WorktreeManagerConfigOriginTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'BaseSystemPromptTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)', 'scandir($source)'],
        'ClaudeCodeMcpClientStdinWedgeTest.php' => ['glob($this->tempDir.\'/*\')'],
        'Cli/AgentManagerWiringTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'Cli/BootstrapHookFileTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'Cli/BootstrapLaunchNoticeRoutingTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)', 'glob($this->tmpDir.\'/launch*.php\')'],
        'Cli/BootstrapSkillSkipsTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'Cli/BootstrapTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'Cli/BootstrapToolAndPermissionSettingsTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'Cli/BootstrapTrustGateSelfGrantTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)', 'scandir($probe)'],
        'Context/EnvironmentBlockTest.php' => ['scandir($dir)'],
        'Integration/BinSugarcrushAutoloadGuardTest.php' => ['scandir($dir)'],
        'Integration/BinSugarcrushDispatchTest.php' => ['RecursiveDirectoryIterator($this->tempHome,\FilesystemIterator::SKIP_DOTS)'],
        'Integration/FeatWiringReachabilityTest.php' => ['scandir($dir)'],
        'Integration/McpToolWiringTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'Integration/MultiAgentRefactorTest.php' => [
            'glob($resultDir.\'/*.gaveup.*\')',
            'glob($resultDir.\'/*.threw\')',
            'glob($resultDir.\'/*.won.*\')',
            'glob($resultDir.\'/task-a.won.*\')',
            'glob($resultDir.\'/task-b.won.*\')',
            'scandir($dir)',
            'scandir($this->worktreesBase)',
        ],
        'LSP/LspConnectionStdinWedgeTest.php' => ['scandir($dir)'],
        'MCP/McpClientTest.php' => ['glob($this->tempDir.\'/*\')'],
        'MCP/OAuthClientRegistrationTest.php' => ['glob($this->tempDir.\'/*\')'],
        'MCP/StdioMcpServerStderrDrainTest.php' => ['RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)'],
        'MCP/StdioMcpServerWriteBoundsTest.php' => ['scandir($dir)'],
        'Sessions/BackgroundSupervisorReapTest.php' => ['glob($this->tempDir.\'/*\')'],
        'SuiteChildStdinIsolationTest.php' => ['scandir($dir)'],
        'SuiteChildStdinPrependResidualTest.php' => ['scandir($dir)'],
        'SuiteSkipRosterTest.php' => ['scandir($cache)'],
        'Workflows/WorkflowRegistryTest.php' => ['scandir($dir)'],
    ];

    /**
     * The walker spellings this derivation can read, split by how PHP spells
     * them.
     *
     * AN ALPHABET IS COVERAGE (section 16.8 rule 31), so what it cannot express
     * is stated: a walk performed by a collaborator object
     * (`$finder->in($root)`), by a subprocess (`find`, `git ls-files`), by
     * `SplFileObject` over a manifest, or through a name reached from a string,
     * is out of reach here - and so is a root that arrives from ANOTHER file, a
     * base class or a trait other than the one channel A already keys on. The
     * accepted direction for anything unreadable is a report, not a pass, which
     * is what the third bucket is for.
     *
     * @var list<string>
     */
    private const WALKER_CLASSES = ['recursivedirectoryiterator', 'directoryiterator', 'filesystemiterator'];

    /** @var list<string> */
    private const WALKER_FUNCTIONS = ['glob', 'scandir', 'readdir'];

    /**
     * An expression that names the package root.
     *
     * BOTH SPELLINGS THIS TREE ACTUALLY USES, and no third: `dirname(__DIR__)`
     * with any depth, and `__DIR__` concatenated with a path that climbs. A
     * bare `__DIR__ . '/fixtures'` is deliberately NOT an anchor - it names a
     * directory beside the test, which is how a fixture is addressed, and
     * treating it as the root put 30 more files in the roster for no reason.
     */
    private const ROOT_ANCHOR = '~dirname\(__DIR__|__DIR__\.[\'"]/\.\.~i';

    /** @var array{roster: list<string>, unaccounted: array<string, list<string>>}|null */
    private static ?array $derivation = null;

    /**
     * CHANNEL A, AND THIS FILE'S OWN MEMBERSHIP.
     *
     * The trait's consumers are the one group whose tree-wide-ness is a fact
     * about the code rather than an inference from it, so they are the derivation
     * step that cannot be wrong. This test pins the five the hand-maintained list
     * omits AND this file itself - a roster that did not contain itself would be
     * reporting on a population it is not in, and this file walks the whole of
     * `tests/` on every one of its own assertions.
     */
    public function testTheDerivationIncludesItselfAndTheTraitsOtherConsumers(): void
    {
        $roster = self::derivation()['roster'];

        $this->assertContains(
            'TreeWideGuardRosterTest.php',
            $roster,
            'this file walks the whole of tests/ and is not in its own derived roster, so the '
                . 'derivation is not seeing the mechanism it is built on',
        );

        foreach (self::OMITTED_BY_THE_HAND_MAINTAINED_SET as $omitted) {
            $this->assertContains(
                $omitted,
                $roster,
                $omitted . ' walks the whole of tests/ through the shared walker trait and is not in '
                    . 'the derived roster. It is one of the five omissions this file exists to stop '
                    . 'being silent; if it genuinely stopped walking the tree, remove it from '
                    . 'OMITTED_BY_THE_HAND_MAINTAINED_SET in the same change-set and say why.',
            );
        }

        // NOT VACUOUS, and both polarities through the same detector: a file
        // that does not use the trait and does not walk a source root must NOT
        // be in the roster. Without this, a derivation returning every test file
        // would satisfy every assertion above.
        $this->assertNotContains(
            'Context/EnvironmentBlockTest.php',
            $roster,
            'a test whose only walk is its own temp-directory teardown is in the roster, so channel B '
                . 'is classifying on co-occurrence rather than on the walker\'s own argument',
        );
    }

    /**
     * THE FINDING ITSELF, AS A PASSING TEST AND A FAILING MESSAGE.
     *
     * The nine hand-maintained files must ALL come out of the derivation. That
     * is the known-positive control (rule 16) and it is what makes every other
     * claim here worth reading: a derivation that missed one of the nine would
     * be a worse instrument than the list it replaces.
     *
     * IT IS ALSO THE DIRECTION THAT REDS ON A REAL REGRESSION. If somebody
     * narrows a channel and a known member drops out, this names the file. If
     * somebody adds a tenth entry to the plan's list that the derivation cannot
     * see, adding it here reds until the derivation - or a declared row -
     * accounts for it.
     */
    public function testTheHandMaintainedCensusSetIsASubsetOfTheDerivedRoster(): void
    {
        $roster = self::derivation()['roster'];
        $missing = array_values(array_diff(self::HAND_MAINTAINED_CENSUS_SET, $roster));

        $this->assertSame(
            [],
            $missing,
            self::describeRosterGap($missing),
        );

        // AND THE DERIVATION IS STRICTLY WIDER, which is the finding: the
        // hand-maintained list is not the set of tree-wide guards. Asserted as
        // a non-empty difference rather than as a count, because a count here
        // would be the very defect this file is about.
        $this->assertNotSame(
            [],
            array_values(array_diff($roster, self::HAND_MAINTAINED_CENSUS_SET)),
            'the derived roster no longer finds anything the hand-maintained list of nine omits. That '
                . 'would mean either the list caught up or a channel died; check the second before '
                . 'believing the first.',
        );
    }

    /**
     * THE FAIL-CLOSED HALF: nothing that walks the tree is classified silently.
     *
     * Every walker call site in a test file that also names the package root
     * must be one of: resolved to the package root by channel B, covered by the
     * file's declared tree-wide row, or covered by a declared local row keyed on
     * this exact file AND this exact expression. Anything else reds here and
     * prints itself, which is the mechanism that turns "the list is incomplete"
     * from a discovery into a test failure.
     *
     * THE DOMAIN IS NARROWED ON PURPOSE to files that name the package root at
     * all: a test that never mentions the root cannot walk it, so requiring a
     * declared row for every `glob()` in `tests/` would be 182 rows of noise
     * instead of 37 of signal. That narrowing is a NECESSARY condition and
     * therefore sound in the fail-closed direction - the residual gap is a root
     * arriving from another file, which the class doc-block names.
     */
    public function testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor(): void
    {
        $unaccounted = self::derivation()['unaccounted'];

        $lines = [];
        foreach ($unaccounted as $file => $expressions) {
            foreach ($expressions as $expression) {
                $lines[] = $file . '  =>  ' . $expression;
            }
        }

        $this->assertSame(
            [],
            $lines,
            "a walker call site in a file that names the package root is in none of the three buckets:\n"
                . implode("\n", $lines) . "\n\n"
                . 'Classify it. If the walk is over the package\'s own files, the test is a tree-wide '
                . 'guard - add it to DECLARED_TREE_WIDE_GUARDS with the reason the derivation cannot '
                . 'see it, and add it to prompt_plan.md section 1.2 action 7b. If it walks a directory '
                . 'the test created, add the file and this exact expression to '
                . 'WALKS_A_DIRECTORY_THE_TEST_MADE. Do NOT widen the anchor pattern to make this pass: '
                . 'that is the direction that lets the next guard in silently.',
        );
    }

    /**
     * THE INSTRUMENT, AGAINST KNOWN ANSWERS, BEFORE ANY VERDICT ABOVE IS
     * BELIEVED (section 1.4 check 13, section 16.8 rules 16-18).
     *
     * Five synthesised sources, each one shape, driven through the same
     * `classifyWalkSites()` the roster is built from. Two must classify as the
     * package root, one as a directory the test made, one as unresolvable, and
     * one must produce no site at all. Without the negative rows a classifier
     * that reported everything would pass; without the positive rows one that
     * reported nothing would.
     */
    public function testTheWalkClassifierAnswersKnownInputsCorrectly(): void
    {
        $direct = "<?php\nforeach (glob(\\dirname(__DIR__, 2) . '/src/*.php') as \$f) {}\n";
        $this->assertSame(
            ['root' => ['glob(\dirname(__DIR__,2).\'/src/*.php\')'], 'unresolved' => []],
            self::classifyWalkSites($direct),
            'an anchor written directly in the walker argument is not resolved, so channel B is dead',
        );

        $viaChain = "<?php\nclass P { private const R = __DIR__ . '/../..';\n"
            . "  private function go(): void { \$lib = self::R; \$src = \$lib . '/src';\n"
            . "    \$it = new \\RecursiveDirectoryIterator(\$src, \\FilesystemIterator::SKIP_DOTS); } }\n";
        $this->assertSame(
            ['root' => ['RecursiveDirectoryIterator($src,\FilesystemIterator::SKIP_DOTS)'], 'unresolved' => []],
            self::classifyWalkSites($viaChain),
            'a root reaching the walker through a constant and two assignments is not resolved, so the '
                . 'fixpoint is not iterating',
        );

        $temp = "<?php\nclass P { private function go(): void { \$d = sys_get_temp_dir() . '/x';\n"
            . "    foreach (glob(\$d . '/*') as \$f) {} } }\n";
        $this->assertSame(
            ['root' => [], 'unresolved' => ['glob($d.\'/*\')']],
            self::classifyWalkSites($temp),
            'a temp-directory walk was resolved to the package root, which would put every test with a '
                . 'teardown in the roster',
        );

        $opaque = "<?php\nclass P { private function go(string \$where): void { scandir(\$where); } }\n";
        $this->assertSame(
            ['root' => [], 'unresolved' => ['scandir($where)']],
            self::classifyWalkSites($opaque),
            'a walk over an unresolvable root came back as neither root nor unresolved, so the '
                . 'fail-closed bucket is not being filled',
        );

        // Neither a walker call: `new Glob(...)` is this tree's Glob TOOL, and
        // `$this->glob(...)` is a method. A classifier that counted either
        // would demand declared rows for code that walks nothing.
        $notAWalk = "<?php\nclass P { private function go(): void { \$g = new Glob(prunedDirs: []);\n"
            . "    \$this->glob(\\dirname(__DIR__) . '/src'); \$x = \\dirname(__DIR__); } }\n";
        $this->assertSame(
            ['root' => [], 'unresolved' => []],
            self::classifyWalkSites($notAWalk),
            'a constructed Glob tool or a method named glob() is being read as a directory walk',
        );
    }

    /**
     * THE FAILURE MESSAGE GETS ITS OWN KNOWN-INPUT TEST (section 16.8 rule 25:
     * a guard's failure message is the one part of a green suite that never
     * runs).
     *
     * {@see describeRosterGap()} is the only text a future agent sees when a
     * known member drops out of the derivation, and a helper that returned the
     * empty string would be indistinguishable from a working one for as long as
     * the suite stayed green. So it is called with a known gap and a known
     * non-gap, and both answers are asserted.
     */
    public function testTheRosterGapMessageNamesTheMissingFilesRatherThanOnlyCountingThem(): void
    {
        $message = self::describeRosterGap(['Support/ChildStderrCaptureTest.php', 'SwallowingCatchCensusTest.php']);

        $this->assertStringContainsString('Support/ChildStderrCaptureTest.php', $message);
        $this->assertStringContainsString('SwallowingCatchCensusTest.php', $message);
        $this->assertStringContainsString('channel', $message, 'the message must say what to suspect, not only what is missing');

        // The no-gap answer is still a sentence rather than '', so a green run
        // cannot hide a helper that has stopped producing text.
        $this->assertNotSame('', self::describeRosterGap([]));
        $this->assertStringNotContainsString('Test.php', self::describeRosterGap([]));
    }

    /**
     * The derived roster and the unaccounted-for sites, computed once.
     *
     * @return array{roster: list<string>, unaccounted: array<string, list<string>>}
     */
    private static function derivation(): array
    {
        if (self::$derivation !== null) {
            return self::$derivation;
        }

        $roster = array_keys(self::DECLARED_TREE_WIDE_GUARDS);
        $unaccounted = [];

        foreach (self::everyTestFile() as $relative => $absolute) {
            if (!str_ends_with($relative, 'Test.php')) {
                continue;
            }
            $relative = str_replace('\\', '/', $relative);
            $source = (string) file_get_contents($absolute);

            if (self::usesTheSharedWalker($source)) {
                $roster[] = $relative;

                continue;
            }
            if (preg_match(self::ROOT_ANCHOR, self::flatten($source)) !== 1) {
                // A file that never names the package root cannot walk it.
                continue;
            }

            $sites = self::classifyWalkSites($source);
            if ($sites['root'] !== []) {
                $roster[] = $relative;

                continue;
            }

            $licensed = self::WALKS_A_DIRECTORY_THE_TEST_MADE[$relative] ?? [];
            if (isset(self::DECLARED_TREE_WIDE_GUARDS[$relative])) {
                continue;
            }

            $left = array_values(array_diff($sites['unresolved'], $licensed));
            if ($left !== []) {
                $unaccounted[$relative] = $left;
            }
        }

        $roster = array_values(array_unique($roster));
        sort($roster);
        ksort($unaccounted);

        return self::$derivation = ['roster' => $roster, 'unaccounted' => $unaccounted];
    }

    /**
     * Does this source use the shared whole-`tests/` walker?
     *
     * ON THE TOKEN STREAM AND NOT ON A GREP, because a grep cannot tell a
     * `use` of the trait from a doc-block that names it - and this file's own
     * doc-blocks name it four times.
     */
    private static function usesTheSharedWalker(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token) || \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (!\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            if (str_ends_with(ltrim($token[1], '\\'), 'TestFileWalkTrait')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every directory-walk call site in one source, split into the ones rooted
     * at the package and the ones this resolver cannot place.
     *
     * A CLASS WALKER COUNTS ONLY WHEN CONSTRUCTED and a FUNCTION walker only
     * when NOT: `new Glob(...)` in this tree is the Glob TOOL, and
     * `new FilesystemIterator($p)` is a walk. Excluding `->`, `?->`, `::` and
     * `function` keeps a method or a declaration named `glob` out.
     *
     * A LITERAL ABSOLUTE PATH is neither bucket - `glob('/proc/self/fd/*')`
     * cannot be the package tree and needs no human to say so.
     *
     * @return array{root: list<string>, unresolved: list<string>}
     */
    private static function classifyWalkSites(string $source): array
    {
        $tokens = self::significant($source);
        $count = \count($tokens);
        $tainted = self::rootAnchoredNames($tokens);

        $root = [];
        $unresolved = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || !\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            $isClass = \in_array($name, self::WALKER_CLASSES, true);
            if (!$isClass && !\in_array($name, self::WALKER_FUNCTIONS, true)) {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if ($isClass !== (\is_array($previous) && $previous[0] === T_NEW)) {
                continue;
            }
            if (\is_array($previous) && \in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }

            [$end, $balanced] = self::closingBracket($tokens, $i + 1);
            $arguments = self::join($tokens, $i + 2, $end - 1);
            $label = ltrim($token[1], '\\') . '(' . $arguments . ')';

            if (!$balanced) {
                $unresolved[] = 'UNBALANCED-ARGUMENT-LIST ' . $label;

                continue;
            }
            if (self::isRootAnchored($arguments, $tainted)) {
                $root[] = $label;

                continue;
            }
            if (preg_match('~^[\'"]/~', $arguments) === 1) {
                continue;
            }
            $unresolved[] = $label;
        }

        return ['root' => array_values(array_unique($root)), 'unresolved' => array_values(array_unique($unresolved))];
    }

    /**
     * The names in one source whose value carries a package-root anchor.
     *
     * FOUR RULES, ITERATED TO A FIXPOINT so a chain of any depth resolves:
     * an assignment (which also covers a class constant and a property default,
     * since the target is read as written and matched as written), a `foreach`
     * binding, and a zero-argument method whose body is anchored - the last one
     * keyed on all three call spellings, because a helper is reached as
     * `self::`, `$this->` or `static::` and a rule that knew one of the three
     * would answer differently for identical code.
     *
     * WHAT IS NOT HERE, and it is the measured omission the class doc-block
     * argues: taint on a function PARAMETER from its same-file call sites.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function rootAnchoredNames(array $tokens): array
    {
        $count = \count($tokens);
        $tainted = [];
        $functions = self::functionBodies($tokens);

        for ($pass = 0; $pass < 8; $pass++) {
            $before = \count($tainted);

            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i] !== '=') {
                    continue;
                }
                $low = $i - 1;
                while ($low >= 0 && !\in_array(self::text($tokens[$low]), [';', '{', '}', '(', ')', ',', '='], true)) {
                    $low--;
                }
                $target = self::join($tokens, $low + 1, $i - 1);
                if ($target === '') {
                    continue;
                }
                $depth = 0;
                $high = $i;
                for ($k = $i + 1; $k < $count; $k++) {
                    $text = self::text($tokens[$k]);
                    if ($text === '(' || $text === '[') {
                        $depth++;
                    }
                    if ($text === ')' || $text === ']') {
                        $depth--;
                    }
                    if ($text === ';' && $depth <= 0) {
                        break;
                    }
                    $high = $k;
                }
                if (!self::isRootAnchored(self::join($tokens, $i + 1, $high), $tainted)) {
                    continue;
                }
                // A constant or property declaration is written with its
                // modifiers; the call site spells only the name, so both the
                // written target and its bare tail are tainted.
                foreach (self::spellingsOf($target) as $spelling) {
                    if (!\in_array($spelling, $tainted, true)) {
                        $tainted[] = $spelling;
                    }
                }
            }

            for ($i = 0; $i < $count; $i++) {
                if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_FOREACH || ($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }
                [$end, $balanced] = self::closingBracket($tokens, $i + 1);
                if (!$balanced) {
                    continue;
                }
                $as = -1;
                $depth = 0;
                for ($k = $i + 2; $k < $end; $k++) {
                    $text = self::text($tokens[$k]);
                    if ($text === '(' || $text === '[') {
                        $depth++;
                    }
                    if ($text === ')' || $text === ']') {
                        $depth--;
                    }
                    if ($depth === 0 && \is_array($tokens[$k]) && $tokens[$k][0] === T_AS) {
                        $as = $k;

                        break;
                    }
                }
                if ($as < 0 || !self::isRootAnchored(self::join($tokens, $i + 2, $as - 1), $tainted)) {
                    continue;
                }
                for ($k = $as + 1; $k < $end; $k++) {
                    if (\is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE && !\in_array($tokens[$k][1], $tainted, true)) {
                        $tainted[] = $tokens[$k][1];
                    }
                }
            }

            foreach ($functions as $name => $body) {
                if (!self::isRootAnchored($body, $tainted)) {
                    continue;
                }
                foreach (['self::' . $name . '(', '$this->' . $name . '(', 'static::' . $name . '('] as $spelling) {
                    if (!\in_array($spelling, $tainted, true)) {
                        $tainted[] = $spelling;
                    }
                }
            }

            if (\count($tainted) === $before) {
                break;
            }
        }

        return $tainted;
    }

    /**
     * Every named function in one source, name => flattened body text.
     *
     * THE RANGES COME FROM THE SHARED
     * {@see \SugarCraft\Crush\Tests\Support\TokenFunctionRanges} RATHER THAN
     * FROM A PRIVATE BRACE WALK, and this file is the reason that class's
     * doc-block gives for existing. The private walk that stood here counted
     * depth on the bare one-byte strings `{` and `}`, which is WRONG for both
     * interpolation openers: PHP returns `T_CURLY_OPEN` (`{$x}`) and
     * `T_DOLLAR_OPEN_CURLY_BRACES` (`${x}`) as ARRAY tokens and closes each with
     * a bare `}`, so the first interpolated string in a scanned body decremented
     * a level that was never incremented and truncated the body from there. That
     * would have made the zero-argument-helper taint rule fail OPEN - a helper
     * whose anchor sat after an interpolation would have gone unresolved and its
     * caller would have landed in the unaccounted bucket rather than the roster.
     * MEASURED: `tests/Support/InterpolationOpenerTokenTest.php` red on this
     * file the first time the census set ran, naming both openers, which is that
     * guard doing precisely its job on a file written in the same change-set as
     * a fix to the roster it belongs to.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string>
     */
    private static function functionBodies(array $tokens): array
    {
        $bodies = [];
        foreach (TokenFunctionRanges::scan($tokens) as $range) {
            $bodies[$range['name']] = self::join($tokens, $range['from'], $range['to']);
        }

        return $bodies;
    }

    /**
     * Does this expression carry a package-root anchor, directly or through a
     * name already known to?
     *
     * @param list<string> $tainted
     */
    private static function isRootAnchored(string $expression, array $tainted): bool
    {
        if (preg_match(self::ROOT_ANCHOR, $expression) === 1) {
            return true;
        }
        foreach ($tainted as $name) {
            if ($name !== '' && str_contains($expression, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The spellings an assignment target is reached by at a use site.
     *
     * `private const LIB_ROOT` is written with its modifiers and read as
     * `self::LIB_ROOT`; `private string $srcDir` is read as `$this->srcDir`. A
     * plain local keeps its own spelling.
     *
     * @return list<string>
     */
    private static function spellingsOf(string $target): array
    {
        if (preg_match('~const([A-Za-z_][A-Za-z0-9_]*)$~', $target, $match) === 1) {
            return ['self::' . $match[1], 'static::' . $match[1], $match[1]];
        }
        if (preg_match('~^(?:private|protected|public|static|readonly|\\??[A-Za-z|\\\\]+)+(\$[A-Za-z_][A-Za-z0-9_]*)$~', $target, $match) === 1) {
            return ['$this->' . ltrim($match[1], '$'), 'self::' . $match[1], $match[1]];
        }

        return [$target];
    }

    /**
     * The token stream without the three classes that are legal between any two
     * tokens this walk reads as neighbours.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function significant(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /**
     * The index of the bracket matching the one at $open, and whether one was
     * found at all.
     *
     * AN UNBALANCED LIST IS REPORTED, NEVER TREATED AS EMPTY: an argument list
     * this cannot read is the shape the next unreadable walk takes, and "cannot
     * decide" must not come out as "not the package tree".
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: bool}
     */
    private static function closingBracket(array $tokens, int $open): array
    {
        $depth = 0;
        $count = \count($tokens);
        for ($i = $open; $i < $count; $i++) {
            $text = self::text($tokens[$i]);
            if ($text === '(' || $text === '[') {
                $depth++;
            }
            if ($text === ')' || $text === ']') {
                $depth--;
                if ($depth === 0) {
                    return [$i, true];
                }
            }
        }

        return [$count - 1, false];
    }

    /**
     * The token texts from $from to $to with NO separator between them, so a
     * reformat cannot change the answer and `$this->srcDir` compares equal to
     * itself however it was spaced.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function join(array $tokens, int $from, int $to): string
    {
        $text = '';
        $count = \count($tokens);
        for ($i = max(0, $from); $i <= $to && $i < $count; $i++) {
            $text .= self::text($tokens[$i]);
        }

        return $text;
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private static function text(array|string|null $token): string
    {
        if ($token === null) {
            return '';
        }

        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * One source with every run of whitespace removed, for matching an anchor
     * that a formatter may have wrapped.
     */
    private static function flatten(string $source): string
    {
        return (string) preg_replace('~\s+~', '', $source);
    }

    /**
     * What to tell the next agent when a known member drops out of the
     * derivation.
     *
     * ITS OWN TEST IS
     * {@see testTheRosterGapMessageNamesTheMissingFilesRatherThanOnlyCountingThem()},
     * because this string is only ever read on a red run and a helper returning
     * '' would look identical to a working one for as long as the suite is green.
     *
     * @param list<string> $missing
     */
    private static function describeRosterGap(array $missing): string
    {
        if ($missing === []) {
            return 'every hand-maintained census member came out of the derivation';
        }

        return "the derivation no longer finds these hand-maintained census members:\n"
            . implode("\n", array_map(static fn (string $file): string => '  - ' . $file, $missing))
            . "\n\nOne of the two channels has narrowed. Suspect the channel before the file: check "
            . 'whether it still uses the shared walker trait, and whether its walk root still resolves '
            . 'through the four taint rules in rootAnchoredNames(). If a file genuinely stopped walking '
            . 'the tree, remove it from HAND_MAINTAINED_CENSUS_SET and from prompt_plan.md section 1.2 '
            . 'action 7b in the same change-set - do not widen the anchor pattern to paper over it.';
    }
}
