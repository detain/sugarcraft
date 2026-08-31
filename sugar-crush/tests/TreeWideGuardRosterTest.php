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
 *  - CHANNEL A: the file names a `tests/` HELPER that itself carries a
 *    root-anchored walk. Sound by construction, since the helper IS the walk.
 *    THE HELPER SET IS DERIVED, not named: {@see walkingHelperNames()} runs every
 *    non-`*Test.php` file under `tests/` through the same
 *    {@see classifyWalkSites()} that grades the tests, and keeps the declared
 *    class/trait names of those whose walk resolves to a source root.
 *    WHAT THIS SAID UNTIL NOW: channel A was one literal name,
 *    `str_ends_with($token, 'TestFileWalkTrait')`. WHAT IS TRUE: that is a
 *    hand-written name inside a file whose whole subject is that a hand-written
 *    name inherits its own omissions, and it fails OPEN - a test that walks the
 *    tree only through some other helper is missed by channel A AND cannot land
 *    in the residue bucket, because it has no walker call site of its own to be
 *    unaccounted for. HOW MEASURED: driving every non-`*Test.php` file under
 *    `tests/` through the shipped classifier finds TWO helpers with a
 *    root-anchored walk - `Support/TestFileWalkTrait.php` (over `tests/`) and
 *    `Tools/BuiltInToolCorpus.php` (over `src/`) - and the second was invisible
 *    to the literal. MEASURED effect on this tree: the derived roster goes from
 *    65 to 67, gaining `Providers/ToolSchemaEncodingTest.php` and
 *    `Tools/BuiltInToolTest.php`, and three more files change only the REASON
 *    they were already members. I PREDICTED THREE NEW MEMBERS AND GOT TWO, and
 *    the third is worth the sentence: `Context/RepoMapBlockTest.php` came off a
 *    `/usr/bin/grep -rln BuiltInToolCorpus` and names it only inside a
 *    `{@see}`, and only as the DIFFERENT class `BuiltInToolCorpusTest`. So the
 *    prediction was made by exactly the grep this method exists to replace, and
 *    the token matcher rejecting it is the mechanism working. Pinned by
 *    {@see testTheHelperSetChannelAKeysOnIsDerivedFromTheTreeItself()}.
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
 * {@see testTheRosterAndTheCandidateSetOverlapWithNeitherContainingTheOther()},
 * which reads them off {@see derivation()} instead of out of a sentence. A
 * superset is not a roster and is not shipped as one.
 *
 * AND THE WORD "SUPERSET" IS WRONG, which is worth its own sentence because it
 * stood in this file as "the candidate set is strictly wider than the roster".
 * MEASURED: the two sets OVERLAP and neither contains the other - 11 roster
 * members are outside the candidate set (every channel-A member, which never
 * reaches the candidate counter) and 27 candidates are outside the roster (the
 * ones whose only walks are over a directory the test made). The CARDINALITIES
 * are ordered - 67 < 83 < 181 < 440 - and that is a different and weaker claim
 * than containment. Both are now asserted for what they are.
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
 * WHAT IT COVERS - AND THIS SENTENCE HAS NOW BEEN WRONG TWICE, so it is stated
 * as the invariant the code actually enforces rather than as the one it would be
 * nice to have. It used to say: "every walker call site in a file that names the
 * package root lands in one of three buckets ... and anything else reds". THAT IS
 * FALSE, and here is the shape of the falsehood: {@see derivation()} decides
 * MEMBERSHIP first, and the moment a file is a member it stops asking about that
 * file's remaining sites - once through channel A, once when any site in the file
 * resolves to the root, and once on a {@see DECLARED_TREE_WIDE_GUARDS} row.
 * MEASURED by driving the shipped classifier over `everyTestFile()`: 12
 * unresolved walker call sites in 9 files are passed over that way
 * (1 via channel A, 5 via a resolved sibling site, 6 via a declared row).
 *
 * WHAT IS TRUE, and it is the property that carries the weight: EVERY FILE with
 * an unresolved walker site is either IN THE ROSTER or has every one of those
 * sites licensed by name. MEASURED: all 9 of those files are roster members, and
 * that is not a coincidence - each of the three early returns fires BECAUSE the
 * file was just added. A site passed over in a file that is already a member
 * cannot change a roster verdict, because the only verdict a site can move is
 * whether its file is a member, and that is already settled.
 * {@see testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed()} is
 * that invariant as an assertion, over both populations, so the claim above is
 * checked rather than argued. What is genuinely LOST is per-site licence
 * discipline inside member files: an unresolved walk added to a member file
 * needs no row and gets no reader. That is a real gap, it is declared here, and
 * it is bounded - it cannot reach roster membership.
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
 * synthesised guards, which is how the fail-open was found: it is in the
 * alphabet rather than in the bucket logic. THAT REVIEWER'S TALLY WAS EIGHT
 * SILENT AND ONE REPORTED, AND IT IS OFF BY ONE. Re-measured by shipping the
 * same nine shapes as an executable table in
 * {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()}: SIX are
 * silent, TWO are reported, and ONE now resolves to the package root because
 * closing it cost one alternative in {@see ROOT_ANCHOR}. The shape the tally
 * mis-filed is the root computed by reflection - `glob()` IS in the alphabet
 * there, so the site is seen and the ROOT is what cannot be resolved, which is
 * precisely the residue bucket doing its job. Before that one door was closed
 * the split was seven silent and two reported. The direction of the finding
 * stands either way; the cardinality is the table's, not the paragraph's.
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
     * IT ADDS A FILE, AND IT ALSO EXEMPTS THAT FILE'S REMAINING SITES - both
     * halves, because the sentence here used to claim only the first. It said
     * "THIS IS NOT AN EXEMPTION LIST ... a wrong row here costs a test run rather
     * than a missed guard." MEASURED: a row here does remove something from a
     * verdict. {@see derivation()} returns as soon as a declared row is found, so
     * that file's unresolved sites are never licensed or reported - 6 sites in 4
     * files at this commit, and the key is the FILE, so the number is unbounded
     * in future.
     *
     * WHY THE ROW IS STILL SOUND, stated as the bound rather than as a denial: a
     * row here puts the file IN the roster, and roster membership is the only
     * verdict a walker site can move. So the exemption cannot cost a missed
     * guard for THIS file - it costs per-site licence discipline inside it, which
     * is a smaller and declared loss.
     * {@see testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed()}
     * asserts exactly that bound, so if a row here ever stopped implying
     * membership the assertion reds.
     *
     * WHY THE KEY IS THE FILE AND NOT FILE-PLUS-EXPRESSION, unlike
     * {@see WALKS_A_DIRECTORY_THE_TEST_MADE} (rule 34: key an exemption on its
     * scope). The claim a row here makes is about the FILE - "this whole file is
     * a tree-wide guard for a reason the alphabet cannot see" - so the file IS
     * the scope. The local bucket's claim is about one expression, so its key is
     * the expression.
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
     * A spelling the taint resolver is willing to look for at a use site.
     *
     * WHY THIS EXISTS AND WHY IT IS A FIX RATHER THAN A TIGHTENING.
     * {@see isRootAnchored()} used to answer with `str_contains()` over a bare
     * list of names, which fails OPEN in two independent ways.
     *
     * FIRST, SUBSTRING. A taint name of `$t` is a substring of `$this`, so ANY
     * argument mentioning `$this` resolved to "the package root" in a file where
     * some unrelated method happened to assign `$t = dirname(__DIR__, 2) .
     * '/src'`. MEASURED through the shipped classifier before the fix:
     * `glob($this->tempDir . '/*')` came back in the `root` bucket - the exact
     * shape the negative control in
     * {@see testTheDerivationIncludesItselfAndTheTraitsOtherConsumers()} declares
     * impossible. Matching is now bounded on both sides, so `$t` cannot answer
     * for `$this`.
     *
     * SECOND, SHAPE. {@see rootAnchoredNames()} takes an assignment TARGET as
     * written, so array-append and index targets arrive as `$calls[]`,
     * `$cases[$name]` and truncated forms like `$aliases]`. MEASURED over all of
     * `tests/`: 8,264 well-formed spellings and 481 distinct malformed ones. A
     * malformed spelling can only ever match by accident, so it is not consulted.
     *
     * THE SHAPES ALLOWED ARE THE ONES {@see spellingsOf()} PRODUCES, and I
     * measured that list rather than guessing it: a plain local `$root`, a
     * property read `$this->srcDir`, a class constant `self::LIB_ROOT` or
     * `static::LIB_ROOT`, and - the one a narrower guess would have deleted
     * outright - the zero-argument helper call `self::helper(`, `$this->helper(`
     * or `static::helper(`, trailing paren included, which is how the fourth
     * taint rule spells its subject. A first draft of this pattern omitted that
     * form and would have silently disabled an entire taint rule; the census
     * above is what caught it.
     *
     * MEASURED EFFECT ON THIS TREE: none. roster 67, candidates 83,
     * walkerFiles 181, testFiles 440, unaccounted 0 - identical before and after.
     * The fail-open was LATENT, and both polarities are now pinned by
     * {@see testTheRootTaintResolverMatchesAtNameBoundariesAndIgnoresMalformedSpellings()}.
     */
    private const NAME_SPELLING = '~^(\\$[A-Za-z_][A-Za-z0-9_]*|(\\$this->|self::|static::)[A-Za-z_][A-Za-z0-9_]*\\(?)$~';

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
     *     candidateFiles: list<string>,
     *     consultedResidue: list<string>,
     *     unresolvedByFile: array<string, list<string>>,
     *     testFiles: int,
     *     walkerFiles: int,
     *     candidates: int
     * }|null
     */
    private static ?array $derivation = null;

    /** @var array<string, string>|null */
    private static ?array $helpers = null;

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
     * THE FAIL-CLOSED HALF: no file that walks the tree becomes a NON-member
     * silently.
     *
     * THE DOMAIN OF THIS TEST IS NARROWER THAN ITS OLD DOC-BLOCK CLAIMED, and
     * the difference matters enough to write out. It used to say "Every walker
     * call site in a test file that also names the package root must be one of
     * ... Anything else reds here". MEASURED FALSE: {@see derivation()} settles
     * MEMBERSHIP first and stops asking about a member file's remaining sites, so
     * 12 unresolved sites in 9 files never reach this test - and all 9 files are
     * members, which is why they never reach it.
     *
     * WHAT THIS TEST REALLY ASSERTS: for a file that is NOT already a roster
     * member, every unresolved walker site must be covered by a declared local
     * row keyed on this exact file AND this exact expression, or it reds here and
     * prints itself. That is the direction that can cost a guard, and it is the
     * one this mechanism turns from a discovery into a test failure. The wider
     * per-file invariant is
     * {@see testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed()}.
     *
     * THE DOMAIN IS NARROWED ON PURPOSE to files that name the package root at
     * all: a test that never mentions the root cannot walk it, so requiring a
     * declared row for every `glob()` in `tests/` would cost a row for every
     * walking test in the tree instead of one per unresolved SITE.
     * {@see derivation()} returns the walking-file population and
     * {@see WALKS_A_DIRECTORY_THE_TEST_MADE} is the residue, so that ratio is
     * readable without being typed here.
     *
     * TWO CARDINALITIES USED TO STAND IN THAT SENTENCE - "182 rows of noise
     * instead of 37 of signal" - AND BOTH WERE STALE BY THE TIME ANYONE READ
     * THEM. RE-MEASURED: the walking-file population is 181, and the residue is
     * 27 files carrying 35 sites. That is the defect this class's doc-block
     * declares four paragraphs earlier, recurring inside the same file, which is
     * a fair measure of how durable it is.
     *
     * The narrowing is a NECESSARY condition and therefore sound in the
     * fail-closed direction - the residual gap is a root arriving from another
     * file, which the class doc-block names.
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
     * measured most of them skipped in SILENCE - not reported - because a shape
     * this alphabet cannot see as a walk produces no site at all, so the residue
     * is empty and nothing reds. The claim was false in the fail-open direction,
     * which is the only direction that matters here.
     *
     * THAT REVIEWER SAID EIGHT OF THE NINE WERE SILENT, AND THE TABLE BELOW SAYS
     * SEVEN WERE. The disagreement is one row: the root computed by reflection
     * is REPORTED, because `glob()` is in the alphabet and it is the ROOT that
     * cannot be resolved. This test is the reason that is now a fact rather than
     * a recollection - it drives the shapes rather than describing them, so the
     * figure below is whatever the classifier actually does.
     *
     * WHAT THIS TEST DOES ABOUT IT. It pins the bucket each shape ACTUALLY lands
     * in, including the ones that land nowhere. So the gap is declared, is
     * checked, and cannot quietly widen; and the day somebody closes one of
     * these, this test reds and names which - which is the correct outcome,
     * because closing one changes the roster and the plan's census set with it.
     *
     * `dirname(__FILE__)` IS IN THE TABLE AS A CLOSED DOOR. It was one of the
     * seven silent rows and it now resolves to the package root, because it cost
     * one alternative in {@see ROOT_ANCHOR} and MEASURED 0 live uses, so closing
     * it could not change any verdict on this tree. The other six stay open
     * deliberately; the class doc-block carries the measurement that rejected a
     * subprocess channel.
     *
     * BOTH POLARITIES, THROUGH THE SAME CLASSIFIER (section 16.8 rule 18): of the
     * nine rows two must REPORT, six must be SILENT and one must RESOLVE, and a
     * classifier that answered the same way for every input would fail two of
     * those three assertions.
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
     * THE POPULATIONS ARE DERIVED; THEIR SIZES ARE ORDERED; AND THE ROSTER IS
     * NOT A SUBSET OF THE CANDIDATE SET IN EITHER DIRECTION.
     *
     * WHY THE SIZES ARE A RELATION AND NOT LITERALS. Two population sizes stood
     * as literals in this class's doc-block with no generator attached, and a
     * reviewer who tried eight readings of them reproduced neither. Rule 2 says
     * ship the generator, not the count - so {@see derivation()} returns the
     * sizes and this test pins their ordering. A relation survives every merge
     * that adds a test; a literal does not.
     *
     * WHAT THIS TEST USED TO CLAIM, AND IT WAS TWO SEPARATE OVERSTATEMENTS.
     * Its name and doc-block said "the candidate set is STRICTLY WIDER than the
     * roster", which is a containment claim. MEASURED FALSE: the sets overlap and
     * neither contains the other - 11 roster members are outside the candidate
     * set (the channel-A members, which return before the candidate counter) and
     * 27 candidates are outside the roster. AND the assertion that shipped for it
     * was `assertNotSame($rosterCount, $candidates)`, satisfied by any two
     * different integers, which is not the claim in either form.
     *
     * SO BOTH HALVES ARE NOW ASSERTED FOR WHAT THEY ARE: the cardinalities are
     * ordered, and the two SET DIFFERENCES are separately non-empty, which is the
     * structural fact the old sentence was reaching for. Neither is a literal:
     * both differences are computed from the two derived lists.
     *
     * A COLLAPSED DERIVATION CANNOT SATISFY THIS. If channel A died the first
     * difference empties; if the candidate gate stopped narrowing the second
     * empties; if any population returned nothing an inequality fails.
     */
    public function testTheRosterAndTheCandidateSetOverlapWithNeitherContainingTheOther(): void
    {
        $derived = self::derivation();
        $roster = $derived['roster'];
        $candidates = $derived['candidateFiles'];

        $this->assertGreaterThan(0, \count($roster), 'the derived roster is empty, so every other assertion in this file is vacuous');
        $this->assertGreaterThan(0, $derived['candidates'], 'no file both walks and names the package root, which cannot be true of this tree');
        $this->assertGreaterThan($derived['candidates'], $derived['walkerFiles'], 'every walking test also names the package root - the candidate gate has stopped narrowing anything');
        $this->assertGreaterThan($derived['walkerFiles'], $derived['testFiles'], 'every test file walks a directory, which would mean the walker alphabet is matching something it should not');
        $this->assertSame(\count($candidates), $derived['candidates'], 'the candidate list and the candidate counter disagree, so one of them is not measuring the candidate set');

        // NEITHER SET CONTAINS THE OTHER, and both directions are named on
        // failure. This is the claim the old "strictly wider" sentence was
        // making badly.
        $rosterOnly = array_values(array_diff($roster, $candidates));
        $candidatesOnly = array_values(array_diff($candidates, $roster));

        $this->assertNotSame(
            [],
            $rosterOnly,
            'every roster member is also a candidate, so channel A and the declared rows have '
                . 'stopped contributing anything the candidate gate does not already find - check '
                . 'those two channels before believing the coincidence',
        );
        $this->assertNotSame(
            [],
            $candidatesOnly,
            'every candidate is a roster member, so the candidate gate has stopped narrowing: a '
                . 'walk over a directory the test just made is being read as a walk over the '
                . 'package, which is the file-level co-occurrence failure this derivation exists to '
                . 'avoid',
        );
    }

    /**
     * THE INVARIANT THAT ACTUALLY CARRIES THE WEIGHT: a file with an unresolved
     * walk is a roster MEMBER, or every one of its unresolved sites is licensed
     * by name.
     *
     * WHY THIS TEST EXISTS AND WHAT IT REPLACES. Two doc-blocks in this file
     * claimed that every walker call SITE in a root-naming file is bucketed or
     * reds. MEASURED FALSE: {@see derivation()} settles MEMBERSHIP first and then
     * stops asking about that file's remaining sites, at three early returns -
     * channel A, a sibling site that resolves, and a
     * {@see DECLARED_TREE_WIDE_GUARDS} row - passing over 12 unresolved sites in
     * 9 files at this commit.
     *
     * The claim was wrong; the MECHANISM is not, and this is the difference. The
     * only verdict a walker site can move is whether ITS FILE is a roster member.
     * Each of those three early returns fires precisely BECAUSE the file has just
     * been made a member, so a site passed over there cannot change any verdict.
     * That is an invariant, so it is asserted rather than explained: for every
     * file with an unresolved site, membership OR a full set of licences.
     *
     * IT FAILS IF THE REASONING EVER STOPS HOLDING. Add a fourth early return
     * that does not add the file to the roster, or make a declared row stop
     * implying membership, and this reds naming the file - which is the only way
     * a reader finds out that the bound the two corrected doc-blocks now promise
     * has been broken.
     *
     * BOTH POPULATIONS ARE ASSERTED NON-EMPTY, or the test passes vacuously on a
     * tree where nothing is unresolved, or where every unresolved file happens to
     * be a member and the licence half is never exercised.
     */
    public function testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed(): void
    {
        $derived = self::derivation();
        $roster = $derived['roster'];

        $this->assertNotSame(
            [],
            $derived['unresolvedByFile'],
            'no test file in the tree has an unresolved walker site, so this test ranges over nothing '
                . 'and the classifier is resolving everything - which would itself be the finding',
        );

        $members = [];
        $licensedOnly = [];
        $broken = [];

        foreach ($derived['unresolvedByFile'] as $file => $unresolved) {
            if (\in_array($file, $roster, true)) {
                $members[] = $file;

                continue;
            }
            $licensed = self::WALKS_A_DIRECTORY_THE_TEST_MADE[$file] ?? [];
            $left = array_values(array_diff($unresolved, $licensed));
            if ($left === []) {
                $licensedOnly[] = $file;

                continue;
            }
            $broken[] = $file . ' => ' . implode(' ; ', $left);
        }

        $this->assertSame(
            [],
            $broken,
            "these files have an unresolved walker site, are NOT roster members, and are not fully "
                . "licensed:\n"
                . implode("\n", array_map(static fn (string $row): string => '  - ' . $row, $broken))
                . "\n\nThat is the one shape this whole file exists to make impossible: a walk nobody "
                . 'can place, in a file nobody runs when the tree changes. Either the walk resolves, '
                . 'or the file is declared tree-wide, or the site gets a row in '
                . 'WALKS_A_DIRECTORY_THE_TEST_MADE keyed on this file AND this expression.',
        );

        // NOT VACUOUS, in both directions. The first group is the one the three
        // early returns produce; the second is the one the residue check
        // produces. If either is empty this test has stopped exercising half of
        // the invariant it states.
        $this->assertNotSame(
            [],
            $members,
            'no file with an unresolved walk is a roster member, so the three early returns in '
                . 'derivation() are no longer reachable and the bound this test asserts is untested',
        );
        $this->assertNotSame(
            [],
            $licensedOnly,
            'no file with an unresolved walk is a non-member covered by licences, so the residue '
                . 'bucket is never the thing that saves a file and the licence half is untested',
        );
    }

    /**
     * EVERY LICENSED RESIDUE ROW STILL MATCHES A LIVE SITE - THE REMOVAL HALF.
     *
     * WHY THIS EXISTS, and it is the half of roster discipline that bites. Every
     * other assertion in this file is about what a change ADDS: a new walk must
     * be classified or declared. {@see WALKS_A_DIRECTORY_THE_TEST_MADE} fails in
     * the other direction. Repair a walk - anchor it properly, delete the test,
     * move it behind a helper - and its licensed row keeps passing, because the
     * row is only ever CONSULTED when a matching site shows up. A row for code
     * that no longer exists is section 16.8 rule 33's licence: it is written for
     * correct code, it is invisible while green, and it is exactly the cover the
     * next real offender needs, because the next walk added to that file under
     * that same expression is waved through by a row nobody remembers granting.
     *
     * THE ROUTES ARE ENUMERATED BY THE DERIVATION, NOT BY THIS DOC-BLOCK, and
     * that is the correction. This paragraph used to say "TWO WAYS A ROW GOES
     * DEAD AND BOTH ARE CHECKED" - the file being gone or having become a
     * channel-A member, and the expression no longer appearing - and the loop
     * re-derived from {@see classifyWalkSites()} to decide. MEASURED FALSE, and
     * false in the fail-open direction: {@see derivation()} also returns early
     * when any sibling site in the file resolves to the root, and again on a
     * {@see DECLARED_TREE_WIDE_GUARDS} row, so a licensed row on such a file is
     * never consulted while `classifyWalkSites()` still happily lists its
     * expression as unresolved. Demonstrated by adding a root-anchored `glob()`
     * helper to a file that carries a licensed row: the row went permanently
     * unconsulted and this test stayed green.
     *
     * SO THE LOOP NOW ASKS THE DERIVATION which files actually reached the
     * residue check - `consultedResidue`, recorded at the one place the residue
     * is read. That is route-agnostic: it covers all four routes known today and
     * any fifth somebody adds later, without this doc-block having to be right
     * about the list. A row whose file is not in that set is dead however it got
     * there.
     *
     * MEASURED at this commit: 0 dead rows, out of 27 files and 35 sites.
     *
     * NOT VACUOUS: the constant must be non-empty, or every assertion below
     * ranges over nothing.
     */
    public function testEveryLicensedResidueRowStillMatchesALiveWalkSite(): void
    {
        $this->assertNotSame(
            [],
            self::WALKS_A_DIRECTORY_THE_TEST_MADE,
            'the licensed-residue constant is empty, so this test ranges over nothing and the '
                . 'unaccounted-for test has nothing left to consult',
        );

        $everyFile = [];
        foreach (self::everyTestFile() as $relative => $absolute) {
            $everyFile[str_replace('\\', '/', $relative)] = $absolute;
        }

        $consulted = self::derivation()['consultedResidue'];

        $dead = [];
        foreach (self::WALKS_A_DIRECTORY_THE_TEST_MADE as $file => $expressions) {
            if (!isset($everyFile[$file])) {
                $dead[] = $file . ' => the file no longer exists';

                continue;
            }

            if (!\in_array($file, $consulted, true)) {
                $dead[] = $file . ' => derivation() never consults this file\'s residue, so its '
                    . \count($expressions) . ' licensed row(s) can never be read. It became a '
                    . 'channel-A member, or a sibling walk in it now resolves to the package root, '
                    . 'or it gained a DECLARED_TREE_WIDE_GUARDS row - every one of those returns '
                    . 'before the residue.';

                continue;
            }

            $source = (string) file_get_contents($everyFile[$file]);
            $unresolved = self::classifyWalkSites($source)['unresolved'];
            foreach ($expressions as $expression) {
                if (!\in_array($expression, $unresolved, true)) {
                    $dead[] = $file . ' => ' . $expression;
                }
            }
        }

        $this->assertSame(
            [],
            $dead,
            "these licensed residue rows no longer match any live walk site:\n"
                . implode("\n", array_map(static fn (string $row): string => '  - ' . $row, $dead))
                . "\n\nA row written for code that no longer walks is a standing licence (rule 33): it "
                . 'passes forever and waves through the next walk that happens to land on the same '
                . 'file and expression. Delete the row in the same change-set as the repair. If the '
                . 'file became a channel-A member, that is the whole reason the row is dead and the '
                . 'row goes with it.',
        );
    }

    /**
     * THE ROOT TAINT MATCHES AT NAME BOUNDARIES, AND IGNORES SPELLINGS THAT ARE
     * NOT NAMES.
     *
     * BOTH POLARITIES THROUGH THE SHIPPED CLASSIFIER (rule 18), because the
     * fix's whole risk is over-correction: a resolver that stopped resolving
     * would move members out of the roster and every subset assertion in this
     * file would still pass, since the nine hand-maintained members resolve
     * through channels this test does not touch.
     *
     * The false-positive shape is the one MEASURED to defeat the old resolver -
     * `$t` answering for `$this` - and the true-positive shape is the same short
     * name used honestly. A resolver that answered `unresolved` for everything
     * fails the second assertion; one that answered `root` for everything fails
     * the first.
     *
     * THE MALFORMED HALF IS ASSERTED ON THE PREDICATE ITSELF rather than through
     * a walk, because the malformed spellings come out of `rootAnchoredNames()`
     * and cannot be written into a fixture by hand: `$calls[]` is what an
     * array-append TARGET looks like, and no use site is ever spelled that way.
     */
    public function testTheRootTaintResolverMatchesAtNameBoundariesAndIgnoresMalformedSpellings(): void
    {
        // FALSE POSITIVE, and it is the one that was live: an unrelated method
        // taints `$t`, and the walk mentions `$this`.
        $substringTrap = "<?php\nclass P {\n"
            . "  private function unrelated(): string { \$t = \\dirname(__DIR__, 2) . '/src'; return \$t; }\n"
            . "  private function walk(): array { return (array) glob(\$this->tempDir . '/*'); }\n"
            . "}\n";
        $trapped = self::classifyWalkSites($substringTrap);

        $this->assertSame(
            [],
            $trapped['root'],
            'a walk over $this->tempDir resolved to the PACKAGE ROOT because an unrelated method in '
                . 'the same file assigned a root to $t, and "$t" is a substring of "$this". That is '
                . 'the substring fail-open NAME_SPELLING and the bounded match exist to close, and it '
                . 'silently promotes temp-directory walks into the roster.',
        );
        $this->assertSame(
            ["glob(\$this->tempDir.'/*')"],
            $trapped['unresolved'],
            'the trapped walk is not being REPORTED either, so closing the substring hole turned a '
                . 'false positive into a silent pass rather than into a residue entry',
        );

        // TRUE POSITIVE, same short name, used honestly. Without this the
        // assertions above would pass against a resolver that resolves nothing.
        $honest = "<?php\nclass P {\n"
            . "  private function walk(): array { \$t = \\dirname(__DIR__, 2) . '/src'; return (array) glob(\$t . '/*.php'); }\n"
            . "}\n";
        $resolved = self::classifyWalkSites($honest);

        $this->assertSame(
            ["glob(\$t.'/*.php')"],
            $resolved['root'],
            'a walk whose argument IS the tainted short name no longer resolves to the package root, '
                . 'so the boundary match has over-corrected and the resolver has stopped resolving',
        );
        $this->assertSame([], $resolved['unresolved']);

        // THE SPELLING PREDICATE, both ways. The malformed entries are real
        // output of rootAnchoredNames(), not invented shapes.
        foreach (['$root', '$this->srcDir', 'self::LIB_ROOT', 'static::LIB_ROOT', 'self::libRoot(', '$this->libRoot('] as $wellFormed) {
            $this->assertSame(
                1,
                preg_match(self::NAME_SPELLING, $wellFormed),
                $wellFormed . ' is a spelling spellingsOf() produces and NAME_SPELLING rejects it, so '
                    . 'a whole taint rule has been disabled - the zero-argument helper form, trailing '
                    . 'paren included, is the one a narrower pattern drops',
            );
        }
        foreach (['$calls[]', '$cases[$name]', '$aliases]', ']', '', '$t->'] as $malformed) {
            $this->assertSame(
                0,
                preg_match(self::NAME_SPELLING, $malformed),
                var_export($malformed, true) . ' is accepted as a name to look for at a use site. It '
                    . 'is an assignment TARGET or a parse fragment, never a use, so it can only ever '
                    . 'match by accident.',
            );
        }
    }

    /**
     * CHANNEL A'S ALPHABET IS DERIVED FROM THE TREE, AND ON TOKENS RATHER THAN
     * TEXT.
     *
     * WHY THIS EXISTS. Channel A used to be one literal name inside a file whose
     * whole subject is that a hand-written name inherits its own omissions - the
     * defect one method over. {@see walkingHelperNames()} replaces the literal,
     * and this pins the three properties that make the replacement worth having
     * rather than merely different.
     *
     * (1) IT FINDS MORE THAN THE LITERAL DID. At least two distinct helpers must
     *     be carrying roster members. Rule 19: one helper carrying eleven members
     *     is one SHAPE, and a derivation that had silently collapsed back to the
     *     single trait would satisfy every other assertion in this file.
     * (2) BOTH POLARITIES THROUGH THE SAME MATCHER (rule 18). A helper that walks
     *     must be in the alphabet; a `tests/` helper that walks nothing must not.
     *     `Support/TokenFunctionRanges.php` is the known negative and it is a real
     *     file, not a synthetic one: it reads token streams and opens no
     *     directory.
     * (3) THE STRING TRAP IS CLOSED, and it is not hypothetical - it is the
     *     reason this matcher reads tokens. THIS FILE would match a text search
     *     for `BuiltInToolCorpus`, purely because
     *     {@see HAND_MAINTAINED_CENSUS_SET} holds the literal
     *     `'Tools/BuiltInToolCorpusTest.php'`, and matching text would put every
     *     file that merely NAMES a roster entry into the roster. Asserted in both
     *     directions against synthesised sources, so the assertion cannot be
     *     satisfied by a matcher that answers `null` for everything.
     */
    public function testTheHelperSetChannelAKeysOnIsDerivedFromTheTreeItself(): void
    {
        $helpers = self::walkingHelperNames();

        // (2) The positive half: the two helpers this tree actually has. Named,
        // because a derivation that found some OTHER pair would be answering a
        // different question and should say so out loud.
        $this->assertArrayHasKey(
            'testfilewalktrait',
            $helpers,
            'the shared whole-tests/ walker is no longer derived as a walking helper, so channel A '
                . 'has stopped seeing the mechanism this whole file is built on',
        );
        $this->assertArrayHasKey(
            'builtintoolcorpus',
            $helpers,
            'the src/ tool corpus is no longer derived as a walking helper. It walks src/ through a '
                . 'RecursiveDirectoryIterator, so either that walk was rewritten - in which case say '
                . 'so here - or the classifier narrowed.',
        );

        // (2) The negative half, through the same derivation: a tests/ helper
        // that reads token streams and opens no directory must NOT be an
        // alphabet entry. Without this, `walkingHelperNames()` returning every
        // helper would pass the two assertions above.
        $this->assertArrayNotHasKey(
            'tokenfunctionranges',
            $helpers,
            'a tests/ helper that performs no directory walk has been promoted into channel A\'s '
                . 'alphabet, which would make every file that names it a roster member on no evidence',
        );

        // (1) At least two DISTINCT helpers carry members. Derived from `why`,
        // with no file named.
        $carrying = [];
        foreach (self::derivation()['why'] as $sites) {
            if (\count($sites) === 1 && str_starts_with($sites[0], 'HELPER:')) {
                $carrying[substr($sites[0], \strlen('HELPER:'))] = true;
            }
        }
        $this->assertGreaterThan(
            1,
            \count($carrying),
            'only one walking helper is carrying roster members (' . implode(', ', array_keys($carrying))
                . '), so channel A has collapsed back to the single hardcoded trait it replaced. '
                . 'Rule 19: that is one shape, and one shape cannot show a narrowing.',
        );

        // (3) The string trap, both ways, against the real matcher.
        $inAString = "<?php\nnamespace X;\nclass P { private const R = ['Tools/BuiltInToolCorpusTest.php']; }\n";
        $inADocBlock = "<?php\nnamespace X;\n/** {@see \\A\\B\\BuiltInToolCorpus} */\nclass P {}\n";
        $asAUse = "<?php\nnamespace X;\nuse SugarCraft\\Crush\\Tests\\Tools\\BuiltInToolCorpus;\nclass P { function go() { BuiltInToolCorpus::instances(); } }\n";

        $this->assertNull(
            self::walkingHelperUsedIn($inAString),
            'a source that names a helper only inside a string literal is being read as a consumer '
                . 'of it. This file is the file that would break: its own roster constants hold '
                . 'those names as strings.',
        );
        $this->assertNull(
            self::walkingHelperUsedIn($inADocBlock),
            'a source that names a helper only in a doc-block is being read as a consumer of it - '
                . 'the matcher is reading comments',
        );
        $this->assertSame(
            'builtintoolcorpus',
            self::walkingHelperUsedIn($asAUse),
            'a source that imports and calls a walking helper is not detected, so channel A is '
                . 'blind in the one direction it exists for',
        );

        // And the exact-segment rule: a longer name that merely ENDS with a
        // helper's name is a different class and must not answer for it.
        $lookalike = "<?php\nnamespace X;\nclass P { function go() { \\X\\MyBuiltInToolCorpus::instances(); } }\n";
        $this->assertNull(
            self::walkingHelperUsedIn($lookalike),
            'a class whose name merely ends with a helper\'s name is answering for that helper, so '
                . 'the matcher is testing a suffix rather than the last namespace segment',
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
            if (\count($sites) === 1 && ($sites[0] === 'DECLARED' || str_starts_with($sites[0], 'HELPER:'))) {
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
     * ({@see testTheRosterAndTheCandidateSetOverlapWithNeitherContainingTheOther()})
     * without pinning a cardinality anywhere - section 16.8 rule 2.
     *
     * `why` maps each member to the walker sites that qualified it, or to the
     * single entry `HELPER:<name>` for a channel-A member - NAMING the helper
     * rather than just the channel, so
     * {@see testTheHelperSetChannelAKeysOnIsDerivedFromTheTreeItself()} can ask
     * whether more than one helper is carrying members. It is what lets
     * {@see testTheDerivationDetectsAShrinkInEitherHalfOfTheWalkerAlphabet()}
     * ask whether both halves of the alphabet are still carrying members, which
     * a nine-member known-positive list cannot see.
     *
     * @return array{
     *     roster: list<string>,
     *     unaccounted: array<string, list<string>>,
     *     why: array<string, list<string>>,
     *     candidateFiles: list<string>,
     *     consultedResidue: list<string>,
     *     unresolvedByFile: array<string, list<string>>,
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
        $candidateFiles = [];
        $consultedResidue = [];
        $unresolvedByFile = [];
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
            $helper = self::walkingHelperUsedIn($source);
            $namesRoot = preg_match(self::ROOT_ANCHOR, self::flatten($source)) === 1;

            // The DOMAIN of the per-file invariant, recorded here so it is the
            // same domain the decisions below use: a file whose unresolved sites
            // this derivation would consider at all. A file that neither names
            // the package root nor reaches it through a helper cannot be walking
            // the package, so its unresolved sites are not this roster's
            // business - which is the narrowing
            // testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor()
            // documents.
            if ($sites['unresolved'] !== [] && ($helper !== null || $namesRoot)) {
                $unresolvedByFile[$relative] = $sites['unresolved'];
            }

            if ($helper !== null) {
                $roster[] = $relative;
                $why[$relative] ??= ['HELPER:' . $helper];

                continue;
            }
            if (!$namesRoot) {
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
            $candidateFiles[] = $relative;

            if ($sites['root'] !== []) {
                $roster[] = $relative;
                $why[$relative] ??= $sites['root'];

                continue;
            }

            if (isset(self::DECLARED_TREE_WIDE_GUARDS[$relative])) {
                continue;
            }

            // The residue is CONSULTED here and nowhere else. Recording that is
            // what lets testEveryLicensedResidueRowStillMatchesALiveWalkSite()
            // ask which rows were actually reached, instead of re-deriving from
            // classifyWalkSites() and missing every row that an earlier return
            // made unreachable.
            $consultedResidue[] = $relative;
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
        sort($consultedResidue);
        sort($candidateFiles);
        ksort($unresolvedByFile);

        return self::$derivation = [
            'roster' => $roster,
            'unaccounted' => $unaccounted,
            'why' => $why,
            'candidateFiles' => $candidateFiles,
            'consultedResidue' => $consultedResidue,
            'unresolvedByFile' => $unresolvedByFile,
            'testFiles' => $testFiles,
            'walkerFiles' => $walkerFiles,
            'candidates' => $candidates,
        ];
    }

    /**
     * Which `tests/` walking helper, if any, does this source name?
     *
     * ON THE TOKEN STREAM AND NOT ON A GREP, and the token filter is doing two
     * different jobs. A grep cannot tell a `use` of a helper from a doc-block
     * that names it - and this file's own doc-blocks name the trait several
     * times. Nor can it tell a use from a STRING: `/usr/bin/grep -rln
     * BuiltInToolCorpus tests/` reports THIS file, and the only reason is that
     * {@see HAND_MAINTAINED_CENSUS_SET} holds the literal
     * `'Tools/BuiltInToolCorpusTest.php'`. Accepting only NAME tokens excludes
     * `T_CONSTANT_ENCAPSED_STRING` by construction, so every roster constant in
     * this file stops being a reference to itself. Both directions are pinned by
     * {@see testTheHelperSetChannelAKeysOnIsDerivedFromTheTreeItself()}.
     *
     * THE LAST SEGMENT IS MATCHED EXACTLY, not by suffix: a suffix test would let
     * a class named `MyBuiltInToolCorpus` answer for `BuiltInToolCorpus`, and the
     * imported and short spellings of one helper already differ only by
     * namespace.
     */
    private static function walkingHelperUsedIn(string $source): ?string
    {
        $helpers = self::walkingHelperNames();

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token) || \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (!\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            $segments = explode('\\', $token[1]);
            $last = strtolower((string) end($segments));
            if (isset($helpers[$last])) {
                return $last;
            }
        }

        return null;
    }

    /**
     * The `tests/` helpers that carry a root-anchored walk, by declared type
     * name, lower-cased. Computed once.
     *
     * THIS IS CHANNEL A'S ALPHABET AND IT IS DERIVED BY THE SAME CLASSIFIER THAT
     * GRADES THE TESTS, which is the whole point: a helper added tomorrow enters
     * channel A without anybody editing this file, and a helper that stops
     * walking leaves it. Only `$sites['root']` counts - a helper whose walk this
     * resolver cannot place is NOT promoted to an alphabet entry, because that
     * would make every file naming it a roster member on a guess.
     *
     * SELF-EXCLUSION IS BY CONSTRUCTION, not by a name check: only
     * non-`*Test.php` files are considered, so no test file can nominate itself
     * or its neighbours as a helper.
     *
     * @return array<string, string> lower-cased name => the file that declared it
     */
    private static function walkingHelperNames(): array
    {
        if (self::$helpers !== null) {
            return self::$helpers;
        }

        $helpers = [];
        foreach (self::everyTestFile() as $relative => $absolute) {
            $relative = str_replace('\\', '/', $relative);
            if (str_ends_with($relative, 'Test.php')) {
                continue;
            }
            $source = (string) file_get_contents($absolute);
            if (self::classifyWalkSites($source)['root'] === []) {
                continue;
            }
            foreach (self::declaredTypeNames($source) as $name) {
                $helpers[strtolower($name)] ??= $relative;
            }
        }
        ksort($helpers);

        return self::$helpers = $helpers;
    }

    /**
     * The class/trait/interface names DECLARED in one source.
     *
     * `Foo::class` IS EXCLUDED - `T_CLASS` is the same token there, and taking
     * the token after it would nominate whatever followed the expression. An
     * anonymous `new class` is excluded by the same test, since the token after
     * it is `(` or `{` rather than a name.
     *
     * @return list<string>
     */
    private static function declaredTypeNames(string $source): array
    {
        $tokens = self::significant($source);
        $names = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || !\in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE], true)) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (\is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if (\is_array($next) && $next[0] === T_STRING) {
                $names[] = $next[1];
            }
        }

        return $names;
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
            if (preg_match(self::NAME_SPELLING, $name) !== 1) {
                continue;
            }
            $boundary = '~(?<![A-Za-z0-9_$>])' . preg_quote($name, '~') . '(?![A-Za-z0-9_])~';
            if (preg_match($boundary, $expression) === 1) {
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
            . 'whether it still names one of the walking helpers walkingHelperNames() derives, and '
            . 'whether its walk root still resolves through the four taint rules in rootAnchoredNames(). '
            . 'If a file genuinely stopped walking '
            . 'the tree, remove it from HAND_MAINTAINED_CENSUS_SET and from prompt_plan.md section 1.2 '
            . 'action 7b in the same change-set - do not widen the anchor pattern to paper over it.';
    }
}
