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
 * MEASURED, WITH ITS GENERATOR AND ITS DOMAIN, because this figure moved while
 * the file was being written and that is the defect the file is about:
 * `/usr/bin/grep -rln 'TestFileWalkTrait' tests/ | wc -l` returns 9 at this
 * commit and 8 at `bb4a311d0`. Two of the nine are not consumers - the trait's
 * own declaration, and THIS file, which is the one the count gained. So there
 * were SEVEN consumers of the same shared whole-tree walker at `bb4a311d0`, and
 * FIVE of those seven are outside the list of nine - one of which carries 3,268
 * assertions on its own, better than a tenth of the whole nine-file set's 31,215
 * (10.5%). AN EARLIER REVISION OF THIS PARAGRAPH said the grep "names SEVEN
 * consumers", which that grep returned at no commit; the subtraction is written
 * out now instead of being left for the reader to rediscover.
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
 * somewhere and names the package root somewhere" - a walk anchored at a temp
 * directory the test just made is indistinguishable from one anchored at a
 * source root, and the candidate set comes out far wider than the roster. THE
 * CARDINALITIES ARE DELIBERATELY NOT PINNED IN THIS PROSE. An earlier revision
 * gave two of them - "182 test files call a walker at all; 87 of those also name
 * the package root" - with no generator attached, and a reviewer who tried eight
 * definitions of those two populations reproduced neither. That is section 16.8
 * rule 2 happening inside the file that cites it. The populations are DERIVED
 * and their ORDERING is what is pinned, by
 * {@see testTheCandidateSetIsStrictlyWiderThanTheRosterAndEveryPopulationIsDerived()},
 * which reads them off {@see derivation()} instead of out of a sentence. A
 * superset is not a roster and is not shipped as one.
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
 * THE SELF-POLICING HALF, AND EXACTLY WHAT IT DOES NOT COVER - CORRECTED IN
 * PLACE (section 16.8 rule 42), because the sentence that stood here was false
 * in the one direction that matters.
 *
 * WHAT IT COVERS: every walker call site in a file that names the package root
 * lands in one of three buckets - derived tree-wide, declared tree-wide, or
 * declared local ({@see WALKS_A_DIRECTORY_THE_TEST_MADE}, keyed on the file AND
 * the flattened expression) - and anything else reds
 * {@see testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor()}
 * and names itself.
 *
 * WHAT THIS PARAGRAPH USED TO SAY, AND IT WAS WRONG: "The accepted direction for
 * anything unreadable is a report, not a pass, which is what the third bucket is
 * for." (The sentence sat on {@see WALKER_CLASSES}.)
 *
 * WHAT IS TRUE: the third bucket catches a walk whose WALKER this alphabet
 * recognises and whose ROOT it cannot resolve. It does NOT catch a walk the
 * alphabet cannot see as a walk at all - a collaborator object, a subprocess, a
 * walker reached through a string, or a root spelled in a way
 * {@see ROOT_ANCHOR} does not match. Those produce NO site, so the residue is
 * empty and {@see derivation()} exits at its root-anchor gate in silence.
 * MEASURED by a reviewer driving {@see classifyWalkSites()} against nine
 * synthesised guards: EIGHT were skipped silently and only the declared
 * parameter-taint gap reported. So the fail-open is real, and it is in the
 * alphabet rather than in the bucket logic.
 *
 * WHY IT IS PINNED RATHER THAN CLOSED - a decision, with the measurement behind
 * it. The gap is LATENT on this tree: `dirname(__FILE__` appears in 0 files, a
 * `Finder` in 0, `shell_exec('find` in 0, and the three files naming
 * `git ls-files` do so in a comment or a teardown. `dirname(__FILE__` was the one
 * cheap spelling and it IS closed now, in {@see ROOT_ANCHOR}. A SUBPROCESS
 * channel was built and MEASURED before being rejected: on this tree it finds
 * exactly TWO root-anchored subprocess sites and BOTH are false positives -
 * `Cli/BootstrapSkillSkipsTest`'s `exec()` and
 * `Integration/BinSugarcrushDispatchTest`'s `proc_open()` spawn the CLI under
 * test and walk nothing - so it would buy zero real members at the price of two
 * exemption rows written for CORRECT code, which rule 33 names as exactly where
 * the next real offender hides.
 *
 * SO THE LIMITS ARE MADE EXECUTABLE INSTEAD OF CLAIMED.
 * {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()} drives each
 * of those shapes through the shipped classifier and asserts the bucket it
 * ACTUALLY lands in - including the ones that land nowhere at all. An alphabet
 * is coverage (rule 31); a table is how it stops being prose. The day one of
 * these is closed, that test reds and names which.
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
        // RECLASSIFIED FROM local TO tree-wide, and the reason it was local was
        // MEASURED FALSE. copyTree() walks `tests/fixtures/prompt/tree` - inside
        // the package - and this row used to sit in
        // WALKS_A_DIRECTORY_THE_TEST_MADE on the argument that "if a step ever
        // adds a file under that fixture tree, the golden prompt tests are what
        // catch it". They do not, on any tree that has run the suite once:
        // ensureFixtureRepo() caches the copy at
        // `tests/../vendor/prompt-fixture/system-repo` and returns early when
        // its `.git` exists, so copyTree() never runs again. MEASURED by a
        // reviewer: adding a file under that fixture tree left
        // `vendor/bin/phpunit tests/BaseSystemPromptTest.php` at
        // `OK (15 tests, 179 assertions)`, byte-identical to baseline; with the
        // cache cleared FIRST, baseline is `OK (49 tests, 601 assertions)` and
        // the same added file gives `Tests: 49, Assertions: 601, Failures: 1` at
        // BaseSystemPromptTest.php:672. So the delegation was to a guard its own
        // cache masks. By this roster's stated criterion the walk qualifies, and
        // it is declared rather than argued away.
        'BaseSystemPromptTest.php' => 'copyTree() takes a fixture tree inside tests/ as a parameter; the golden guard it delegated to is masked by ensureFixtureRepo()\'s vendor/ cache',
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
     * THE ROW THAT USED TO BE WORTH A SECOND LOOK IS GONE FROM THIS LIST.
     * `BaseSystemPromptTest.php` was licensed here on the argument that its
     * `scandir($source)` copies a fixture tree and that "the golden prompt tests
     * are what catch it" if a step adds a file under that tree. A reviewer
     * MEASURED that false - `ensureFixtureRepo()` caches the copy under
     * `vendor/` and returns early, so the golden guard is masked on every
     * sandbox that has run the suite once - and the file is now in
     * {@see DECLARED_TREE_WIDE_GUARDS}, where its full reason is recorded. Both
     * of its walker sites, the fixture copy AND the `removeTree()` teardown, are
     * covered by that declaration: a file declared tree-wide is IN the roster,
     * so this bucket stops asking about it.
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
     * base class or a trait other than the one channel A already keys on.
     *
     * A SENTENCE HERE USED TO CLAIM that "the accepted direction for anything
     * unreadable is a report, not a pass". IT WAS FALSE, and the class
     * doc-block's self-policing paragraph now carries the correction and the
     * measurement: a shape this alphabet cannot see as a walk produces no site
     * at all and is skipped SILENTLY. That is why every one of the shapes named
     * above is pinned, with the bucket it really lands in, by
     * {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()}.
     *
     * @var list<string>
     */
    private const WALKER_CLASSES = ['recursivedirectoryiterator', 'directoryiterator', 'filesystemiterator'];

    /** @var list<string> */
    private const WALKER_FUNCTIONS = ['glob', 'scandir', 'readdir'];

    /**
     * An expression that names the package root.
     *
     * THE TWO SPELLINGS THIS TREE USES, plus one it does not use yet:
     * `dirname(__DIR__)` at any depth, `__DIR__` concatenated with a path that
     * climbs, and `dirname(__FILE__)` at any depth. The third is here because a
     * reviewer defeated the derivation with it and it costs one alternative;
     * MEASURED, it matches 0 files under `tests/` and `src/` today, so it is a
     * closed door rather than a behaviour change.
     *
     * A bare `__DIR__ . '/fixtures'` is deliberately NOT an anchor - it names a
     * directory beside the test, which is how a fixture is addressed rather than
     * how a source root is. An earlier revision of this paragraph claimed that
     * treating it as a root "put 30 more files in the roster"; that figure was
     * never measured and a reviewer's reproduction produced 0 extra files, so
     * the claim is withdrawn rather than corrected - the REASON stands on what
     * `__DIR__ . '/fixtures'` denotes, and needs no cardinality.
     */
    private const ROOT_ANCHOR = '~dirname\(__DIR__|dirname\(__FILE__|__DIR__\.[\'"]/\.\.~i';

    /**
     * @var array{
     *     roster: list<string>,
     *     unaccounted: array<string, list<string>>,
     *     why: array<string, list<string>>,
     *     testFiles: int,
     *     walkerFiles: int,
     *     candidates: int
     * }|null
     */
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
     * THE ALPHABET'S BLIND SPOTS ARE WHERE THIS FILE SAYS THEY ARE - a table,
     * not a paragraph.
     *
     * WHY THIS TEST EXISTS. The class doc-block used to claim that "the accepted
     * direction for anything unreadable is a report, not a pass". A reviewer
     * drove the shipped classifier against nine synthesised new guards and
     * measured EIGHT of them skipped in SILENCE - not reported - because a shape
     * this alphabet cannot see as a walk produces no site at all, so the residue
     * is empty and nothing reds. The claim was false in the fail-open direction,
     * which is the only direction that matters here.
     *
     * WHAT THIS TEST DOES ABOUT IT. It pins the bucket each shape ACTUALLY lands
     * in, including the ones that land nowhere. So the gap is declared, is
     * checked, and cannot quietly widen; and the day somebody closes one of
     * these, this test reds and names which - which is the correct outcome,
     * because closing one changes the roster and the plan's census set with it.
     *
     * `dirname(__FILE__)` IS IN THE TABLE AS A CLOSED DOOR. It was one of the
     * eight and it now resolves to the package root, because it cost one
     * alternative in {@see ROOT_ANCHOR} and MEASURED 0 live uses, so closing it
     * could not change any verdict on this tree. The other six stay open
     * deliberately; the class doc-block carries the measurement that rejected a
     * subprocess channel.
     *
     * BOTH POLARITIES, THROUGH THE SAME CLASSIFIER (section 16.8 rule 18): two
     * rows must REPORT and six must not, and a classifier that answered the same
     * way for every input would fail one half or the other.
     */
    public function testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre(): void
    {
        $shapes = [
            // Out of reach, SILENTLY - each produces no site this alphabet sees.
            'a collaborator object' => [
                "<?php\nclass P { function go() { \$f = new Finder(); foreach (\$f->files()->in(\\dirname(__DIR__, 2) . '/src') as \$x) {} } }\n",
                'silent',
            ],
            'a subprocess running find' => [
                "<?php\nclass P { function go() { \$o = shell_exec('find ' . \\dirname(__DIR__, 2) . '/src -name \"*.php\"'); } }\n",
                'silent',
            ],
            'a subprocess running git ls-files' => [
                "<?php\nclass P { function go() { \$o = shell_exec('git -C ' . \\dirname(__DIR__, 2) . ' ls-files'); } }\n",
                'silent',
            ],
            'SplFileObject over a manifest' => [
                "<?php\nclass P { function go() { \$h = new \\SplFileObject(\\dirname(__DIR__, 2) . '/src/list.txt'); } }\n",
                'silent',
            ],
            'a literal absolute path' => [
                "<?php\nclass P { function go() { \$r = \\dirname(__DIR__); foreach (glob('/home/x/sugar-crush/src/*.php') as \$y) {} } }\n",
                'silent',
            ],
            'a walker reached through a string' => [
                "<?php\nclass P { function go() { \$w = 'scandir'; \$r = \\dirname(__DIR__, 2) . '/src'; \$o = \$w(\$r); } }\n",
                'silent',
            ],
            // CLOSED: this one was a blind spot and is not one any more.
            'dirname(__FILE__) as the root' => [
                "<?php\nclass P { function go() { foreach (glob(\\dirname(__FILE__, 3) . '/src/*.php') as \$x) {} } }\n",
                'root',
            ],
            // Out of reach but REPORTED - the fail-closed half, and the reason
            // the residue bucket is not decorative.
            'a root computed by reflection' => [
                "<?php\nclass P { function go() { foreach (glob(\\dirname((new \\ReflectionClass(self::class))->getFileName()) . '/*.php') as \$x) {} } }\n",
                'reported',
            ],
            'a root reaching the walker through a parameter' => [
                "<?php\nclass P { function go() { \$this->under(\\dirname(__DIR__, 2) . '/src'); }\n  function under(string \$d) { foreach (scandir(\$d) as \$x) {} } }\n",
                'reported',
            ],
        ];

        $actual = [];
        foreach ($shapes as $label => [$source, $_expected]) {
            $sites = self::classifyWalkSites($source);
            $actual[$label] = $sites['root'] !== []
                ? 'root'
                : ($sites['unresolved'] !== [] ? 'reported' : 'silent');
        }

        $expected = array_map(static fn (array $row): string => $row[1], $shapes);

        $this->assertSame(
            $expected,
            $actual,
            'a shape moved between the alphabet\'s buckets. A row going from "silent" to "reported" '
                . 'or "root" is somebody CLOSING a declared blind spot - good, and it changes the '
                . 'derived roster, so update this table, re-run the roster, and tell the orchestrator '
                . 'that prompt_plan.md section 1.2 action 7b has more members. A row going the other '
                . 'way is the alphabet narrowing and is a regression.',
        );

        // BOTH POLARITIES ARE PRESENT, asserted rather than assumed: without a
        // reporting row this table would pass against a classifier that saw
        // nothing, and without a silent row it would pass against one that
        // reported everything.
        $this->assertContains('reported', $actual, 'no shape in this table reaches the residue bucket, so the fail-closed half is untested');
        $this->assertContains('silent', $actual, 'no shape in this table is silently out of scope, so this table has stopped describing the gap it exists for');
        $this->assertContains('root', $actual, 'no shape in this table resolves, so the classifier may be answering "silent" for everything');
    }

    /**
     * THE POPULATIONS ARE DERIVED AND STRICTLY ORDERED, and no cardinality of
     * them is written down anywhere.
     *
     * WHY. Two population sizes stood as literals in this class's doc-block with
     * no generator attached, and a reviewer who tried eight readings of them
     * reproduced neither. Section 16.8 rule 2 says ship the generator, not the
     * count - so {@see derivation()} returns the three sizes and this test pins
     * the RELATIONSHIP between them, which is the claim that was actually being
     * made: the candidate set is strictly wider than the roster, and strictly
     * narrower than the set of test files that walk anything.
     *
     * A relation survives every merge that adds a test; a literal does not. And
     * a collapsed derivation cannot satisfy it: if any channel returned nothing,
     * one of these strict inequalities fails.
     */
    public function testTheCandidateSetIsStrictlyWiderThanTheRosterAndEveryPopulationIsDerived(): void
    {
        $derived = self::derivation();

        $this->assertGreaterThan(0, \count($derived['roster']), 'the derived roster is empty, so every other assertion in this file is vacuous');
        $this->assertGreaterThan(0, $derived['candidates'], 'no file both walks and names the package root, which cannot be true of this tree');
        $this->assertGreaterThan($derived['candidates'], $derived['walkerFiles'], 'every walking test also names the package root - the candidate gate has stopped narrowing anything');
        $this->assertGreaterThan($derived['walkerFiles'], $derived['testFiles'], 'every test file walks a directory, which would mean the walker alphabet is matching something it should not');

        // The roster is not simply the candidate set: channel A and the declared
        // rows add to it, and the candidates that only walk a temp directory do
        // not. Both directions of that difference must be non-empty, or the two
        // populations have collapsed into one.
        $rosterCount = \count($derived['roster']);
        $this->assertNotSame(
            $rosterCount,
            $derived['candidates'],
            'the roster and the candidate set are now the same size. That means either every '
                . 'candidate qualified or the trait and declared channels stopped contributing; '
                . 'check the channels before believing the coincidence.',
        );
    }

    /**
     * A SHRINK IN EITHER HALF OF THE WALKER ALPHABET IS DETECTED.
     *
     * WHY THIS EXISTS, and it is a real hole a reviewer found in this file's own
     * controls: emptying {@see WALKER_FUNCTIONS} drops the derived roster by
     * eight members and {@see testTheHandMaintainedCensusSetIsASubsetOfTheDerivedRoster()}
     * stays GREEN, because all nine hand-maintained members happen to qualify
     * through a CLASS walker. A nine-member known-positive control cannot see a
     * narrowing that misses all nine - section 16.8 rule 19: count distinct
     * SHAPES, not cases.
     *
     * So this asserts that each half of the alphabet is still carrying at least
     * one member ON ITS OWN. It is derived from `why`, with no file named here:
     * emptying either constant empties one of the two groups and reds this with
     * the group named.
     */
    public function testTheDerivationDetectsAShrinkInEitherHalfOfTheWalkerAlphabet(): void
    {
        $functionOnly = [];
        $classOnly = [];

        foreach (self::derivation()['why'] as $member => $sites) {
            if ($sites === ['TRAIT'] || $sites === ['DECLARED']) {
                continue;
            }
            $viaFunction = false;
            $viaClass = false;
            foreach ($sites as $site) {
                foreach (self::WALKER_FUNCTIONS as $spelling) {
                    $viaFunction = $viaFunction || str_starts_with(strtolower($site), $spelling . '(');
                }
                foreach (self::WALKER_CLASSES as $spelling) {
                    $viaClass = $viaClass || str_starts_with(strtolower($site), $spelling . '(');
                }
            }
            if ($viaFunction && !$viaClass) {
                $functionOnly[] = $member;
            }
            if ($viaClass && !$viaFunction) {
                $classOnly[] = $member;
            }
        }

        $this->assertNotSame(
            [],
            $functionOnly,
            'no roster member qualifies through a FUNCTION walker alone (glob/scandir/readdir). '
                . 'Either WALKER_FUNCTIONS has been emptied or narrowed, or every such guard was '
                . 'rewritten - and the nine-member census control cannot see this, which is why '
                . 'this assertion exists.',
        );
        $this->assertNotSame(
            [],
            $classOnly,
            'no roster member qualifies through a CLASS walker alone '
                . '(RecursiveDirectoryIterator/DirectoryIterator/FilesystemIterator). Same '
                . 'reasoning as the assertion above, for the other half of the alphabet.',
        );
    }

    /**
     * The derived roster, the unaccounted-for sites, WHY each member qualified,
     * and the three population sizes. Computed once.
     *
     * THE POPULATIONS ARE RETURNED RATHER THAN WRITTEN DOWN. Two of them stood
     * as literals in this class's doc-block, with no generator, and did not
     * reproduce for a reviewer who tried eight readings of them. Returning them
     * makes their ORDERING assertable
     * ({@see testTheCandidateSetIsStrictlyWiderThanTheRosterAndEveryPopulationIsDerived()})
     * without pinning a cardinality anywhere - section 16.8 rule 2.
     *
     * `why` maps each member to the walker sites that qualified it, or to the
     * single entry `TRAIT` for a channel-A member. It is what lets
     * {@see testTheDerivationDetectsAShrinkInEitherHalfOfTheWalkerAlphabet()}
     * ask whether both halves of the alphabet are still carrying members, which
     * a nine-member known-positive list cannot see.
     *
     * @return array{
     *     roster: list<string>,
     *     unaccounted: array<string, list<string>>,
     *     why: array<string, list<string>>,
     *     testFiles: int,
     *     walkerFiles: int,
     *     candidates: int
     * }
     */
    private static function derivation(): array
    {
        if (self::$derivation !== null) {
            return self::$derivation;
        }

        $roster = [];
        $why = [];
        foreach (array_keys(self::DECLARED_TREE_WIDE_GUARDS) as $declared) {
            $roster[] = $declared;
            $why[$declared] = ['DECLARED'];
        }
        $unaccounted = [];
        $testFiles = 0;
        $walkerFiles = 0;
        $candidates = 0;

        foreach (self::everyTestFile() as $relative => $absolute) {
            if (!str_ends_with($relative, 'Test.php')) {
                continue;
            }
            $relative = str_replace('\\', '/', $relative);
            $source = (string) file_get_contents($absolute);
            $testFiles++;

            $sites = self::classifyWalkSites($source);
            if ($sites['root'] !== [] || $sites['unresolved'] !== []) {
                $walkerFiles++;
            }

            if (self::usesTheSharedWalker($source)) {
                $roster[] = $relative;
                $why[$relative] ??= ['TRAIT'];

                continue;
            }
            if (preg_match(self::ROOT_ANCHOR, self::flatten($source)) !== 1) {
                // A file that never names the package root cannot walk it.
                continue;
            }
            if ($sites['root'] === [] && $sites['unresolved'] === []) {
                // Names the root but performs no walk this alphabet can see.
                // Silently out of scope, and that gap is pinned by
                // testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre().
                continue;
            }
            $candidates++;

            if ($sites['root'] !== []) {
                $roster[] = $relative;
                $why[$relative] ??= $sites['root'];

                continue;
            }

            if (isset(self::DECLARED_TREE_WIDE_GUARDS[$relative])) {
                continue;
            }

            $licensed = self::WALKS_A_DIRECTORY_THE_TEST_MADE[$relative] ?? [];
            $left = array_values(array_diff($sites['unresolved'], $licensed));
            if ($left !== []) {
                $unaccounted[$relative] = $left;
            }
        }

        $roster = array_values(array_unique($roster));
        sort($roster);
        ksort($unaccounted);
        ksort($why);

        return self::$derivation = [
            'roster' => $roster,
            'unaccounted' => $unaccounted,
            'why' => $why,
            'testFiles' => $testFiles,
            'walkerFiles' => $walkerFiles,
            'candidates' => $candidates,
        ];
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
