<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\DropsInsignificantTokensTrait;
use SugarCraft\Crush\Tests\Support\RefusesAnUnreadableSourceTrait;
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
 * THIS IS THE THIRD READING OF ONE GREP AND THE FIRST ONE THAT COUNTS THE RIGHT
 * THING, so all three are written out - a figure this file has now got wrong
 * twice is exactly the figure a reader should not have to take on trust.
 *
 * REVISION 1 said the grep "names SEVEN consumers". REVISION 2 said the grep
 * returns 9 here and 8 at `bb4a311d0`, that two of the nine are not consumers -
 * "the trait's own declaration, and THIS file" - and therefore SEVEN consumers
 * at base, FIVE of them outside the list of nine.
 *
 * WHAT IS TRUE, and the difference is that a `grep` for a NAME finds doc-blocks:
 * `git grep -l 'TestFileWalkTrait' bb4a311d0 -- sugar-crush/tests/` returns 8
 * files, and only FIVE of them carry `use TestFileWalkTrait;`. THREE do not: the
 * trait's own declaration, `Backend/AwaitPromiseDiagnosticArmTest.php` and
 * `Backend/ScaledClockHelperSeamTest.php` - the last two name the trait ONLY
 * inside a doc-block, and each says in so many words that its own walk is NOT the
 * trait's. Revision 2 also named THIS file as a non-consumer, and this file DOES
 * `use TestFileWalkTrait;` - its own `derivation()['why']` records it as
 * `HELPER:testfilewalktrait`. So revision 2 named the wrong two of the wrong
 * count.
 *
 * SO: FIVE consumers at `bb4a311d0`, of which THREE are outside the list of nine
 * (`Support/AssertionSwallowingCatchTest.php`,
 * `Support/DuplicatedDocBlockLineTest.php`,
 * `Support/OneSidedHomeSandboxTest.php`) - one of which carries 3,268 assertions
 * at base on its own, better than a tenth of the whole nine-file set's 31,215
 * (10.5%). The finding is unchanged in kind and smaller in size, and the reason
 * the count kept moving is that the generator was a text search for a name in a
 * tree whose doc-blocks discuss that name. {@see walkingHelperUsedIn()} exists
 * because of exactly that, and this paragraph is where the lesson was learned
 * twice before being applied.
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
 *    THE ALIAS HALF TRAVELLED FROM F4: "names" now includes the two-literal
 *    `class_alias` form, whose canonical sits in a STRING and so was invisible
 *    to the T_STRING walk - MEASURED null at the base, and the import-alias
 *    half the close review inferred was MEASURED already-honest (the `use`
 *    statement spells the canonical). Both rows, with the non-helper polarity,
 *    are pinned by
 *    {@see testAWalkingHelperReachedThroughAnAliasOrClassAliasIsStillChannelA()}.
 *  - CHANNEL B: the file constructs a directory walker whose ROOT resolves to
 *    the package root. Resolution is a small same-file dataflow: an anchor
 *    (`dirname(__DIR__…)`, or `__DIR__` followed by a `/..` segment) written
 *    directly in the walker's own argument, or reaching it through an
 *    assignment, a class constant, a property default, a `foreach` binding, or
 *    a zero-argument helper whose body is itself anchored. Iterated to a
 *    fixpoint, so a chain of any depth resolves. THE NAME HALF IS NO LONGER
 *    WRITTEN-TEXT-ONLY: F4 measured three silent shapes through the old
 *    alphabet - `use function glob as g;`, `use RecursiveDirectoryIterator as
 *    Walk;`, and an `SplFileInfo` construction iterated via `getChildren()` -
 *    and {@see importAliasMap()} resolves the alias pair (plus the
 *    `namespace\` relative spelling the write scanner had already paid for)
 *    while {@see classifyGetChildrenSite()} reads the SPL shape and fails
 *    CLOSED on receivers it cannot place. The remaining silence in that
 *    family - a computed `class_alias`, an alias to a NAMESPACED user class
 *    that walks - is carried as rows in the blind-spot table, per F4's
 *    "detect or declare-with-pin", not faked.
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
 * MEASURED: the two sets OVERLAP and neither contains the other - some roster
 * members are outside the candidate set (every channel-A member, which never
 * reaches the candidate counter) and some candidates are outside the roster (the
 * ones whose only walks are over a directory the test made). The CARDINALITIES
 * are merely ORDERED, which is a different and weaker claim than containment.
 * Both are now asserted for what they are, by
 * {@see testTheRosterAndTheCandidateSetOverlapWithNeitherContainingTheOther()}.
 *
 * NO SIZE OF EITHER SET IS WRITTEN DOWN ANYWHERE IN THIS FILE, and that is the
 * policy rather than an omission - see THE POPULATIONS ARE NOT PINNED IN PROSE
 * below.
 *
 * WHAT IS DELIBERATELY *NOT* IN THE DERIVATION, with the measurement that
 * decided it. A rule that taints a function PARAMETER from the arguments of its
 * same-file call sites would resolve three more real guards - but it is
 * flow-INSENSITIVE, so one call passing a source root and another passing a temp
 * directory taint the same parameter. MEASURED on this tree: that rule promotes
 * SEVEN files and only THREE of the seven are genuinely tree-wide, so it buys
 * three members at the price of four wrong ones and of a roster nobody can
 * check by reading. The ones it gets right are declared by hand instead, in
 * {@see DECLARED_TREE_WIDE_GUARDS} - which now holds FOUR rows rather than the
 * three that rule promoted correctly, because `BaseSystemPromptTest.php` was
 * later reclassified into it for an unrelated and separately measured reason. The
 * sentence used to say "the three it gets right are declared by hand", which read
 * as though the constant WAS those three. Each row carries its own reason, which
 * is rule 15's
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
 * Sites are passed over that way through all three routes - channel-A
 * membership, a resolved sibling site in the same file, and a declared row.
 * A PRESENT-TENSE COUNT OF THEM STOOD HERE, in a file whose
 * own policy is THE POPULATIONS ARE NOT PINNED IN PROSE. MEASURED by a reviewer
 * on the sentence it replaced: planting ONE ordinary root-anchored guard under
 * `tests/Support/` - this file's own killer experiment, recorded below - moved
 * three of its five numbers and the suite stayed green. The one-liner in
 * THE POPULATIONS ARE NOT PINNED IN PROSE answers it on demand, with
 * `["unresolvedByFile"]`, and cannot go stale.
 *
 * A COUNT OF THEM STOOD HERE AND WAS FALSE AT THE COMMIT THAT SHIPPED IT, which
 * is worth the four lines it takes to say why, because the cause is a fix in the
 * same commit. The smaller answer was correct BEFORE
 * {@see NAME_SPELLING} closed the substring fail-open: with the old
 * `str_contains()` resolver, `RuntimeTest.php`'s `scandir($dir)` resolved
 * (falsely) to the package root, so it sat in the `root` bucket instead of the
 * residue. Closing the hole moved it into the residue, where channel-A
 * membership passes it over. MEASURED both ways by reverting only
 * {@see isRootAnchored()}: the shipped resolver passes over EXACTLY ONE MORE
 * SITE, IN EXACTLY ONE MORE FILE, than the old one. The delta is the claim; the
 * two absolutes it was written as are the kind this file no longer records - see
 * THE POPULATIONS ARE NOT PINNED IN PROSE.
 *
 * AND THAT ALSO CORRECTS {@see NAME_SPELLING}'s OWN "MEASURED EFFECT ON THIS
 * TREE: none". It has no effect on the roster, the candidate set, the walking-file
 * population or the unaccounted-for set - those four are what was checked - but it
 * DOES move one site between buckets inside a file that is a member either way.
 * Four aggregates agreeing is not the same as nothing changing.
 *
 * WHAT IS TRUE, and it is the property that carries the weight: EVERY FILE with
 * an unresolved walker site is either IN THE ROSTER or has every one of those
 * sites licensed by name. MEASURED: EVERY file with a passed-over site is a
 * roster member, and that is not a coincidence - each of the three early returns
 * fires BECAUSE the file was just added. A site passed over in a file that is already a member
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
 * it, AND WITH THIS FILE EXCLUDED FROM ITS OWN DOMAIN. The gap is LATENT on this
 * tree: `dirname(__FILE__`, a `Finder` and `shell_exec('find` each appear in 0
 * files under `tests/` and `src/` OTHER THAN THIS ONE, and the files naming
 * `git ls-files` do so in a comment or a teardown.
 *
 * THE EXCLUSION IS THE CORRECTION, not a hedge. Those three claims were written
 * as a flat "0 files" and are now self-falsified by the paragraph that makes
 * them: MEASURED, each matches exactly one file under `tests/` and `src/` -
 * `tests/TreeWideGuardRosterTest.php`, because the blind-spot table below carries
 * all three as FIXTURE SOURCE. At `bb4a311d0` all three were genuinely 0. No
 * verdict moves either way, so this is a wrong figure rather than a wrong
 * classification - but it is the same self-reference hazard this change-set
 * handled explicitly for `src/Agents/Agent.php`'s citation census and then walked
 * into here. `dirname(__FILE__` was the one
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
 * WHAT THIS FILE DOES NOT DO. It does not edit `prompt_plan.md`; the plan
 * document is the orchestrator's to update.
 *
 * AND IT DOES NOT REPORT THE ROSTER ON A GREEN RUN. That is a real gap and the
 * sentence here used to deny it: it said the derived roster "is reported by
 * {@see testTheHandMaintainedCensusSetIsASubsetOfTheDerivedRoster()}'s failure
 * message". MEASURED FALSE, and false twice over. That test's first message is
 * {@see describeRosterGap()}, whose text is the hand-maintained members MISSING
 * from the derivation - the COMPLEMENT of the roster, empty precisely when the
 * derivation is working; its second names no file at all. And PHPUnit prints an
 * assertion message only on FAILURE, so no message of any wording would emit the
 * roster on a green run.
 *
 * THE POPULATIONS ARE NOT PINNED IN PROSE, ANYWHERE IN THIS FILE, AND THAT TOOK
 * THREE ATTEMPTS TO LEARN - which is why it is stated as a policy here rather
 * than left to each paragraph's judgement.
 *
 * ATTEMPT ONE paired occurrences with distinct spellings out of a probe whose
 * pattern was not the shipped one, and reproduced under no reading. ATTEMPT TWO
 * gave four figures measured correctly at the commit that wrote them, and they
 * were stale two commits later, because THIS FILE IS ONE OF THE FILES THE
 * GENERATOR READS. ATTEMPT THREE - the one a reviewer killed - wrote a dozen
 * MORE present-tense cardinalities into the paragraphs that were explaining why
 * the first two were withdrawn: the roster size, the candidate size, the
 * walking-file population, the residue's files and sites, the passed-over split,
 * the two set differences. MEASURED, by that reviewer: planting ONE ordinary
 * root-anchored guard under `tests/` moved four of them at once and the suite
 * stayed `OK`. Every one of them reproduced on the day it was typed. That is
 * exactly the property that makes them worthless - section 16.8 rule 2 is not
 * about being careful, it is about the number having no owner.
 *
 * SO: a size in this file is either DERIVED at the point of use, or it is a
 * BEFORE/AFTER pair from a controlled experiment at a named commit - which
 * cannot rot, because both arms move together and the claim is the DELTA. What
 * replaces them is the command below.
 *
 * ATTEMPT FOUR IS THIS ONE, AND THE SENTENCE THAT STOOD HERE WAS FALSE AT THE
 * COMMIT THAT SHIPPED IT. WHAT IT SAID: "The present-tense ones are gone."
 * WHAT WAS TRUE: five of them were still standing a hundred lines above this
 * paragraph, in THE SELF-POLICING HALF - a site count, a file count and a
 * three-way split of the passed-over residue - plus a roster size in the
 * GENERATOR paragraph below and a residue count on
 * {@see DECLARED_TREE_WIDE_GUARDS}. HOW MEASURED: a reviewer ran attempt
 * three's own killer experiment against attempt four, planting one ordinary
 * root-anchored guard under `tests/Support/`, and REPORTED three of the five
 * moving. That half is the reviewer's measurement and is recorded as theirs.
 * What was re-derived here, by planting the same guard: the roster moves 67 to
 * 68, {@see derivation()} absorbs the new file through channel B without a
 * declared row, and this file stays GREEN while it happens - so the sizes moved
 * with nothing able to notice, which is the whole property. (A total stood
 * here, `OK (16 tests, 1071 assertions)`, and was two revisions out of date by
 * the time a reviewer re-ran the experiment and got 1083. It is a present-tense
 * absolute with no paired before-arm, which is the one form the policy above
 * forbids, in the paragraph documenting why. The claim is that the suite stays
 * green, and that is what is recorded.) It
 * was written and then not applied to the paragraphs already on the page, which
 * is the same failure one level up, and the reason this correction is kept in
 * place rather than silently swept: FOUR attempts is the measurement.
 *
 * SO THE ROUTE IS THE GENERATOR, WHICH IS THE ONLY KIND OF ROUTE THAT CANNOT GO
 * STALE. From `<worktree>/sugar-crush`:
 *
 *     php -r 'require "vendor/autoload.php";
 *       $m = (new ReflectionClass(\SugarCraft\Crush\Tests\TreeWideGuardRosterTest::class))
 *           ->getMethod("derivation");
 *       $m->setAccessible(true);
 *       echo implode("\n", $m->invoke(null)["roster"]), "\n";'
 *
 * Swap `["roster"]` for `["candidates"]`, `["walkerFiles"]`, `["testFiles"]`,
 * `["candidateFiles"]`, `["consultedResidue"]`, `["unresolvedByFile"]` or
 * `["why"]` and the same one-liner answers every other population question this
 * file used to answer in prose.
 *
 * That prints the members whenever anybody wants them, needs no artefact, and
 * is the answer `prompt_plan.md` section 1.2 action 7b's list should be checked
 * against. A test that printed the whole roster on every green run would be
 * noise the suite has to carry forever; a test that printed them only on failure would not
 * be reporting at all. Neither is preferable to one command.
 *
 * AND IT DOES NOT CLAIM THAT "NOTHING THAT WALKS THE TREE ESCAPES CLASSIFICATION
 * SILENTLY", which is what this paragraph used to end with and which the file
 * contradicts in three places of its own. The blind-spot paragraph above says a
 * shape the alphabet cannot see is skipped in silence, and
 * {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()} ASSERTS that
 * six of its nine shapes land in the `silent` bucket. A summary sentence that
 * contradicts an assertion two hundred lines away is worse than no summary, and
 * it is the A1 defect - a doc-block claiming the opposite of the code - recurring
 * in the file written to close it.
 *
 * WHAT IT DOES CLAIM, all three parts asserted:
 *  - the roster is DERIVED, not hand-maintained, and so is each channel's
 *    alphabet;
 *  - every one of the nine hand-maintained census files comes out of the
 *    derivation ({@see testTheHandMaintainedCensusSetIsASubsetOfTheDerivedRoster()});
 *  - every file with a walk this alphabet CAN see, and cannot place, is either a
 *    roster member or licensed by name
 *    ({@see testEveryFileWithAnUnresolvedWalkIsARosterMemberOrFullyLicensed()}).
 * The gap between "a walk this alphabet can see" and "a walk" is the blind-spot
 * table, and it is a table precisely so that the gap has a size and a shape
 * instead of a summary sentence.
 *
 * THIS FILE IS ITSELF A MEMBER, through channel A, and
 * {@see testTheDerivationIncludesItselfAndTheGuardsTheNineFileListOmits()} asserts it -
 * a roster of tree-wide guards that did not contain itself would be answering
 * about a population it is not in.
 *
 * @internal
 */
final class TreeWideGuardRosterTest extends TestCase
{
    use DropsInsignificantTokensTrait;
    use RefusesAnUnreadableSourceTrait;
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
     * Five tree-wide guards the nine-file list omits, WITH THE CHANNEL EACH ONE
     * QUALIFIES THROUGH.
     *
     * THE HEADING USED TO SAY "the five consumers of the shared walker trait",
     * AND TWO OF THE FIVE ARE NOT CONSUMERS. MEASURED: the two `Backend/` rows
     * name the trait only inside a doc-block and qualify through CHANNEL B, on a
     * `RecursiveDirectoryIterator($root, ...)` of their own; `use
     * TestFileWalkTrait;` appears in neither. The three `Support/` rows are
     * genuine trait consumers. The rows are all correct - every one is a
     * tree-wide guard the nine-file list omits, which is what this constant is
     * for - and only the label over them was wrong, so the channel is now
     * recorded per row rather than asserted for the group.
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
        // CHANNEL B, not the trait: names TestFileWalkTrait only in a doc-block,
        // and that doc-block says its own walk is not the trait's.
        'Backend/AwaitPromiseDiagnosticArmTest.php',
        // CHANNEL B, same shape.
        'Backend/ScaledClockHelperSeamTest.php',
        // CHANNEL A, a real `use TestFileWalkTrait;`.
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
     * gives. A human has read each of these FOUR ROWS and confirmed the walk is
     * over the package's own files. (This said "each of these three" and was
     * correct until `BaseSystemPromptTest.php` was reclassified into the constant
     * a commit later - the sentence did not travel with the row it describes.
     * MEASURED at HEAD: four rows, and `why` agrees with declared = 4.)
     *
     * THAT USED TO CITE "rule 40" AND NO SUCH RULE EXISTS. WHAT IT SAID: "which
     * is rule 40". WHAT IS TRUE: prompt_plan.md's section 16.8 rule 40 is "a
     * surviving mutation may be equivalent, and that verdict does not transfer
     * to its neighbour" - a rule about mutating NEIGHBOURS OF A SURVIVOR, not
     * about a correction reaching neighbouring prose. HOW MEASURED: read the
     * WHOLE of section 16.8's rule list - rules 1 to 55, ending at
     * prompt_plan.md:3240 - and grepped the whole file for
     * "travel"/"neighbour"; the correction-travels claim appears in the plan's
     * PROSE twice and in no rule. (That said "rules 1-49" when it was written,
     * which was six rules short of the list and is the domain defect of rule 1
     * inside a paragraph about citation discipline. The rule list has ended at
     * 55 since before this branch's base - re-derived at `bb4a311d0` and at
     * HEAD - and rules 50-55 are merge, commit-before-mutating, log-on-failure,
     * still-tree, predict-first and silent-descoping: none of them is it, so
     * the conclusion was right over the wrong population.) So the claim stands on its own here - it is
     * measured over and over in this phase - and the number is dropped rather
     * than swapped for another one. The two plan sites are outside every
     * declared file list and are REPORTED, not edited.
     *
     * IT ADDS A FILE, AND IT ALSO EXEMPTS THAT FILE'S REMAINING SITES - both
     * halves, because the sentence here used to claim only the first. It said
     * "THIS IS NOT AN EXEMPTION LIST ... a wrong row here costs a test run rather
     * than a missed guard." MEASURED: a row here does remove something from a
     * verdict. {@see derivation()} returns as soon as a declared row is found, so
     * that file's unresolved sites are never licensed or reported at all, and the
     * key is the FILE, so how many that is grows with the file and is not
     * recorded here - ask `["unresolvedByFile"]`, per THE POPULATIONS ARE NOT
     * PINNED IN PROSE.
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
     * {@see WALKS_A_DIRECTORY_THE_TEST_MADE} (rule 35: an exemption's key is its
     * scope; the class doc-block above cites the same rule by the same number,
     * and this copy said 34 - which is the DIFFERENT rule about keying on
     * structure rather than on prose). The claim a row here makes is about the FILE - "this whole file is
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
        // `OK (15 tests, 179 assertions)`, byte-identical to baseline. RE-MEASURED
        // HERE, all three states, because the pair that stood in this comment -
        // "with the cache cleared FIRST, baseline is OK (49 tests, 601
        // assertions)" - cannot be true of a file that declares 15 test methods
        // and no data provider, and I shipped it without deriving it:
        //     cache warm                            OK (15 tests, 179 assertions)
        //     cache cleared                         OK (15 tests, 195 assertions)
        //     cache cleared + one file added under
        //     tests/fixtures/prompt/tree            Tests: 15, Assertions: 195,
        //                                           Failures: 1, at
        //                                           BaseSystemPromptTest.php:672
        // The 49/601 was wrong; the ARGUMENT it was supporting is right and the
        // corrected figures make it sharper - the walk is masked by its own
        // vendor/ cache (179 vs 195 assertions is the masking, measured), and it
        // unmasks the moment the cache is cleared. By this roster's stated
        // criterion the walk qualifies, and it is declared rather than argued
        // away.
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
     * base class, or a helper channel A's derived alphabet does not contain -
     * that last phrase used to read "a trait other than THE ONE channel A already
     * keys on", stale since channel A stopped being one hardcoded trait five
     * commits into this change-set. MEASURED at HEAD: two derived helpers, and
     * {@see closeOverDelegates()} now follows delegation chains from them, so the
     * residual gap is a helper that neither walks nor names one that does.
     *
     * A SENTENCE HERE USED TO CLAIM that "the accepted direction for anything
     * unreadable is a report, not a pass". IT WAS FALSE, and the class
     * doc-block's self-policing paragraph now carries the correction and the
     * measurement: a shape this alphabet cannot see as a walk produces no site
     * at all and is skipped SILENTLY. That is why every one of the shapes named
     * above is pinned, with the bucket it really lands in, by
     * {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()}.
     *
     * `GlobIterator` WAS MISSING AND WAS THE SAME DEFECT AS THE A4 ONE THIS
     * CHANGE-SET FIXED ONE FILE OVER - a token class absent from an alphabet
     * AND from that alphabet's own statement of what it cannot express, which
     * section 16.8 rule 31 is precisely about. MEASURED through the shipped
     * classifier before the fix: `new \GlobIterator(\dirname(__DIR__, 2) .
     * '/src/*.php')` produced NO site in either bucket, so
     * {@see derivation()} exited at its root-anchor gate and nothing red -
     * the fail-OPEN and silent direction, over a class that `class_parents()`
     * reports as extending `FilesystemIterator` and `DirectoryIterator`, two
     * of the three spellings already here.
     *
     * IT WAS NOT A GUESS THAT IT BELONGED: `tests/Support/ReadPathCensusTest.php`
     * carries its own path-reader alphabet listing
     * `RecursiveDirectoryIterator`, `DirectoryIterator`, `GlobIterator` and
     * `SplFileObject`, so a sibling census in this same tree already named it -
     * the identical argument the write-primitive scanner's `T_NAME_RELATIVE`
     * correction makes.
     *
     * MEASURED EFFECT ON THIS TREE: none - every population {@see derivation()}
     * returns is identical before and after, because
     * live `GlobIterator` walks under `tests/`, `src/` and `bin/` number ZERO
     * (the single grep hit is the alphabet string in that sibling census). A
     * closed door rather than a behaviour change, which is the same standing
     * `dirname(__FILE__` has in {@see ROOT_ANCHOR}. Pinned as a known-answer
     * row in {@see testTheWalkClassifierAnswersKnownInputsCorrectly()}.
     *
     * @var list<string>
     */
    private const WALKER_CLASSES = ['recursivedirectoryiterator', 'directoryiterator', 'filesystemiterator', 'globiterator'];

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
     * {@see testTheDerivationIncludesItselfAndTheGuardsTheNineFileListOmits()} declares
     * impossible. Matching is now bounded on both sides, so `$t` cannot answer
     * for `$this`.
     *
     * SECOND, SHAPE. {@see rootAnchoredNames()} takes an assignment TARGET as
     * written, so array-append and index targets arrive as `$calls[]`,
     * `$cases[$name]` and truncated forms like `$aliases]`. A malformed spelling
     * can only ever match by accident, so it is not consulted.
     *
     * THE CENSUS IS THE GENERATOR AND NOTHING ELSE - NO CARDINALITY, on the third
     * attempt, and the third attempt is the one that had to stop counting.
     * GENERATOR: for each `.php` file `everyTestFile()` returns, run
     * {@see rootAnchoredNames()} over {@see significant()} and classify every name
     * it yields by this pattern.
     *
     * WHY NO NUMBER GOES HERE. Attempt one paired OCCURRENCES with DISTINCT
     * spellings out of a probe whose pattern was not this constant, and reproduced
     * under no reading. Attempt two fixed the units and gave four figures measured
     * correctly at the commit that wrote them - and they were STALE TWO COMMITS
     * LATER, because THIS FILE IS ONE OF THE FILES the generator reads - the size
 * of that population is `["testFiles"]` on the one-liner above, deliberately not
 * written here. Every
     * paragraph added to it moves its own census. MEASURED: substituting the
     * earlier revision of this one file back into the population reproduces those
     * four figures exactly, and the current file gives four different ones.
     *
     * A self-referential census cannot be pinned in prose by anybody, however
     * carefully they measure - which is rule 2 not as a style preference but as
     * the only option. So the DIRECTION of the claim, which is all the pattern
     * needs, is asserted from the tree instead by
     * {@see testTheRootTaintResolverMatchesAtNameBoundariesAndIgnoresMalformedSpellings()}:
     * malformed spellings really are produced, they really are rejected, and they
     * really are the minority. Run the generator above if you want today's four
     * numbers; do not write them down.
     *
     * THE SHAPES ALLOWED ARE A SUBSET OF THE ONES {@see spellingsOf()}
     * PRODUCES - CORRECTED IN PLACE (section 16.8 rule 42).
     *
     * WHAT THIS SAID: "the shapes allowed are the ones spellingsOf() produces,
     * and I measured that list rather than guessing it".
     * WHAT IS TRUE: `spellingsOf()` emits SIX shapes and this pattern accepts
     * FOUR. It rejects `self::$root` - the static-property spelling of an
     * assignment target written `private static string $root` - and it rejects
     * a bare constant name like `LIB_ROOT`.
     * HOW MEASURED: drove `spellingsOf()` by reflection over the two declaration
     * shapes it branches on and ran every output through this pattern;
     * `self::$root` and `LIB_ROOT` come back 0 and the other four come back 1.
     * WHICH DIRECTION THAT FAILS IN, because it decides whether it is a hole:
     * CLOSED. A walk rooted at a name this pattern will not look for simply does
     * not resolve, so the site lands in the residue and is REPORTED - it cannot
     * become a silent roster omission. No verdict on this tree moves either way;
     * `self::$root` and a bare constant read are spellings this tree does not
     * currently use.
     *
     * The four it does accept: a plain local `$root`, a
     * property read `$this->srcDir`, a class constant `self::LIB_ROOT` or
     * `static::LIB_ROOT`, and - the one a narrower guess would have deleted
     * outright - the zero-argument helper call `self::helper(`, `$this->helper(`
     * or `static::helper(`, trailing paren included, which is how the fourth
     * taint rule spells its subject. A first draft of this pattern omitted that
     * form and would have silently disabled an entire taint rule; the census
     * above is what caught it.
     *
     * MEASURED EFFECT ON THIS TREE: none - every population {@see derivation()}
     * returns is identical before and after.
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
     * MEASURED, it matches 0 files under `tests/` and `src/` OTHER THAN THIS ONE
     * today, so it is a closed door rather than a behaviour change. (The
     * exclusion is the correction the class doc-block already carries, and this
     * copy of the same claim did not get it: a correction missed on the
     * neighbour 370 lines away. (This cited "rule 40" for that, and rule 40 is
     * the mutation-equivalence rule - see the correction on
     * {@see DECLARED_TREE_WIDE_GUARDS}.) The one match is
     * this file: the blind-spot table carries `dirname(__FILE__` as FIXTURE
     * SOURCE. At `bb4a311d0` it was genuinely 0.)
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
     * THE OMISSIONS, AND THIS FILE'S OWN MEMBERSHIP.
     *
     * A trait consumer's tree-wide-ness is a fact about the code rather than an
     * inference from it, which is why channel A is the derivation step that
     * cannot be wrong. This test pins the five guards the hand-maintained list
     * omits AND this file itself - a roster that did not contain itself would be
     * reporting on a population it is not in, and this file walks the whole of
     * `tests/` on every one of its own assertions.
     *
     * ITS NAME USED TO SAY "AndTheTraitsOtherConsumers" AND TWO OF THE FIVE ARE
     * NOT CONSUMERS - see {@see OMITTED_BY_THE_HAND_MAINTAINED_SET}, where the
     * channel is now recorded per row. The subjects did not change; the name was
     * describing three of them and claiming all five.
     */
    public function testTheDerivationIncludesItselfAndTheGuardsTheNineFileListOmits(): void
    {
        $roster = self::derivation()['roster'];

        $this->assertContains(
            'TreeWideGuardRosterTest.php',
            $roster,
            'this file walks the whole of tests/ and is not in its own derived roster, so the '
                . 'derivation is not seeing the mechanism it is built on',
        );

        // THE SAME LIVENESS PIN, FOR THE CONSTANT THAT IS THE FINDING. These
        // five rows ARE the Phase 3 close review's finding 1 made executable,
        // and the loop below is a `foreach`: fewer rows is fewer iterations, so
        // an emptied constant retires the finding and keeps the test's name.
        // MEASURED by a reviewer: one row deleted ran `OK (16 tests, 1068
        // assertions)` and the emptied constant `OK (16 tests, 1064)` - a drop
        // small enough that nobody reads it as a retired guarantee.
        $this->assertCount(
            5,
            self::OMITTED_BY_THE_HAND_MAINTAINED_SET,
            'the five tree-wide guards the nine-file list omits - which ARE the Phase 3 close '
            . 'review\'s finding 1, made executable - have changed in number. The loop below '
            . 'cannot fail for a row it no longer has, so a row deleted on its own retires part of '
            . 'that finding while staying green. If the nine-file list in prompt_plan.md section '
            . '1.2 action 7b grew to cover one of these, say so here and update this count.',
        );

        foreach (self::OMITTED_BY_THE_HAND_MAINTAINED_SET as $omitted) {
            $this->assertContains(
                $omitted,
                $roster,
                $omitted . ' walks the whole tree and is not in the derived roster. It is one of the '
                    . 'five guards the hand-maintained list of nine omits, and this file exists to '
                    . 'stop those being silent. CHECK WHICH CHANNEL IT QUALIFIED THROUGH before '
                    . 'suspecting the trait: the three Support/ rows are trait consumers, the two '
                    . 'Backend/ rows are channel B and name the trait only in a doc-block - an '
                    . 'earlier version of this message asserted the trait for all five and would '
                    . 'have printed a false sentence for either Backend/ row. If it genuinely '
                    . 'stopped walking the tree, remove it from '
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
        // THE CONSTANT'S OWN LIVENESS, BEFORE ANYTHING IS DERIVED FROM IT, and
        // this file had no business shipping without it: `array_diff` over a
        // SHORTER list is a SMALLER diff, so deleting a row - or emptying the
        // constant outright - leaves every assertion below green. MEASURED by a
        // reviewer, both ways: one row removed and the whole constant replaced
        // with `[]` each ran `OK (16 tests, 1069 assertions)`, the count
        // IDENTICAL to the untouched file, so neither the green nor the
        // assertion-total corollary could see it. That is section 16.8 rule 15
        // happening inside the file whose headline is that a hand-maintained
        // list inherits its own omissions - and the sibling constant
        // {@see DECLARED_TREE_WIDE_GUARDS} already carried the guard these two
        // lacked. An exact count and not `assertNotSame([], ...)`: the plan's
        // list is a fixed nine, so a legitimate change to it is a change to
        // `prompt_plan.md` section 1.2 action 7b and belongs in the same
        // change-set as this number.
        $this->assertCount(
            9,
            self::HAND_MAINTAINED_CENSUS_SET,
            'the nine-file census set prompt_plan.md section 1.2 action 7b names has changed size. '
            . 'If a file was legitimately added there or dropped from it, update this count in the '
            . 'same change-set; if a row was deleted from this constant on its own, the subset '
            . 'assertion below cannot fail for a case it no longer has.',
        );

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
     * a number of unresolved sites never reach this test - and every one of the
     * files carrying them is a member, which is why they never reach it.
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
     * THEM. AND THEIR REPLACEMENTS WENT THE SAME WAY: a re-measured pair stood
     * here next, and a reviewer planted one ordinary guard under `tests/` and
     * moved both without anything going red. Neither ratio is written down now;
     * {@see derivation()} returns `walkerFiles` and `consultedResidue` and the
     * class doc-block prints the command. That is the defect this class's
     * doc-block declares four paragraphs earlier, recurring inside the same file
     * TWICE, which is a fair measure of how durable it is.
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
        $direct = self::knownAnswerSources()['direct'];
        $this->assertSame(
            ['root' => ['glob(\dirname(__DIR__,2).\'/src/*.php\')'], 'unresolved' => []],
            self::classifyWalkSites($direct),
            'an anchor written directly in the walker argument is not resolved, so channel B is dead',
        );

        $viaChain = self::knownAnswerSources()['viaChain'];
        $this->assertSame(
            ['root' => ['RecursiveDirectoryIterator($src,\FilesystemIterator::SKIP_DOTS)'], 'unresolved' => []],
            self::classifyWalkSites($viaChain),
            'a root reaching the walker through a constant and two assignments is not resolved, so the '
                . 'fixpoint is not iterating',
        );

        $temp = self::knownAnswerSources()['temp'];
        $this->assertSame(
            ['root' => [], 'unresolved' => ['glob($d.\'/*\')']],
            self::classifyWalkSites($temp),
            'a temp-directory walk was resolved to the package root, which would put every test with a '
                . 'teardown in the roster',
        );

        $opaque = self::knownAnswerSources()['opaque'];
        $this->assertSame(
            ['root' => [], 'unresolved' => ['scandir($where)']],
            self::classifyWalkSites($opaque),
            'a walk over an unresolvable root came back as neither root nor unresolved, so the '
                . 'fail-closed bucket is not being filled',
        );

        // THE SPL FAMILY'S FOURTH SPELLING, added because it was a SILENT
        // fail-open: GlobIterator extends FilesystemIterator extends
        // DirectoryIterator, and it produced no site in either bucket until it
        // joined WALKER_CLASSES. Zero live uses on this tree, so this row is
        // the only thing keeping the alphabet entry honest.
        $viaGlobIterator = self::knownAnswerSources()['viaGlobIterator'];
        $this->assertSame(
            ['root' => ["GlobIterator(\\dirname(__DIR__,2).'/src/*.php')"], 'unresolved' => []],
            self::classifyWalkSites($viaGlobIterator),
            'a root-anchored GlobIterator is not seen as a directory walk. It extends '
                . 'FilesystemIterator, which IS in WALKER_CLASSES, and a walk this alphabet cannot '
                . 'see produces no site at all - so the file is skipped in SILENCE rather than '
                . 'landing in the residue. Put globiterator back in WALKER_CLASSES.',
        );

        // THE OTHER TWO SPELLINGS WITH NO LIVE SITE ON THIS TREE. Same reason as
        // GlobIterator's row above, and the correspondence is now DERIVED by
        // {@see testTheDerivationDetectsAShrinkInEitherHalfOfTheWalkerAlphabet()}
        // rather than left to whoever adds the next spelling to remember: a
        // spelling the tree does not exercise is covered by nothing unless it
        // has a row here.
        //
        // `readdir` IS THE MORE INTERESTING OF THE TWO, because the root does
        // not reach it directly - it reaches `opendir()`, and the handle is what
        // reaches `readdir()`. So this row also pins that the taint fixpoint
        // crosses `opendir`, which no other row here covers.
        $viaReaddir = self::knownAnswerSources()['viaReaddir'];
        $this->assertSame(
            ['root' => ['readdir($h)'], 'unresolved' => []],
            self::classifyWalkSites($viaReaddir),
            'a root-anchored readdir() loop is not seen as a directory walk, or the root stopped '
                . 'reaching it through the opendir() handle. Either way a guard written that way '
                . 'produces no site at all and its file is skipped in SILENCE rather than landing '
                . 'in the residue.',
        );

        $viaFilesystemIterator = self::knownAnswerSources()['viaFilesystemIterator'];
        $this->assertSame(
            ['root' => ['FilesystemIterator($d,\FilesystemIterator::SKIP_DOTS)'], 'unresolved' => []],
            self::classifyWalkSites($viaFilesystemIterator),
            'a root-anchored FilesystemIterator is not seen as a directory walk. It appears on this '
                . 'tree only as the SKIP_DOTS flag argument of another iterator, never as the '
                . 'iterator itself, so this row is the only thing keeping that alphabet entry '
                . 'honest.',
        );

        // Neither a walker call: `new Glob(...)` is this tree's Glob TOOL, and
        // `$this->glob(...)` is a method. A classifier that counted either
        // would demand declared rows for code that walks nothing.
        $notAWalk = self::knownAnswerSources()['notAWalk'];
        $this->assertSame(
            ['root' => [], 'unresolved' => []],
            self::classifyWalkSites($notAWalk),
            'a constructed Glob tool or a method named glob() is being read as a directory walk',
        );

        // THE THREE MEASURED SILENT ESCAPES OF F4, each now classified, plus
        // the runtime-alias sibling and the two controls that keep the new
        // channels from reporting everything: an UNANCHORED getChildren
        // (fail-closed residue — a walk over a directory the test made, which
        // is exactly what WALKS_A_DIRECTORY_THE_TEST_MADE exists to answer)
        // and the written-name labels, which stay WRITTEN (`g(...)` not
        // `glob(...)`) because a roster row's job is to point a human at the
        // line, and the line says `g`.
        $viaFunctionAlias = self::knownAnswerSources()['viaFunctionAlias'];
        $this->assertSame(
            ['root' => ["g(\\dirname(__DIR__,2).'/src/*.php')"], 'unresolved' => []],
            self::classifyWalkSites($viaFunctionAlias),
            'F4 escape one is open again: `use function glob as g;` + `g($root)` classifies as '
            . 'nothing, so a guard that walks only through a function alias lands in no bucket '
            . 'at all and its file is skipped in SILENCE. The alias map adds the spelling; '
            . 'deleting the function-kind half of importAliasMap() reddens exactly this row.',
        );

        $viaClassAlias = self::knownAnswerSources()['viaClassAlias'];
        $this->assertSame(
            ['root' => ['Walk($f)'], 'unresolved' => []],
            self::classifyWalkSites($viaClassAlias),
            'F4 escape two is open again: a walker CLASS imported under an alias is still the '
            . 'walker, and a classifier that reads only written last-segment text sees neither '
            . 'the class nor the walk it constructs.',
        );

        $viaRuntimeAlias = self::knownAnswerSources()['viaRuntimeAlias'];
        $this->assertSame(
            ['root' => ['RD($d)'], 'unresolved' => []],
            self::classifyWalkSites($viaRuntimeAlias),
            'the addendum channel: `class_alias(\'RecursiveDirectoryIterator\', \'RD\')` + '
            . '`new RD($d)` is the same class under a runtime name, decidable here from two '
            . 'literals — and a computed alias stays declared-silent in the blind-spot table.',
        );

        $viaSplFileInfoChildren = self::knownAnswerSources()['viaSplFileInfoChildren'];
        $this->assertSame(
            ['root' => ['$f->getChildren()'], 'unresolved' => []],
            self::classifyWalkSites($viaSplFileInfoChildren),
            'F4 escape three is open again: the package root reached the SPL walker through '
            . 'the CONSTRUCTION and the walk itself is a method call - neither alphabet names '
            . 'it, and the file disappears without this channel.',
        );

        $splFileInfoChained = self::knownAnswerSources()['splFileInfoChained'];
        $this->assertSame(
            ['root' => ["(new\\SplFileInfo(\\dirname(__DIR__,2).'/src'))->getChildren()"], 'unresolved' => []],
            self::classifyWalkSites($splFileInfoChained),
            'the chained spelling `(new \SplFileInfo($root))->getChildren()` has no variable for '
            . 'the taint resolver to carry the anchor - the receiver walk has to read the '
            . 'expression itself, and if it returns unresolved here the anchor died with the '
            . 'parenthesis.',
        );

        // THE CONTROL THE NEW CHANNELS OWE (§16.8 rule 18): the getChildren
        // arm over a TEMP directory must report UNRESOLVED, not root and not
        // silence. A classifier that answered `root` here would roster every
        // temp-dir test in the tree; one that answered nothing here would have
        // answered nothing everywhere, which is the silence F4 is about.
        $childrenUnanchored = self::knownAnswerSources()['childrenUnanchored'];
        $this->assertSame(
            ['root' => [], 'unresolved' => ['$f->getChildren()']],
            self::classifyWalkSites($childrenUnanchored),
            'an unanchored getChildren either resolved to the package root (the anchor leaked) '
            . 'or produced no site at all (the fail-closed direction was dropped) - both are the '
            . 'new channel reporting wrongly',
        );

        // THE CYCLE-2 PAIR: label order is free in PHP 8, and a runtime alias
        // may be namespaced - the site then spells the WHOLE name, with or
        // without the leading backslash. Both were the fully-silent bucket on
        // both instruments until the pairing/keying fix.
        $viaReorderedClassAlias = self::knownAnswerSources()['viaReorderedClassAlias'];
        $this->assertSame(
            ['root' => ['RD($d)'], 'unresolved' => []],
            self::classifyWalkSites($viaReorderedClassAlias),
            'the reversed label order paired backwards again (alias into the target slot and '
            . 'the other way round), which registers NOTHING the scanner can key on - the '
            . 'silent direction, again',
        );

        $viaNamespacedClassAlias = self::knownAnswerSources()['viaNamespacedClassAlias'];
        $this->assertSame(
            ['root' => ['Deep\\NS($d)'], 'unresolved' => []],
            self::classifyWalkSites($viaNamespacedClassAlias),
            'a namespaced class_alias literal must key the FULL registered name - last-segment '
            . 'keying left `new \Deep\NS(...)` matching nothing while it constructed the walker '
            . 'for real',
        );

        // THE CYCLE-3 PAIR, each measured silent-and-undeclared at the
        // previous HEAD (F-3: the write scanner's subclass chain had never
        // travelled to this classifier; F-2: the nowdoc body is as literal
        // as a quoted string and only the shape refused it).
        $viaWalkerSubclass = self::knownAnswerSources()['viaWalkerSubclass'];
        $this->assertSame(
            ['root' => ['MyWalker($d)'], 'unresolved' => []],
            self::classifyWalkSites($viaWalkerSubclass),
            'a same-file SUBCLASS of a walker class, constructed rooted, is the walker under a '
            . 'name the alphabet does not spell - exactly the silence the GlobIterator row '
            . 'refuses, reopened by this file\'s own fixpoint-less chain read',
        );

        $viaHeredocClassAlias = self::knownAnswerSources()['viaHeredocClassAlias'];
        $this->assertSame(
            ['root' => ['RD($d)'], 'unresolved' => []],
            self::classifyWalkSites($viaHeredocClassAlias),
            'a NOWDOC alias body is a literal in full - PHP registered the alias, the site '
            . 'constructed the walker, and a reader that matches only the quoted spelling '
            . 'watched it happen with no site in either bucket',
        );

        // AND THE FIXTURE SET ITSELF IS PINNED, which was the NINTH door in
        // this one check when the set held eight. The coverage half
        // in {@see testTheDerivationDetectsAShrinkInEitherHalfOfTheWalkerAlphabet()}
        // grants a walker spelling coverage from whatever these fixtures make the
        // classifier EMIT, and it iterates ALL of them. This test once read
        // eight by name while the set grew past them - which is the sentence
        // F4's own growth re-falsifies on any day a fixture again arrives
        // without a row, and why the key list below is the pin and the prose
        // is not. It now reads EVERY fixture by name: the alias pair, the
        // runtime alias, the two `getChildren` spellings and the unanchored
        // control each have their exact answer asserted above.
        //
        // MEASURED by a reviewer and reproduced here before fixing, in its
        // weaponised form: swap the REAL spelling `directoryiterator` out of
        // WALKER_CLASSES for a bogus one (size preserved, so the count pin does
        // not fire) and add one unasserted fixture that makes the classifier
        // emit the bogus name. `OK (16 tests, 1081 assertions)` - identical to
        // the clean tree. A live walker spelling left the alphabet with every
        // assertion in this file green.
        //
        // The two doc-blocks either side of that mechanism said this test
        // "asserts the exact answer for each" fixture. That was true of the
        // eight and false of the set; it is true of the set now.
        $this->assertSame(
            ['direct', 'viaChain', 'temp', 'opaque', 'viaGlobIterator', 'viaReaddir', 'viaFilesystemIterator', 'notAWalk',
                'viaFunctionAlias', 'viaClassAlias', 'viaRuntimeAlias', 'viaSplFileInfoChildren', 'splFileInfoChained', 'childrenUnanchored',
                'viaReorderedClassAlias', 'viaNamespacedClassAlias', 'viaWalkerSubclass', 'viaHeredocClassAlias',],
            array_keys(self::knownAnswerSources()),
            'a fixture was added to or removed from knownAnswerSources() without a matching '
            . 'exact-answer row above. That matters in one direction in particular: '
            . 'testTheDerivationDetectsAShrinkInEitherHalfOfTheWalkerAlphabet() grants alphabet '
            . 'coverage from whatever these fixtures make the classifier EMIT, and it reads every '
            . 'fixture while this test can only assert the keys it carries - so an unasserted '
            . 'fixture widens that coverage silently, which is how a walker spelling with a live '
            . 'call site can be dropped from WALKER_CLASSES with this whole file green at an '
            . 'unchanged assertion count. Add the row above, then add the key here.',
        );
    }

    /**
     * The known-answer fixtures, in ONE place, because two consumers read them.
     *
     * {@see testTheWalkClassifierAnswersKnownInputsCorrectly()} asserts what the
     * classifier returns for each; {@see testTheDerivationDetectsAShrinkInEitherHalfOfTheWalkerAlphabet()}
     * derives which walker spellings are EXERCISED by running the classifier
     * over them. Held apart, the two would drift and the coverage half would go
     * on reporting spellings that no fixture reaches any more.
     *
     * @return array<string, string>
     */
    private static function knownAnswerSources(): array
    {
        return [
            'direct' => "<?php\nforeach (glob(\\dirname(__DIR__, 2) . '/src/*.php') as \$f) {}\n",
            'viaChain' => "<?php\nclass P { private const R = __DIR__ . '/../..';\n"
            . "  private function go(): void { \$lib = self::R; \$src = \$lib . '/src';\n"
            . "    \$it = new \\RecursiveDirectoryIterator(\$src, \\FilesystemIterator::SKIP_DOTS); } }\n",
            'temp' => "<?php\nclass P { private function go(): void { \$d = sys_get_temp_dir() . '/x';\n"
            . "    foreach (glob(\$d . '/*') as \$f) {} } }\n",
            'opaque' => "<?php\nclass P { private function go(string \$where): void { scandir(\$where); } }\n",
            'viaGlobIterator' => "<?php\nclass P { private function go(): void {\n"
            . "    foreach (new \\GlobIterator(\\dirname(__DIR__, 2) . '/src/*.php') as \$f) {} } }\n",
            'viaReaddir' => "<?php\nclass P { private function go(): void { \$d = \\dirname(__DIR__, 2) . '/src';\n"
            . "    \$h = opendir(\$d); while (\$f = readdir(\$h)) {} } }\n",
            'viaFilesystemIterator' => "<?php\nclass P { private function go(): void { \$d = \\dirname(__DIR__, 2) . '/src';\n"
            . "    foreach (new \\FilesystemIterator(\$d, \\FilesystemIterator::SKIP_DOTS) as \$f) {} } }\n",
            'notAWalk' => "<?php\nclass P { private function go(): void { \$g = new Glob(prunedDirs: []);\n"
            . "    \$this->glob(\\dirname(__DIR__) . '/src'); \$x = \\dirname(__DIR__); } }\n",
            // THE THREE F4 ESCAPES, plus the runtime-alias row and two
            // fail-closed controls. None of the six has a live use on this
            // tree (MEASURED at this commit: `use function glob|scandir|readdir`,
            // `use ...Iterator as ...`, `->getChildren(` and `class_alias` all
            // return nothing outside this file and the write-scanner's own
            // probes) — which is precisely why each needs a row: the tree
            // exercises nothing here, so the row is the only thing keeping the
            // alphabet entry honest, the standing argument of the GlobIterator
            // and FilesystemIterator rows above.
            'viaFunctionAlias' => "<?php\nuse function glob as g;\nclass P { private function go(): void {\n"
            . "    foreach (g(\\dirname(__DIR__, 2) . '/src/*.php') as \$f) {} } }\n",
            'viaClassAlias' => "<?php\nuse RecursiveDirectoryIterator as Walk;\nclass P { private function go(): void {\n"
            . "    \$f = \\dirname(__DIR__, 2) . '/src'; \$it = new Walk(\$f); } }\n",
            'viaRuntimeAlias' => "<?php\nclass_alias('RecursiveDirectoryIterator', 'RD');\nclass P { private function go(): void {\n"
            . "    \$d = \\dirname(__DIR__, 2) . '/src'; \$it = new RD(\$d); } }\n",
            'viaSplFileInfoChildren' => "<?php\nclass P { private function go(): void {\n"
            . "    \$f = new \\SplFileInfo(\\dirname(__DIR__, 2) . '/src');\n"
            . "    foreach (\$f->getChildren() as \$c) {} } }\n",
            'splFileInfoChained' => "<?php\nclass P { private function go(): void {\n"
            . "    foreach ((new \\SplFileInfo(\\dirname(__DIR__, 2) . '/src'))->getChildren() as \$c) {} } }\n",
            'childrenUnanchored' => "<?php\nclass P { private function go(): void {\n"
            . "    \$d = sys_get_temp_dir() . '/x'; \$f = new \\SplFileInfo(\$d);\n"
            . "    foreach (\$f->getChildren() as \$c) {} } }\n",
            // THE CYCLE-2 RE-RAISES, pinned by exact label this time (the
            // table rows below pin the bucket): `class_alias` arguments
            // paired by label in EITHER order, and a NAMESPACED alias
            // constructed under its full name. Both were silent-and-undeclared
            // at the previous HEAD on both instruments, truncating for real.
            'viaReorderedClassAlias' => "<?php\nclass_alias(alias: 'RD', class: 'RecursiveDirectoryIterator');\n"
            . "class P { private function go(): void { \$d = \\dirname(__DIR__, 2) . '/src'; new RD(\$d); } }\n",
            'viaNamespacedClassAlias' => "<?php\nclass_alias('RecursiveDirectoryIterator', 'Deep\\NS');\n"
            . "class P { private function go(): void { \$d = \\dirname(__DIR__, 2) . '/src'; new \\Deep\\NS(\$d); } }\n",
            // THE CYCLE-3 PAIR, both MEASURED silent-and-undeclared before the
            // fix: a same-file SUBCLASS of a walker class (the write scanner's
            // thirteenth-defeat channel, never travelled to this classifier
            // until F-3), and a heredoc NOWDOC alias body (a pure literal the
            // two-literal reader refused for shape alone, F-2).
            'viaWalkerSubclass' => "<?php\nclass MyWalker extends \\RecursiveDirectoryIterator {}\n"
            . "class P { private function go(): void { \$d = \\dirname(__DIR__, 2) . '/src'; new MyWalker(\$d); } }\n",
            'viaHeredocClassAlias' => "<?php\nclass_alias('RecursiveDirectoryIterator', <<<'EOT'\nRD\nEOT);\n"
            . "class P { private function go(): void { \$d = \\dirname(__DIR__, 2) . '/src'; new RD(\$d); } }\n",
        ];
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
     * WHAT THE HEADER CLAIMED, AND F4 MEASURED FALSE (section 16.8 rule 42,
     * in place). WHAT THIS SAID: the table was the map of the alphabet's
     * silence - "the gap is declared, is checked, and cannot quietly widen".
     * WHAT IS TRUE NOW: it was three rows short of that claim - an import
     * FUNCTION alias, an import CLASS alias, and the SPL `getChildren()`
     * iteration were all silent shapes the table did not name, and none was
     * any of the six silences it did. A map of omissions that itself has
     * unomitted omissions is exactly the defect this file exists to refuse;
     * the paragraph earned its place by being wrong, which is why the sentence
     * above stands corrected rather than deleted. HOW MEASURED: the close
     * review drove all three through the shipped {@see classifyWalkSites()} -
     * 0 sites in either bucket, fully silent - and the same three rows now
     * appear below in the bucket they really land in, two of them as closed
     * doors, so the escape cannot return un-noticed.
     *
     * `dirname(__FILE__)` IS IN THE TABLE AS A CLOSED DOOR. It was silent and
     * it now resolves to the package root, because it cost one alternative in
     * {@see ROOT_ANCHOR} and MEASURED 0 live uses, so closing it could not
     * change any verdict on this tree. The import-alias pair and the SPL
     * `getChildren()` row are closed doors of the same standing, each with
     * its zero-live-population measurement in
     * {@see testTheWalkClassifierAnswersKnownInputsCorrectly()}'s fixture
     * comment. So are - as of review cycle 3, which measured each of them
     * silent-and-undeclared the day after they were written about - the
     * same-file WALKER SUBCLASS chain ({@see sameFileWalkerSubclasses()},
     * the F-3 row this method's own GlobIterator criterion demanded), the
     * reversed LABEL order and the NOWDOC alias body of the runtime-alias
     * reader (F-2). The rows still marked silent stay open deliberately -
     * each is the shape whose closure costs more than a false positive buys:
     * a COMPUTED `class_alias` and an alias whose target lives in another
     * file's class (cycle 1's boundary), the KEYWORD spellings inside a
     * subclass, whose resolution needs the enclosing-body stack the write
     * scanner carries and this classifier deliberately does not (cycle 3's
     * F-3, declined half, named), and the same-file CONSTANT argument of a
     * `class_alias`, which is the constant folding the string-indirection
     * row already refuses, declined on both instruments by the same right.
     * The class doc-block carries the measurement that rejected a subprocess
     * channel.
     *
     * ALL THREE POLARITIES, THROUGH THE SAME CLASSIFIER (section 16.8 rule
     * 18): the table carries rows that must RESOLVE, rows that must REPORT
     * and rows that must stay SILENT, and no count of them is written here -
     * the table has grown with every closed and every declared shape, which
     * is rule 2 in the paragraph two rows above it. A classifier that answered
     * the same way for every input would fail two of the three `assertContains`
     * checks below no matter what this table grows to.
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
            // THE THREE SHAPES F4 MEASURED AS UNDECLARED SILENCE. Two of the
            // buckets they now land in are CLOSED DOORS, in the standing sense
            // of the `dirname(__FILE__)` row above: the import-alias pair
            // resolves through {@see importAliasMap()} and the SPL
            // getChildren pair through {@see classifyGetChildrenSite()}, so
            // they resolve rather than report - and they stay in this table
            // because a table that only records silence drifts back into
            // claiming its resolves are permanent. The third row here is the
            // boundary of that closure: an alias this file cannot read is the
            // silence F4's disposition refused to fake away.
            'a walker function reached through an import alias' => [
                "<?php\nuse function glob as g;\nclass P { function go() { foreach (g(\\dirname(__DIR__, 2) . '/src/*.php') as \$x) {} } }\n",
                'root',
            ],
            'a walker class reached through an import alias' => [
                "<?php\nuse RecursiveDirectoryIterator as Walk;\nclass P { function go() { \$f = \\dirname(__DIR__, 2) . '/src'; new Walk(\$f); } }\n",
                'root',
            ],
            'an SPL walker iterated through getChildren' => [
                "<?php\nclass P { function go() { \$f = new \\SplFileInfo(\\dirname(__DIR__, 2) . '/src'); foreach (\$f->getChildren() as \$x) {} } }\n",
                'root',
            ],
            // THE IMPORT SPELLINGS THE READER CLAIMS TO ACCEPT. Review cycle 1
            // F-C5 measured all five resolving correctly and named the sin:
            // TRUE AND UNPINNED - a future edit blinding the comma-list branch,
            // the group-brace discriminator, the leading-backslash accept, the
            // function-aliased `class_alias` or its named-argument form would
            // keep this file green. The write-scanner twin pins its equivalents;
            // these rows are the roster's, and F-C4's two defeats arrive here
            // as closed doors the same day they are read.
            'a walker function imported in a comma list' => [
                "<?php\nuse function strlen as x, glob as g;\nclass P { function go() { foreach (g(\\dirname(__DIR__, 2) . '/src/*.php') as \$y) {} } }\n",
                'root',
            ],
            'a walker function imported in a braced group' => [
                "<?php\nuse function Acme\\{strlen as x, glob as g};\nclass P { function go() { foreach (g(\\dirname(__DIR__, 2) . '/src/*.php') as \$y) {} } }\n",
                'root',
            ],
            'a walker function imported with a leading backslash' => [
                "<?php\nuse function \\glob as g;\nclass P { function go() { foreach (g(\\dirname(__DIR__, 2) . '/src/*.php') as \$y) {} } }\n",
                'root',
            ],
            'a class_alias called through a function alias' => [
                "<?php\nuse function class_alias as ca;\nclass P { function go() { ca('RecursiveDirectoryIterator', 'RD'); new RD(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'root',
            ],
            'a class_alias called with named arguments' => [
                "<?php\nclass P { function go() { class_alias(class: 'RecursiveDirectoryIterator', alias: 'RD'); new RD(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'root',
            ],
            // THE CYCLE-2 PAIR, added the day the pairing and keying were
            // fixed (review cycle 2, F-A and F-B - both MEASURED silent at the
            // previous HEAD): label order is free, and a class_alias alias may
            // itself be namespaced, in which case the SITE writes the whole
            // name and only full-name keying matches it.
            'a class_alias with its labels in reversed order' => [
                "<?php\nclass P { function go() { class_alias(alias: 'RD', class: 'RecursiveDirectoryIterator'); new RD(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'root',
            ],
            'a namespaced runtime alias at its construction site' => [
                "<?php\nclass P { function go() { class_alias('RecursiveDirectoryIterator', 'Deep\\\\NS'); new \\Deep\\NS(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'root',
            ],
            'a same-file subclass of a walker class' => [
                "<?php\nclass MyWalker extends \\RecursiveDirectoryIterator {}\nclass P { function go() { new MyWalker(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'root',
            ],
            // THE TWO DECLINED SHAPES of the F-3 family, named so the table
            // keeps being the map of the silence the header now claims.
            // A KEYWORD (`new self` inside a subclass) needs the enclosing-
            // body stack the WRITE scanner grew; the classifier carries none,
            // and giving it one would only ever over-widen a bucket that
            // already lands in the residue for a human. A SAME-FILE CONSTANT
            // as the alias name is the literal one hop from the call -
            // resolving it is the constant folding the string-indirection
            // row already refuses, declined on both instruments by the same
            // right (RuntimeTest declares it beside this one).
            'a walker subclass reached through a self/static/parent keyword' => [
                "<?php\nclass MyWalker extends \\RecursiveDirectoryIterator { function m() { return new self(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'silent',
            ],
            'a class_alias named by a same-file constant' => [
                "<?php\nconst AL = 'RD';\nclass P { function go() { class_alias('RecursiveDirectoryIterator', AL); new RD(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'silent',
            ],
            'a walker reached through a COMPUTED class_alias' => [
                "<?php\nclass P { function go() { \$n = 'DirectoryIterator'; class_alias(\$n, 'RD'); new \\RD(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'silent',
            ],
            'an alias of a NAMESPACED user class that walks' => [
                "<?php\nuse Acme\\TreeScanner as Scan;\nclass P { function go() { new Scan(\\dirname(__DIR__, 2) . '/src'); } }\n",
                'silent',
            ],
            // THE NEW CHANNEL'S FAIL-CLOSED HALF, stated as a row: a
            // getChildren whose receiver this resolver cannot place does not
            // disappear - it lands in the residue for a human to answer, the
            // same direction the reflection-root row above demonstrates for
            // the walker alphabets.
            'a getChildren behind a helper call this resolver cannot follow' => [
                "<?php\nclass P { function go() { \$it = \$this->makeIterator(); foreach (\$it->getChildren() as \$x) {} }\n  function makeIterator() { return new \\stdClass(); } }\n",
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
     * THE HELPER CHANNEL AND THE ALIAS CHANNEL - one row of which the close
     * review INFERRED and F4's disposition asked to "detect or
     * declare-with-pin". Both halves were MEASURED against the base
     * implementation before either claim was repeated here, and they split.
     *
     * WHAT THE ADDENDUM SAID: `use Support\TestFileWalkTrait as TF;` +
     * `use TF;` "would miss walkingHelperUsedIn()'s name matching exactly as
     * the class-alias walk missed classifyWalkSites". WHAT MEASUREMENT SHOWS:
     * it does not - the written `use` statement itself carries the canonical
     * last segment, which is the token the matcher keys on, and the shipped
     * base classifier returned the helper for that row (driven, not argued).
     * The addendum's own label - INFERRED, "not separately re-driven" - was
     * exactly the epistemic status that caveat exists to mark, and this is
     * the correction it earned. The row below stays asserted anyway: it pins
     * a behavior the alias map could have silently broken.
     *
     * THE HALF THAT WAS REAL: `class_alias('...BuiltInToolCorpus', 'Corpus');`
     * + `new Corpus()` keeps the canonical name inside a STRING CONSTANT - no
     * T_STRING ever spells it - and the base matcher returned null. That is
     * channel A failing open by a whole construct, the same family as the
     * import-alias escapes this step closed on the other side of the file.
     * {@see importAliasMap()} reads the two-literal form; the computed form
     * stays in the blind-spot table above, and the polarity row here keeps
     * the channel from answering for every alias in the tree.
     */
    public function testAWalkingHelperReachedThroughAnAliasOrClassAliasIsStillChannelA(): void
    {
        $helpers = self::walkingHelperNames();

        // THE KNOWN ANSWER, asserted before any verdict (rule 13): the helper
        // set the rows below consult is the derived two, not an accident of
        // an empty map.
        $this->assertArrayHasKey('testfilewalktrait', $helpers, 'the derived helper set no longer holds the trait the class doc-block names - the rows below would then be about nothing');
        $this->assertArrayHasKey('builtintoolcorpus', $helpers, 'the derived helper set no longer holds the corpus - see the class doc-block for the measurement that found it');

        $importAlias = "<?php\nuse SugarCraft\\Crush\\Tests\\Support\\TestFileWalkTrait as TF;\nclass P { use TF; }\n";
        $this->assertSame(
            'testfilewalktrait',
            self::walkingHelperUsedIn($importAlias),
            'the import-alias half: measured ALREADY-HONEST at the base (the `use` statement '
            . 'spells the canonical), re-asserted here because the alias map this file now '
            . 'grows must not break the path that already worked',
        );

        $runtimeAlias = "<?php\nclass_alias('SugarCraft\\Crush\\Tests\\Tools\\BuiltInToolCorpus', 'Corpus');\nclass P { function go(): void { new Corpus(); } }\n";
        $this->assertSame(
            'builtintoolcorpus',
            self::walkingHelperUsedIn($runtimeAlias),
            'the class_alias half is the gap that was REAL: the canonical lives in a string '
            . 'constant, no T_STRING spells it, and the base matcher answered null - a test '
            . 'that reaches the tree only through an alias of the helper vanishes from '
            . 'channel A, which is the silence F4 addendum named even though its chosen '
            . 'spelling turned out to be safe',
        );

        $notAHelper = "<?php\nclass_alias('SugarCraft\\Crush\\Tests\\Support\\NotAWalkingHelperAtAll', 'Nope');\nclass P { function go(): void { new Nope(); } }\n";
        $this->assertNull(
            self::walkingHelperUsedIn($notAHelper),
            'a class_alias of a non-helper must contribute nothing - an alias map that '
            . 'answered for every alias would pass the row above while rostering the tree',
        );
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
     * neither contains the other - the channel-A members are outside the
     * candidate set, because they return before the candidate counter, and the
     * files whose only walks are over a directory the test made are candidates
     * outside the roster. Neither difference's SIZE is written here; both are
     * computed below. AND the assertion that shipped for it
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
     * {@see DECLARED_TREE_WIDE_GUARDS} row - passing over every remaining
     * unresolved site in a file it has just made a member.
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
     * MEASURED at this commit: ZERO dead rows. The size of the constant is not
     * written here; it is `count(self::WALKS_A_DIRECTORY_THE_TEST_MADE)` and the
     * assertion below ranges over all of it.
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

            $source = self::readOrFail($everyFile[$file]);
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
     * EVERY DECLARED TREE-WIDE ROW IS STILL WARRANTED - THE OTHER REMOVAL HALF.
     *
     * WHY THIS EXISTS, and it is a gap a reviewer opened by mutation rather
     * than by reading. {@see testEveryLicensedResidueRowStillMatchesALiveWalkSite()}
     * is the removal half for {@see WALKS_A_DIRECTORY_THE_TEST_MADE}, and its
     * doc-block spends a paragraph on why a row for code that no longer walks
     * "passes forever and waves through the next walk". {@see DECLARED_TREE_WIDE_GUARDS}
     * had NO equivalent, and {@see derivation()} pushes every declared key into
     * the roster with no existence check at all.
     *
     * MEASURED, both mutations, before this test existed: adding
     * `'Ghost/DeletedGuardTest.php' => '...'` for a file that does not exist
     * left this file at `OK (15 tests, 1055 assertions)`, byte-identical to
     * baseline, with the phantom sitting in a roster of 68. Adding a row for
     * `Config/EnvRosterDriftTest.php` - a file the derivation ALREADY reaches
     * through a resolved root site - was equally silent.
     *
     * AND THE SECOND ONE IS THE EXPENSIVE SHAPE, which is why "warranted" here
     * means more than "the file exists". A declared row makes
     * {@see derivation()} return BEFORE the residue check, so from that moment
     * the file's unresolved walker sites are never licensed and never reported.
     * A row that adds no member still buys that silence. Section 16.8 rule 33:
     * an exemption row written for correct code is a licence, and it is where
     * the next real offender hides.
     *
     * SO THREE THINGS ARE ASSERTED PER ROW, and each is a way a row goes dead:
     * the file still exists; channel A does NOT already carry it; and channel B
     * does NOT already resolve one of its walks. The last two are what make
     * this a warrant check rather than an existence check.
     *
     * WHAT IS DELIBERATELY NOT ASSERTED: that the file has an unresolved
     * walker site. A legitimate future row is precisely a file whose walk this
     * alphabet cannot see AT ALL - a collaborator object, a subprocess - and
     * that file produces no site in either bucket. Requiring one would red on
     * the very case {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()}
     * enumerates. The aggregate is asserted non-zero instead, so the exemption
     * half is exercised by SOMETHING without being demanded of EVERYTHING.
     *
     * BOTH POLARITIES, THROUGH THE SAME PREDICATES (rule 18), and the control
     * files are DERIVED from `why` rather than named: a resolver that answered
     * "not carried" for every input would pass the loop above on any constant
     * at all, so the two controls take a real channel-A member and a real
     * channel-B member out of the derivation and assert the predicates reject
     * exactly those.
     */
    public function testEveryDeclaredTreeWideGuardRowIsStillWarranted(): void
    {
        $this->assertNotSame(
            [],
            self::DECLARED_TREE_WIDE_GUARDS,
            'the declared-guard constant is empty, so this test ranges over nothing',
        );

        // AND ITS SIZE IS PINNED, which is what lets the two paragraphs above
        // say FOUR out loud. Same reasoning as the sibling constants: the loop
        // below is a `foreach`, so a deleted row is one fewer iteration and one
        // fewer chance to fail. This is the ONE size in this file written in
        // prose AND owned by an assertion; the rest are derived at the point of
        // use or are before/after pairs - see THE POPULATIONS ARE NOT PINNED IN
        // PROSE.
        $this->assertCount(
            4,
            self::DECLARED_TREE_WIDE_GUARDS,
            'the declared-guard list has changed size. A row is a LICENCE, so adding one is a '
            . 'deliberate act that belongs in the same change-set as this count; removing one '
            . 'retires a licence the file argues for in prose, and the loop below cannot fail for '
            . 'a row it no longer has.',
        );

        $everyFile = [];
        foreach (self::everyTestFile() as $relative => $absolute) {
            $everyFile[str_replace('\\', '/', $relative)] = $absolute;
        }

        $dead = [];
        $exemptedSites = 0;
        foreach (self::DECLARED_TREE_WIDE_GUARDS as $file => $_reason) {
            if (!isset($everyFile[$file])) {
                $dead[] = $file . ' => the file no longer exists';

                continue;
            }

            $source = self::readOrFail($everyFile[$file]);

            $helper = self::walkingHelperUsedIn($source);
            if ($helper !== null) {
                $dead[] = $file . ' => channel A already carries it (it names the walking helper '
                    . $helper . '), so this row adds no roster member and only buys silence for the '
                    . 'file\'s unresolved sites';

                continue;
            }

            $sites = self::classifyWalkSites($source);
            if ($sites['root'] !== []) {
                $dead[] = $file . ' => channel B already resolves ' . implode(' ; ', $sites['root'])
                    . ', so this row adds no roster member and only buys silence for the file\'s '
                    . 'unresolved sites';

                continue;
            }

            $exemptedSites += \count($sites['unresolved']);
        }

        $this->assertSame(
            [],
            $dead,
            "these DECLARED_TREE_WIDE_GUARDS rows are no longer warranted:\n"
                . implode("\n", array_map(static fn (string $row): string => '  - ' . $row, $dead))
                . "\n\nA row here does two things: it puts the file in the roster, AND it stops "
                . 'derivation() ever reaching that file\'s residue. When the derivation learns to '
                . 'reach the file on its own, only the second half survives - a standing licence '
                . 'over every future walk in that file, written for code that is already correct '
                . '(rule 33). Delete the row in the same change-set as whatever made it '
                . 'unnecessary; do not keep it "just in case".',
        );

        $this->assertGreaterThan(
            0,
            $exemptedSites,
            'no declared row is exempting any unresolved walker site, so the second half of what a '
                . 'row does - the silence it buys - is not exercised by this tree at all, and the '
                . 'warning in this constant\'s doc-block has lost its subject',
        );

        // BOTH POLARITIES. The controls are pulled out of the derivation so no
        // file name is written here and neither can go stale.
        $viaHelper = null;
        $viaRoot = null;
        foreach (self::derivation()['why'] as $member => $sites) {
            if ($viaHelper === null && \count($sites) === 1 && str_starts_with($sites[0], 'HELPER:')) {
                $viaHelper = $member;
            }
            if ($viaRoot === null && $sites !== [] && $sites[0] !== 'DECLARED' && !str_starts_with($sites[0], 'HELPER:')) {
                $viaRoot = $member;
            }
        }

        $this->assertIsString($viaHelper, 'no channel-A member exists to use as a control, so the helper predicate above is untested');
        $this->assertIsString($viaRoot, 'no channel-B member exists to use as a control, so the root predicate above is untested');

        $this->assertNotNull(
            self::walkingHelperUsedIn(self::readOrFail($everyFile[$viaHelper])),
            $viaHelper . ' qualified through channel A yet the predicate this test uses says it names '
                . 'no walking helper, so the loop above would accept a redundant row for it',
        );
        $this->assertNotSame(
            [],
            self::classifyWalkSites(self::readOrFail($everyFile[$viaRoot]))['root'],
            $viaRoot . ' qualified through channel B yet the predicate this test uses resolves none of '
                . 'its walks, so the loop above would accept a redundant row for it',
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

        // THE MALFORMED SPELLINGS ARE REAL OUTPUT OF THIS TREE, asserted rather
        // than quoted from a census. The doc-block gives four figures for them;
        // this is the part that must hold whatever those figures drift to - that
        // rootAnchoredNames() really does yield names NAME_SPELLING rejects, so
        // the filter is doing work rather than being decorative.
        $malformedFromTheTree = [];
        $wellFormedFromTheTree = 0;
        foreach (self::everyTestFile() as $absolute) {
            foreach (self::rootAnchoredNames(self::significant(self::readOrFail($absolute))) as $name) {
                if (preg_match(self::NAME_SPELLING, $name) === 1) {
                    $wellFormedFromTheTree++;

                    continue;
                }
                $malformedFromTheTree[$name] = true;
            }
        }

        $this->assertNotSame(
            [],
            $malformedFromTheTree,
            'rootAnchoredNames() no longer yields a single spelling NAME_SPELLING rejects, so either '
                . 'the taint extraction started returning only well-formed names - in which case say '
                . 'so here and the filter becomes belt-and-braces - or the pattern has been widened '
                . 'until it accepts assignment targets again',
        );
        $this->assertGreaterThan(
            \count($malformedFromTheTree),
            $wellFormedFromTheTree,
            'malformed taint spellings now outnumber well-formed ones, which means the filter is '
                . 'discarding most of the taint and the resolver is about to stop resolving',
        );

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
     * THE TAINT LOOP IS A FIXPOINT AND NOT A FIXED NUMBER OF PASSES.
     *
     * WHAT IT USED TO BE, and this is a correction in place (section 16.8 rule
     * 42) of a claim that two doc-blocks in this file made about the code:
     * {@see rootAnchoredNames()} ran `for ($pass = 0; $pass < 8; $pass++)`,
     * while the class doc-block said the resolution is "iterated to a fixpoint,
     * so a chain of any depth resolves" and that method's own doc-block repeated
     * the phrase. One taint rule adds at most one link per pass, so a chain of
     * NINE assignments written in reverse order truncated. MEASURED by a
     * reviewer driving the shipped {@see classifyWalkSites()}: depths 2, 6, 7
     * and 8 resolved, 9 and 12 did not.
     *
     * WHY THE CLAIM WAS REPAIRED RATHER THAN THE SENTENCE. The loop already
     * stopped as soon as a pass added nothing, and the taint set only ever
     * GROWS and is bounded by the distinct names in one file - so the cap was
     * never what made it terminate, and dropping it costs nothing and cannot
     * loop. `do { … } while (count($tainted) !== $before)` is the same loop
     * without the arbitrary bound. The alternative - declaring "eight" as a
     * blind spot in {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()} -
     * would have been honest and would have kept a limit nothing needs.
     *
     * WHICH DIRECTION IT FAILED IN, said plainly because it decides the
     * severity: CLOSED. A truncated chain drops the site into `unresolved`, the
     * file lands in `unaccounted`, and
     * {@see testEveryWalkerCallSiteInAFileThatNamesThePackageRootIsAccountedFor()}
     * reds. No guard could be silently missed. What was wrong was the MESSAGE a
     * reader would then get - it says "classify this walk or license it", and
     * the true repair was "your chain is deeper than eight".
     *
     * MEASURED EFFECT ON THIS TREE: none - every population {@see derivation()}
     * returns is identical before and
     * after, because no chain in `tests/` is nine deep. The defect was latent,
     * which is exactly why it needed a test rather than an observation.
     *
     * THE NEGATIVE CONTROL IS THE POINT OF THE LAST ASSERTION: a resolver that
     * answered `root` for every input would pass all three positive rows.
     */
    public function testTheRootTaintFixpointResolvesAChainDeeperThanTheOldEightPassCap(): void
    {
        // Reverse order on purpose: `$aN = $aN-1` is written BEFORE the
        // assignment that anchors `$a1`, so each pass can carry the taint
        // exactly one link further and the depth IS the pass count.
        $chain = static function (int $depth, string $seed): string {
            $body = "<?php\nclass P { private function go(): void {\n";
            for ($i = $depth; $i > 1; $i--) {
                $body .= '    $a' . $i . ' = $a' . ($i - 1) . ";\n";
            }
            $body .= '    $a1 = ' . $seed . ";\n";
            $body .= '    foreach (glob($a' . $depth . " . '/*.php') as \$f) {}\n} }\n";

            return $body;
        };

        $anchored = "\\dirname(__DIR__, 2) . '/src'";

        // The control: a depth the old cap could reach. Without it, a resolver
        // that had stopped resolving entirely would satisfy nothing below and
        // this test would be reporting the wrong cause.
        $this->assertSame(
            ["glob(\$a2.'/*.php')"],
            self::classifyWalkSites($chain(2, $anchored))['root'],
            'a two-link taint chain no longer resolves, so the resolver is broken outright rather '
                . 'than bounded - fix that before reading the depth rows below',
        );

        foreach ([9, 20] as $depth) {
            $sites = self::classifyWalkSites($chain($depth, $anchored));
            $this->assertSame(
                ["glob(\$a{$depth}.'/*.php')"],
                $sites['root'],
                'a taint chain ' . $depth . ' links deep did not resolve to the package root. '
                    . 'rootAnchoredNames() has regained a pass cap: one rule carries the taint one '
                    . 'link per pass, so any fixed number of passes is a depth limit. It fails '
                    . 'CLOSED - the walk lands in the unaccounted bucket and the reader is told to '
                    . 'classify or license a walk that is in fact a resolvable one - so the cost is '
                    . 'a wrong repair, not a missed guard. Restore the do/while.',
            );
            $this->assertSame([], $sites['unresolved']);
        }

        // NEGATIVE CONTROL, same shape, same depth: a chain that never touches
        // an anchor must NOT resolve.
        $unanchored = self::classifyWalkSites($chain(20, "sys_get_temp_dir() . '/x'"));
        $this->assertSame([], $unanchored['root'], 'a 20-link chain rooted at a temp directory resolved to the package root, so the resolver taints on depth rather than on the anchor');
        $this->assertSame(["glob(\$a20.'/*.php')"], $unanchored['unresolved']);
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
     *     be carrying roster members. Rule 19: one helper carrying every one of
     *     them is one SHAPE, and a derivation that had silently collapsed back to
     *     the single trait would satisfy every other assertion in this file.
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

        // (2) The positive half: the helpers this tree actually has. Named,
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
     * THE HELPER CLOSURE FOLLOWS A DELEGATION CHAIN OF ANY LENGTH.
     *
     * WHY THIS IS DRIVEN RATHER THAN OBSERVED. This tree contains no delegating
     * helper - MEASURED, `walkingHelperNames()` returns exactly the two that walk
     * for themselves - so the fixpoint loop in {@see closeOverDelegates()} does
     * ZERO work on the real population and is invisible to every other assertion
     * in this file. That is the definition of a branch that reads as working. So
     * it is driven with synthesised sources instead, which is also why the helper
     * closure is a pure function taking the texts.
     *
     * THREE THINGS ARE PINNED, and the third is the reason this is a fixpoint.
     * (1) A ONE-HOP delegate joins the set. (2) A THREE-HOP chain joins it -
     * a single pass would find only the first link, so this is what distinguishes
     * a loop from an `if`. (3) A helper that names NOTHING in the set stays OUT,
     * or the closure would be adding every non-test file under `tests/` and every
     * test that mentions any of them.
     */
    public function testTheHelperClosureFollowsADelegationChainOfAnyLength(): void
    {
        // name => declaring file, which is the shape walkingHelperNames() keeps.
        $seed = ['walks' => 'Support/Walks.php'];

        $oneHop = [
            'Support/A.php' => "<?php\nnamespace X;\ntrait A { use Walks; }\n",
        ];
        $this->assertArrayHasKey(
            'a',
            self::closeOverDelegates($oneHop, $seed),
            'a helper that delegates straight to a walking helper does not join the alphabet, so '
                . 'every test reaching the tree only through it is missed - and missed silently, '
                . 'because a delegating helper has no walker call site to be unaccounted for',
        );

        // THREE HOPS, and deliberately in an order where a single pass would stop
        // after the first: C names B, B names A, A names the seed.
        $chain = [
            'Support/C.php' => "<?php\nnamespace X;\ntrait C { use B; }\n",
            'Support/B.php' => "<?php\nnamespace X;\ntrait B { use A; }\n",
            'Support/A.php' => "<?php\nnamespace X;\ntrait A { use Walks; }\n",
        ];
        $closed = self::closeOverDelegates($chain, $seed);

        foreach (['a', 'b', 'c'] as $link) {
            $this->assertArrayHasKey(
                $link,
                $closed,
                'the delegation chain stopped before "' . $link . '", so closeOverDelegates() is '
                    . 'resolving one hop rather than iterating to a fixpoint - and the chain has no '
                    . 'bound, so one hop is not enough',
            );
        }

        // THE DECLARATION ALPHABET, all four spellings, because a helper whose
        // name declaredTypeNames() cannot read contributes nothing and is missed
        // in silence - the same failure mode this whole method is about, one
        // token class down. `enum` is in the list BECAUSE it was not: MEASURED,
        // NO helper file under tests/ declares one today, so this is a
        // door closed before anybody walks through it.
        foreach (['class' => 'C', 'trait' => 'T', 'interface' => 'I', 'enum' => 'E'] as $keyword => $name) {
            $declaration = ['Support/D.php' => "<?php\nnamespace X;\n" . $keyword . ' ' . $name . " { use Walks; }\n"];
            $this->assertArrayHasKey(
                strtolower($name),
                self::closeOverDelegates($declaration, $seed),
                'a helper declared with the "' . $keyword . '" keyword contributes no name, so channel '
                    . 'A cannot key on it and every test reaching the tree only through it is missed '
                    . 'silently - declaredTypeNames() must read all four declaration spellings',
            );
        }

        // AND THE NEGATIVE HALF. Without it every assertion above passes against
        // a closure that adds everything it is handed.
        $unrelated = [
            'Support/Unrelated.php' => "<?php\nnamespace X;\nfinal class Unrelated { public function go(): void {} }\n",
        ];
        $this->assertSame(
            $seed,
            self::closeOverDelegates($unrelated, $seed),
            'a helper that names nothing in the set was added to it, so the closure is enrolling '
                . 'every non-test file under tests/ and, through them, every test that mentions one',
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

        // AND THE TWO ASSERTIONS ABOVE DO NOT DO WHAT THIS METHOD IS NAMED FOR,
        // WHICH A REVIEWER MEASURED RATHER THAN ARGUED. They range over the
        // ROSTER, so a spelling with no live site on this tree can be deleted
        // from the alphabet and neither of them moves: dropping `readdir` ran
        // `OK (16 tests, 1069 assertions)` and dropping `filesystemiterator`
        // ran the same, the count IDENTICAL to the untouched file, while the
        // control - dropping `recursivedirectoryiterator`, which has live sites
        // - RED 9 of them. The instrument was alive and blind at once, which is
        // the pair of readings that separates rule 16 from rule 40.
        //
        $liveSiteCount = array_fill_keys([...self::WALKER_CLASSES, ...self::WALKER_FUNCTIONS], 0);
        foreach (self::derivation()['why'] as $sites) {
            foreach ($sites as $site) {
                foreach (array_keys($liveSiteCount) as $spelling) {
                    if (str_starts_with(strtolower($site), $spelling . '(')) {
                        $liveSiteCount[$spelling]++;
                    }
                }
            }
        }

        // SO EVERY SPELLING WITH NO LIVE SITE ON THIS TREE MUST CARRY A
        // KNOWN-ANSWER ROW, and that correspondence is derived here rather than
        // remembered. A spelling the tree exercises is covered by the tree; a
        // spelling it does not is covered by nothing unless somebody wrote it a
        // fixture, which is exactly how `globiterator` came to have one.
        //
        // AND THE OBVIOUS CHECK IN THIS SLOT WAS CIRCULAR, which is recorded
        // because it looked right and shipped for the length of one test run.
        // WHAT IT DID: built a synthetic call per spelling and asserted the
        // classifier returned a site for it. MEASURED, both halves: adding
        // `notawalkeratall` to WALKER_FUNCTIONS and `notaclassatall` to
        // WALKER_CLASSES - with the counts below bumped so they did not mask it
        // - each ran `OK (16 tests, 1076 assertions)`. Of course they did: the
        // alphabet IS the classifier's key, so a name is visible BECAUSE it was
        // declared. An assertion that cannot fail for an arbitrary input is not
        // a weak assertion, it is not one (section 16.8 rule 16).
        // AND THE COVERED SET IS WHAT THE CLASSIFIER EMITS, NOT WHAT THE FILE
        // SAYS. EIGHT revisions of this one check were satisfiable by text: a
        // comment; an assertion message; an array literal nothing compares; a
        // tautological `assertSame`; a string literal spelling the assertion
        // out; the message again once the gate moved to the token stream; a
        // literal in a DEAD TERNARY ARM that merely preceded the classifier
        // call; and a bare `str_contains` that let `ecursivedirectoryiterator`
        // ride on `recursivedirectoryiterator(`. Every one of the first seven
        // was closed by narrowing WHICH TEXT counted, and the eighth arrived by
        // the same route the previous seven did. MEASURED, the last two: the
        // dead ternary and the substring each ran `OK (16 tests, 1081
        // assertions)`, the total IDENTICAL to the clean tree.
        //
        // A TEXT CHECK HAS A DOOR FOR EVERY WAY TO WRITE TEXT. So the covered
        // set is now derived by RUNNING the shipped classifier over
        // {@see knownAnswerSources()} and keying on the spellings it actually
        // returns. No comment, message, literal, tautology, dead branch or
        // token forgery can add a key to that array; only a fixture the
        // classifier really reports can. The fixtures are shared with
        // {@see testTheWalkClassifierAnswersKnownInputsCorrectly()}, which
        // asserts the exact answer for each, so a fixture that stops meaning
        // what it says reds there rather than silently widening coverage here.
        //
        // AND THE SUBSTRING MATCH IS GONE WITH IT: an exact array key cannot be
        // a suffix of its neighbour. That door was not hypothetical -
        // `directoryiterator` has NO fixture of its own and exactly one live
        // site on this tree, so under `str_contains` it was covered by its
        // longer sibling's label. Refactor that one call and it becomes the
        // `globiterator` case again, invisibly. Under this check it becomes a
        // red demanding a fixture, which is the correct answer.
        $covered = [];
        foreach (self::knownAnswerSources() as $fixture) {
            $sites = self::classifyWalkSites($fixture);
            foreach ([...$sites['root'], ...$sites['unresolved']] as $label) {
                $name = strstr($label, '(', true);
                if ($name !== false) {
                    $covered[strtolower($name)] = true;
                }
            }
        }

        // NOT VACUOUS. An empty covered set would report every zero-site
        // spelling as uncovered, which fails CLOSED - but it would blame the
        // alphabet for a broken fixture set, and the next reader would go
        // looking in the wrong place. This puts the blame where it belongs.
        $this->assertGreaterThan(
            3,
            \count($covered),
            'running the shipped classifier over knownAnswerSources() produced almost no walker '
            . 'labels. Either those fixtures were rewritten or classifyWalkSites() stopped '
            . 'reporting; fix that rather than reading the correspondence failure below as a real '
            . 'gap in the alphabet.',
        );

        $uncovered = [];
        $zeroSite = [];
        foreach ($liveSiteCount as $spelling => $sites) {
            if ($sites !== 0) {
                continue;
            }

            $zeroSite[] = $spelling;
            if (!isset($covered[$spelling])) {
                $uncovered[] = $spelling;
            }
        }

        $this->assertNotSame(
            [],
            $zeroSite,
            'every spelling in the walker alphabet now has live sites on this tree, so the '
            . 'correspondence below ranges over nothing. That is a legitimate state - delete this '
            . 'pair of assertions when it becomes permanent - but while it holds, a newly added '
            . 'spelling is covered by nothing and nothing here says so.',
        );
        $this->assertSame(
            [],
            $uncovered,
            'a spelling this file declares in its walker alphabet has ZERO call sites on this tree '
            . 'AND no known-answer row in testTheWalkClassifierAnswersKnownInputsCorrectly(). '
            . 'Nothing exercises it: it reads as coverage, and if the classifier cannot in fact '
            . 'see that spelling, a file walking the tree that way is skipped in SILENCE rather '
            . 'than landing in the fail-closed residue - which is precisely how globiterator '
            . 'behaved before it was found. Write it a fixture there, in the shape the rows around '
            . 'it use.',
        );

        // THE REMOVAL HALF. The loop above cannot fail for a spelling that is no
        // longer in the constant, so the sizes are pinned - the same defect, and
        // the same one-line fix, as the two hand-maintained constants above.
        $this->assertCount(
            4,
            self::WALKER_CLASSES,
            'the class half of the walker alphabet has changed size. Adding a spelling is welcome '
            . 'and the loop above will exercise it - update this count in the same change-set. '
            . 'REMOVING one is the case this assertion exists for: a spelling with no live site on '
            . 'this tree can be dropped with every other assertion in this file staying green.',
        );
        $this->assertCount(
            3,
            self::WALKER_FUNCTIONS,
            'the function half of the walker alphabet has changed size - see the message above, '
            . 'which applies unchanged to glob/scandir/readdir.',
        );

        // NOT VACUOUS: at least one spelling in each half has live sites, so the
        // derivation above is reading a real tree rather than an empty one.
        $this->assertGreaterThan(0, array_sum(array_intersect_key($liveSiteCount, array_flip(self::WALKER_CLASSES))));
        $this->assertGreaterThan(0, array_sum(array_intersect_key($liveSiteCount, array_flip(self::WALKER_FUNCTIONS))));
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
            $source = self::readOrFail($absolute);
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
        return self::namesOneOf($source, self::walkingHelperNames());
    }

    /**
     * Which of `$helpers`, if any, does this source name as a TYPE?
     *
     * SPLIT OUT OF {@see walkingHelperUsedIn()} so that
     * {@see walkingHelperNames()} can use the same matcher while it is still
     * BUILDING the helper set - calling `walkingHelperUsedIn()` from there would
     * recurse into the memo it is computing.
     *
     * @param array<string, string> $helpers lower-cased name => declaring file
     */
    private static function namesOneOf(string $source, array $helpers): ?string
    {
        // THE ALIAS CHANNEL, travelled from F4's import-alias family and
        // pinned by {@see testAWalkingHelperReachedThroughAnAliasOrClassAliasIsStillChannelA()}:
        // `use SugarCraft\Crush\Tests\Support\TestFileWalkTrait as TF;` +
        // `use TF;` names the helper as surely as the plain import does — the
        // written last segment alone said it did not, which is the same
        // defeat, in the same file tree, the write-scanner took twice.
        // Consulted ADDITIVELY: the written name still answers first and the
        // map can only ADD a match, never subtract one.
        $aliases = self::importAliasMap(self::significant($source))['class'];

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
            $canonical = $aliases[$last] ?? null;
            if ($canonical !== null && isset($helpers[$canonical])) {
                return $canonical; // report the HELPER the alias names, not the alias
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

        $candidates = [];
        foreach (self::everyTestFile() as $relative => $absolute) {
            $relative = str_replace('\\', '/', $relative);
            if (str_ends_with($relative, 'Test.php')) {
                continue;
            }
            $candidates[$relative] = self::readOrFail($absolute);
        }

        // PASS 1: a helper that performs the walk itself.
        $helpers = [];
        $contributed = [];
        foreach ($candidates as $relative => $source) {
            if (self::classifyWalkSites($source)['root'] === []) {
                continue;
            }
            $contributed[$relative] = true;
            foreach (self::declaredTypeNames($source) as $name) {
                $helpers[strtolower($name)] ??= $relative;
            }
        }

        // PASS 2 TO A FIXPOINT: helpers that DELEGATE to one.
        $helpers = self::closeOverDelegates($candidates, $helpers, $contributed);
        ksort($helpers);

        return self::$helpers = $helpers;
    }

    /**
     * Extend a helper set with the helpers that DELEGATE to it, to a fixpoint.
     *
     * WHY THIS EXISTS, and it was a silent miss of exactly the shape this file is
     * about. A trait that does `use TestFileWalkTrait;` and returns
     * `self::everyTestFile()` walks the whole tree, but performs NO walk of its
     * own - so pass 1 of {@see walkingHelperNames()} cannot see it, because
     * {@see classifyWalkSites()} finds no site to root. And the residue cannot
     * catch the tests that use it either, for the same reason: a delegating helper
     * has no walker call site to be unaccounted FOR. So a real whole-`tests/`
     * guard reached through two hops was missed in SILENCE.
     *
     * MEASURED, by planting exactly that shape - a trait that consumes
     * `TestFileWalkTrait` and re-exports its walk, plus one test that uses only
     * the new trait. With pass 2 DISABLED: `testFiles` rises by one, so the file
     * was SEEN, while the roster does not move and `unaccounted` stays empty -
     * the planted guard is in no bucket at all and this file's own suite stays
     * green. With pass 2: the trait joins the alphabet, the roster rises by one,
     * and the planted test is that member. The deltas are the claim; no absolute
     * is recorded, per THE POPULATIONS ARE NOT PINNED IN PROSE.
     *
     * WHAT THAT MEASUREMENT ALSO SAYS, and it is a declared gap rather than a
     * hidden one: with no delegating helper in this tree, REMOVING the call to
     * this method from {@see walkingHelperNames()} changes nothing and reds
     * nothing. The fixpoint below is pinned by
     * {@see testTheHelperClosureFollowsADelegationChainOfAnyLength()}, which
     * drives it directly; its WIRING into the derivation is not, and cannot be
     * without planting a file under `tests/`.
     *
     * A FIXPOINT AND NOT ONE HOP, because the chain has no bound: a trait that
     * delegates to a trait that delegates. The loop terminates because each
     * iteration either adds a file to `$contributed` or sets `$added` false, and
     * `$candidates` is finite.
     *
     * PURE ON PURPOSE - it takes the sources rather than reading them, so
     * {@see testTheHelperClosureFollowsADelegationChainOfAnyLength()} can drive
     * chains this tree does not contain without planting files in `tests/`.
     *
     * @param  array<string, string> $candidates  relative path => source text
     * @param  array<string, string> $helpers     lower-cased name => declaring file
     * @param  array<string, bool>   $contributed files already in the set
     * @return array<string, string>
     */
    private static function closeOverDelegates(array $candidates, array $helpers, array $contributed = []): array
    {
        do {
            $added = false;
            foreach ($candidates as $relative => $source) {
                if (isset($contributed[$relative])) {
                    continue;
                }
                if (self::namesOneOf($source, $helpers) === null) {
                    continue;
                }
                $contributed[$relative] = true;
                $added = true;
                foreach (self::declaredTypeNames($source) as $name) {
                    $helpers[strtolower($name)] ??= $relative;
                }
            }
        } while ($added);

        return $helpers;
    }

    /**
     * The class/trait/interface/enum names DECLARED in one source.
     *
     * `Foo::class` IS EXCLUDED - `T_CLASS` is the same token there, and taking
     * the token after it would nominate whatever followed the expression. An
     * anonymous `new class` is excluded by the same test, since the token after
     * it is `(` or `{` rather than a name.
     *
     * `T_ENUM` IS IN THE LIST AND WAS NOT. The doc-block said
     * "class/trait/interface" and the filter matched it, so a walking - or
     * DELEGATING - helper declared as an `enum` contributed no name at all,
     * channel A could not key on it, and by {@see closeOverDelegates()}'s own
     * argument the tests reaching the tree only through it would be missed in
     * SILENCE, having no walker call site of their own to land in the residue.
     * That is the A4 defect one file over: a token class missing from an alphabet
     * AND from that alphabet's own statement of what it cannot express (rule 31).
     * MEASURED LATENT: no non-`*Test.php` file under `tests/` declares
     * an enum, so closing it changes no verdict today - it removes a future one,
     * for the cost of one token constant.
     *
     * @return list<string>
     */
    private static function declaredTypeNames(string $source): array
    {
        $tokens = self::significant($source);
        $names = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || !\in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
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
     * AN IMPORT ALIAS ADDS A SPELLING AND NEVER REPLACES ONE, and the
     * sentence had lived only in the OTHER scanner of this tree until F4
     * measured three SILENT escapes through the gap (§16.8 rule 40, the
     * correction that never travelled): `use function glob as g;` + `g($root)`,
     * `use RecursiveDirectoryIterator as Walk;` + `new Walk($root)`, and
     * `new \SplFileInfo($root)` iterated through `->getChildren()`. The first
     * two now resolve through {@see importAliasMap()} - the same additive
     * direction the write scanner took twice - and the third through the
     * `getChildren` channel below, which FAILS CLOSED on a receiver this
     * resolver cannot place. `namespace\glob(...)`, the write scanner's
     * twelfth defeat, is read here too for the same travel.
     *
     * @return array{root: list<string>, unresolved: list<string>}
     */
    private static function classifyWalkSites(string $source): array
    {
        $tokens = self::significant($source);
        $count = \count($tokens);
        $tainted = self::rootAnchoredNames($tokens);
        $aliases = self::importAliasMap($tokens);
        // THE SAME-FILE CHAIN, travelled from the write scanner's thirteenth
        // defeat the day review cycle 3 measured the classifier still reading
        // written names only: `class MyWalker extends \RecursiveDirectoryIterator
        // {}` + `new MyWalker($root)` is the walker under a name the alphabet
        // does not spell, and it was silently neither root nor residue - the
        // GlobIterator row's own criterion for a defect.
        $walkerSubclasses = self::sameFileWalkerSubclasses($tokens, $aliases['class']);

        $root = [];
        $unresolved = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || !\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            // THE RELATIVE SPELLING, travelled from the write scanner's
            // twelfth defeat: `namespace\glob(...)` in a global-namespace file
            // IS the global walker. A multi-segment relative name resolves to
            // a different symbol and is left exactly as written - no match,
            // like `Acme\glob()`, which names a different function.
            if ($token[0] === T_NAME_RELATIVE) {
                $relative = substr($name, \strlen('namespace\\'));
                if (!str_contains($relative, '\\')) {
                    $name = $relative;
                }
            }
            $canonicalClass = $aliases['class'][$name] ?? null;
            $canonicalFunction = $aliases['function'][$name] ?? null;
            $isClass = \in_array($name, self::WALKER_CLASSES, true)
                || ($canonicalClass !== null && \in_array($canonicalClass, self::WALKER_CLASSES, true))
                || isset($walkerSubclasses[$name])
                || ($canonicalClass !== null && isset($walkerSubclasses[$canonicalClass]));
            $isFunction = \in_array($name, self::WALKER_FUNCTIONS, true)
                || ($canonicalFunction !== null && \in_array($canonicalFunction, self::WALKER_FUNCTIONS, true));
            if (!$isClass && !$isFunction) {
                // THE getChildren CHANNEL - F4's third escape. `getchildren`
                // is neither a class nor a function in this alphabet; it is
                // the method by which a WALKER reached through an
                // `SplFileInfo` (or any iterator the resolver cannot place)
                // actually walks. Checked only here, after both alphabets
                // missed, so a walker-named token never spends this arm.
                if ($token[0] === T_STRING && $name === 'getchildren') {
                    self::classifyGetChildrenSite($tokens, $i, $tainted, $root, $unresolved);
                }

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
     * The `getChildren` channel of {@see classifyWalkSites()} - F4's third
     * escape, and the only one of the three that is not an import alias.
     *
     * `new \SplFileInfo(\dirname(__DIR__, 2) . '/src')` then iterates
     * `->getChildren()`: the walk happens WITHOUT the package root ever
     * appearing in a walker-named argument, and `getchildren` names neither
     * alphabet. MEASURED silent (0/0) through the shipped classifier at the
     * close review, with the two alias escapes beside it - all three now
     * classified, the aliases into `root` and this shape into `root` too when
     * the receiver resolves anchored.
     *
     * IT FAILS CLOSED, which is the whole design. A `->getChildren(` whose
     * receiver this resolver cannot place lands in the residue - the
     * GlobIterator row's own argument, quoted by the class doc-block: "a walk
     * this alphabet cannot see produces no site at all - so the file is
     * skipped in SILENCE rather than landing in the residue." A receiver that
     * resolves but is NOT anchored (`$tempIterator->getChildren()` over a
     * directory the test made) is a real unresolved site too - over-asking a
     * human for a residue row is the safe direction here, and
     * WALKS_A_DIRECTORY_THE_TEST_MADE exists to answer exactly that.
     *
     * @param array<string> $root
     * @param array<string> $unresolved
     */
    /**
     * Every class DECLARED IN THIS FILE whose `extends` chain reaches a
     * {@see WALKER_CLASSES} entry, as `declared name => walker spelling`.
     *
     * THE CLASSIFIER'S THIRTEENTH-DEFEAT TWIN, and review cycle 3, F-3, is
     * what it closes: the write scanner got this channel in the same step and
     * the classifier did not, so `class MyWalker extends
     * \RecursiveDirectoryIterator {}` + `new MyWalker(dirname(...))` produced
     * NO site in either bucket - skipped in SILENCE, the exact grade the
     * GlobIterator row was written to refuse. Fixpoint so declaration order
     * cannot matter; a parent spelled through `use … as …` or a two-literal
     * `class_alias` resolves through the merged class map; a parent imported
     * from another file keeps its chain out of reach and the blind-spot table
     * says so. A DECLARED subclass constructs nothing until a `new` names it -
     * the same polarity discipline as the write scanner's channel.
     *
     * THE KEYWORD SPELLINGS ARE NOT HERE: `new self` / `new static` /
     * `new parent` inside such a subclass resolve their target through the
     * ENCLOSING BODY, which the classifier carries no stack for; that shape
     * is a pinned blind-spot row, not a fake.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                         $classAliases
     *
     * @return array<string, string>
     */
    private static function sameFileWalkerSubclasses(array $tokens, array $classAliases): array
    {
        $parentOf = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (\is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }
            $declared = $tokens[$i + 1] ?? null;
            if (!\is_array($declared) || $declared[0] !== T_STRING) {
                continue;
            }
            $child = strtolower($declared[1]);

            for ($j = $i + 2; $j < $count; $j++) {
                $step = $tokens[$j];
                if (\is_array($step) && $step[0] === T_EXTENDS) {
                    $parent = $tokens[$j + 1] ?? null;
                    if (\is_array($parent) && \in_array($parent[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                        $pname = strtolower(ltrim($parent[1], '\\'));
                        if ($parent[0] === T_NAME_RELATIVE) {
                            $rel = substr($pname, \strlen('namespace\\'));
                            $pname = str_contains($rel, '\\') ? '' : $rel;
                        }
                        if ($pname !== '' && !str_contains($pname, '\\')) {
                            $parentOf[$child] = $pname;
                        }
                    }

                    break;
                }
                if ($step === '{') {
                    break;
                }
            }
        }

        $roots = [];
        do {
            $added = false;
            foreach ($parentOf as $child => $parent) {
                if (isset($roots[$child])) {
                    continue;
                }
                $spellings = [$parent];
                $resolved = $classAliases[$parent] ?? null;
                if ($resolved !== null && $resolved !== $parent) {
                    $spellings[] = $resolved;
                }
                foreach ($spellings as $spelling) {
                    if (\in_array($spelling, self::WALKER_CLASSES, true)) {
                        $roots[$child] = $spelling;
                        $added = true;

                        break;
                    }
                    if (isset($roots[$spelling])) {
                        $roots[$child] = $roots[$spelling];
                        $added = true;

                        break;
                    }
                }
            }
        } while ($added);

        return $roots;
    }

    private static function classifyGetChildrenSite(array $tokens, int $i, array $tainted, array &$root, array &$unresolved): void
    {
        $previous = $tokens[$i - 1] ?? null;
        if (!\is_array($previous) || !\in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return;
        }
        if (($tokens[$i + 1] ?? null) !== '(') {
            return;
        }

        [$end, $balanced] = self::closingBracket($tokens, $i + 1);
        $receiver = self::receiverExpression($tokens, $i - 2);
        $label = ($receiver['resolved'] ? $receiver['text'] : '<receiver-unreadable>')
            . '->getChildren(' . self::join($tokens, $i + 2, $end - 1) . ')';

        if (!$balanced) {
            $unresolved[] = 'UNBALANCED-ARGUMENT-LIST ' . $label;

            return;
        }
        if (!$receiver['resolved'] || !self::isRootAnchored($receiver['text'], $tainted)) {
            $unresolved[] = $label;

            return;
        }

        $root[] = $label;
    }

    /**
     * The primary expression a `->` chain hangs off, read BACKWARD from the
     * token before the operator.
     *
     * Three shapes, no more: a bare variable (`$f->getChildren()`, which the
     * taint resolver has already had its chances at), a balanced closer
     * (`(new \SplFileInfo($root))->getChildren()` or
     * `$this->walk()->getChildren()` - the opener is found by depth and the
     * primary's own head - `new`, a name, a variable - is taken with it), or
     * nothing this resolver may claim (`resolved: false`, which the caller
     * fails closed on). THE BACKWARD DEPTH COUNT IS PAIRED BY CLOSER CHARACTER
     * on purpose: over the text this walk can actually produce in a receiver
     * slot, a `)` always answers a `(` and a `]` a `[`; an unbalanced prefix
     * stops at the stream head and reports unresolved, never a truncated
     * guess dressed as a placement.
     *
     * @return array{text: string, resolved: bool}
     */
    private static function receiverExpression(array $tokens, int $beforeOperator): array
    {
        if ($beforeOperator < 0) {
            return ['text' => '', 'resolved' => false];
        }
        $token = $tokens[$beforeOperator];
        if (\is_array($token) && $token[0] === T_VARIABLE) {
            return ['text' => $token[1], 'resolved' => true];
        }
        if ($token !== ')' && $token !== ']') {
            return ['text' => self::text($token), 'resolved' => false];
        }

        $closer = $token;
        $opener = $closer === ')' ? '(' : '[';
        $depth = 0;
        $start = null;
        for ($j = $beforeOperator; $j >= 0; $j--) {
            $t = $tokens[$j];
            if ($t === $closer) {
                $depth++;

                continue;
            }
            if ($t === $opener) {
                $depth--;
                if ($depth === 0) {
                    $start = $j;

                    break;
                }
            }
        }
        if ($start === null) {
            return ['text' => self::join($tokens, 0, $beforeOperator), 'resolved' => false];
        }

        $head = $start;
        for ($k = $start - 1; $k >= 0; $k--) {
            $t = $tokens[$k];
            if (\is_array($t) && \in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_VARIABLE, T_NEW, T_DOUBLE_COLON], true)) {
                $head = $k;
                if ($t[0] === T_VARIABLE || $t[0] === T_NEW) {
                    break;
                }

                continue;
            }
            break;
        }

        return ['text' => self::join($tokens, $head, $beforeOperator), 'resolved' => true];
    }

    /**
     * `use function X as y;` / `use X as y;` and two-literal `class_alias`,
     * as `written alias => canonical short name`, by KIND.
     *
     * THE ALIAS CHANNEL THE CLASSIFIER NEVER HAD, and F4 is what it cost:
     * the write scanner in this same `tests/` tree has honoured import
     * aliases since `5cabca4a8` - twice, because the first honouring failed
     * OPEN by subtraction and had to be re-closed additively - while this
     * classifier matched WRITTEN last-segment text alone, so
     * `use function glob as g;` + `g($root)` was SILENT. An import alias
     * adds a spelling and never replaces one; this map is consulted
     * additively by {@see classifyWalkSites()} and {@see namesOneOf()}, and
     * a name absent from it is judged on its own written text exactly as
     * before.
     *
     * READ OFF THE TOKEN STREAM, because the write scanner's regex reader
     * is the cautionary tale: a `use function` inside a comment, doc-block
     * or string constant entered the map there, and with a rewrite-caller
     * that deleted a primitive from the alphabet for the whole file. Here a
     * whole comment is one token and a string literal is one token, so
     * neither can contain a T_USE. Comma lists, the braced group form and a
     * leading backslash all parse, for the write scanner's same reason:
     * each was a separate defeat before it was handled. A trait-use
     * conflict block's `{` ends the statement; a group brace is the one
     * preceded by a namespace separator; a closure's `use ( ... )` imports
     * variables and is skipped.
     *
     * `class_alias(A::class, 'b')` and `class_alias('A', 'b')` contribute
     * `b => a` to the CLASS kind - the addendum channel F4 named: a walking
     * helper (or a walker class) reached under a runtime alias is the same
     * defeat one construct over, and a two-literal alias is decidable from
     * this file alone. An alias built from a variable or a concatenation
     * is NOT contributed - this reads spellings and does not execute the
     * program, and the blind-spot table carries that shape as silence.
     *
     * WHY THIS IS A SECOND READER AND NOT A SHARED ONE:
     * {@see \SugarCraft\Crush\Tests\RuntimeTest::importedSymbolAliases()} is
     * the write scanner's twin, private to its class, and a consolidation
     * would move both into a `Support/` trait - a file this step's declared
     * list does not include. {@see DuplicatedTestHelperDriftTest} cannot
     * see the pair (it keys on method NAMES across files, its own
     * doc-block's admitted limit). Recorded in the step report rather than
     * silently grown.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{function: array<string, string>, class: array<string, string>}
     */
    private static function importAliasMap(array $tokens): array
    {
        $function = [];
        $class = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $head = $tokens[$i + 1] ?? null;
            if ($head === '(') {
                continue; // a closure's `use ( $var )` imports variables
            }
            $isFunction = \is_array($head) && $head[0] === T_FUNCTION;
            if (\is_array($head) && $head[0] === T_CONST) {
                continue; // `use const` belongs to neither alphabet
            }

            $map = $isFunction ? 'function' : 'class';
            $name = null;
            $alias = null;
            $sawAs = false;
            $previous = null;

            for ($j = $i + ($isFunction ? 2 : 1); $j < $count; $j++) {
                $item = $tokens[$j];

                if ($item === '{' && \is_array($previous) && $previous[0] === T_NS_SEPARATOR) {
                    $name = null;
                    $alias = null;
                    $sawAs = false;
                    $previous = $item;

                    continue;
                }

                if ($item === '{' || $item === '}' || $item === ',' || $item === ';') {
                    if ($name !== null) {
                        $segments = explode('\\', trim((string) $name, '\\'));
                        $short = strtolower((string) end($segments));
                        $written = strtolower((string) ($alias ?? $short));
                        // ADDITIVE AND IDENTITY-EXCLUDED: an alias that spells
                        // its own target says nothing the written name does
                        // not already say, and recording it would let a
                        // future caller confuse "imported" with "renamed".
                        if ($written !== $short) {
                            ${$map}[$written] = $short;
                        }
                    }
                    $name = null;
                    $alias = null;
                    $sawAs = false;
                    if ($item === ';' || $item === '{') {
                        break;
                    }
                    $previous = $item;

                    continue;
                }

                if (\is_array($item) && $item[0] === T_AS) {
                    $sawAs = true;
                    $previous = $item;

                    continue;
                }
                if (\is_array($item) && $item[0] === T_NS_SEPARATOR) {
                    $previous = $item;

                    continue;
                }
                // AN INTERPOLATION OPENER CANNOT LEGALLY APPEAR IN AN IMPORT
                // LIST, and this reader compares the bare `{` and `}` below -
                // the exact pair {@see \SugarCraft\Crush\Tests\Support\InterpolationOpenerTokenTest}
                // exists to police: a walk that counts bare braces while
                // ignoring `{$`/`${` loses a level somewhere, and here the
                // silence would end an import early. The `break` below already
                // ends the statement when it meets one (neither is a name, an
                // `as`, a separator or a bracket) - MEASURED EQUIVALENT, and
                // named rather than left to look accidental, exactly as
                // RuntimeTest names its two measured-equivalent guards.
                if (\is_array($item) && ($item[0] === T_CURLY_OPEN
                    || (\defined('T_DOLLAR_OPEN_CURLY_BRACES') && $item[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
                    break;
                }
                if (\is_array($item) && \in_array($item[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    if ($sawAs) {
                        $alias = $item[1];
                    } else {
                        $name = $item[1];
                    }
                    $previous = $item;

                    continue;
                }

                break; // anything else cannot be part of an import list
            }
        }

        // THE RUNTIME ALIAS, class kind only - `class_alias` has no function
        // counterpart in PHP. The CALL SPELLING resolves through the FUNCTION
        // map this method just built: review cycle 1 measured
        // `use function class_alias as ca; ca('RecursiveDirectoryIterator', 'RD');`
        // sailing through both this reader and its RuntimeTest twin - the
        // fourteenth and the F4-family fifteenth of the same construct, silent
        // and undeclared while the map already held `ca => class_alias`.
        // `class_alias(class: ..., alias: ...)` was its twin spelling.
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || !\in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
            if ($token[0] === T_NAME_RELATIVE) {
                $relative = substr($name, \strlen('namespace\\'));
                $name = str_contains($relative, '\\') ? $name : $relative;
            }
            $name = $function[$name] ?? $name;
            if ($name !== 'class_alias') {
                continue;
            }
            $previous = $i > 0 ? $tokens[$i - 1] : null;
            if (\is_array($previous) && \in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue; // a method or a declaration of that name is not the call
            }
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $open = $i + 1;
            $args = [];
            $current = '';
            $depth = 0;
            for ($j = $open; $j < $count; $j++) {
                $t = self::text($tokens[$j]);
                if ($t === '(') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($t === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($t === ',' && $depth === 1) {
                    $args[] = $current;
                    $current = '';

                    continue;
                }
                if ($depth >= 1) {
                    $current .= $t;
                }
            }
            $args[] = $current;
            if (\count($args) < 2) {
                continue;
            }

            // PAIR BY LABEL WHEN ONE CARRIES ONE (review cycle 2, F-A:
            // `class_alias(alias: 'V', class: X::class)` is legal PHP 8 and
            // the positional read paired it backwards, silently losing the
            // alias on BOTH instruments). A positional argument fills the
            // first unfilled signature slot, class then alias, which is the
            // only reading a legal mix can have; a third `exclusive` argument
            // has no slot and is ignored.
            $slots = ['class' => null, 'alias' => null];
            $positional = [];
            foreach ($args as $arg) {
                if (preg_match('~^([A-Za-z_][A-Za-z0-9_]*):(?!:)~', trim($arg), $lm) === 1) {
                    $label = strtolower($lm[1]);
                    if (array_key_exists($label, $slots) && $slots[$label] === null) {
                        $slots[$label] = substr(trim($arg), strlen($lm[0]));
                    }

                    continue;
                }
                $positional[] = $arg;
            }
            foreach (['class', 'alias'] as $slot) {
                if ($slots[$slot] === null && $positional !== []) {
                    $slots[$slot] = array_shift($positional);
                }
            }
            if ($slots['class'] === null || $slots['alias'] === null) {
                continue;
            }

            $targetFull = self::aliasLiteralName($slots['class']);
            $aliasFull = self::aliasLiteralName($slots['alias']);
            if ($targetFull === null || $aliasFull === null) {
                continue;
            }
            // the roster keys on the LAST segment (WALKER_CLASSES is spelled
            // short), so the target resolves to its last segment; the alias is
            // stored under BOTH its full name (what a site writes) and its
            // last segment (what a `use Solo\NS`-style bare read writes),
            // because `class_alias(X::class, 'Solo\NS')` registers the whole
            // name `\Solo\NS` - review cycle 2, F-B, silent on both
            // instruments while keyed by last segment alone.
            $sep = strrpos($targetFull, '\\');
            $target = $sep === false ? $targetFull : substr($targetFull, $sep + 1);
            $sep = strrpos($aliasFull, '\\');
            $aliasLast = $sep === false ? $aliasFull : substr($aliasFull, $sep + 1);
            $class[$aliasFull] ??= $target;
            $class[$aliasLast] ??= $target;
        }

        return ['function' => $function, 'class' => $class];
    }

    /**
     * The lowercased short name ONE argument of `class_alias` spells: a
     * quoted string or a `X::class` constant, nothing else.
     *
     * The argument arrives as joined token text rather than as a token list -
     * a literal argument is then exactly one shape wide (`'Acme\File'`,
     * `"Acme\\File"`, `Acme\File::class`), and everything else - variable,
     * concatenation, interpolation - contains a character no name token
     * carries and returns null. This reader resolves SPELLINGS; the
     * enumeration of what it cannot see is in
     * {@see testTheAlphabetsOwnBlindSpotsAreWhereThisFileSaysTheyAre()}.
     */
    private static function aliasLiteralName(string $argument): ?string
    {
        $argument = trim($argument);
        if ($argument === '') {
            return null;
        }

        // A leading NAMED-ARGUMENT LABEL is normally stripped upstream by the
        // pairing loop, but this reader is called directly too, so the guard
        // stays; a ternary's `?` or `??` breaks the shape before a bare colon,
        // so `a ? 'x' : 'y'` is not a label and falls through to null below.
        if (preg_match('~^[A-Za-z_][A-Za-z0-9_]*:(?!:)~', $argument, $label) === 1) {
            $argument = trim(substr($argument, strlen($label[0])));
        }

        $body = null;
        $quote = "'";
        if (preg_match("~^(['\"])(?<name>.*)\\1$~s", $argument, $m) === 1) {
            $body = $m['name'];
            $quote = $m[1];
        } elseif (preg_match("~^<<<'(?<label>\w+)'\n(?<name>.*)\n\\k<label>$~s", $argument, $m) === 1) {
            // NOWDOC (review cycle 3, F-2): the body is literal verbatim —
            // the readers used to refuse `class_alias(A::class, <<<'EOT'
            // W
            // EOT);` entirely, and PHP still registered the alias, truncated
            // the target, and both instruments answered nothing. An
            // interpolated body is impossible in a nowdoc by definition.
            // VERBATIM: a nowdoc body carries no escapes at all, so `\\` in
            // source is two backslashes in the runtime name — the shape
            // check below refuses it, as the engine would refuse the name.
            $body = rtrim($m['name'], "\r\n");
            $quote = "\x00";
        } elseif (preg_match("~^<<<(?<label>\w+)\n(?<name>.*)\n\\k<label>$~s", $argument, $m) === 1) {
            // Double-quoted HEREDOC: same escape law as `""`; the join of
            // the significant stream carries the whole body as ONE run here
            // precisely when it is interpolation-free, because an
            // interpolated body would have joined `$`/`{`-bearing extra
            // tokens into a text this shape refuses below at the decode.
            $body = rtrim($m['name'], "\r\n");
            $quote = '"';
        }

        if ($body !== null) {
            $decoded = self::decodeAliasStringBody($body, $quote);
            if ($decoded === null) {
                return null;
            }
            $full = strtolower(ltrim($decoded, '\\'));

            return preg_match('~^[a-z_][a-z0-9_]*(?:\\\\[a-z_][a-z0-9_]*)*$~', $full) === 1 ? $full : null;
        }

        if (preg_match('~^(?<name>[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::class$~', $argument, $m) === 1) {
            return strtolower($m['name']);
        }

        return null;
    }

    /**
     * The runtime value of a string literal's BODY, for a class name.
     *
     * A class_alias alias is a STRING, so `'Solo\\NS'` in source spells the
     * runtime name `Solo\NS` - the double backslash is ONE separator, and it
     * is the single-backslash form the construction SITE writes (`new
     * Solo\NS` is one T_NAME_QUALIFIED token with one separator), so the two
     * only line up after this unescape (review cycle 2, F-B: last-segment
     * keying on the still-escaped text matched neither). Single quotes: only
     * `\\` and `\'` escape; every other backslash stands, so a bare namespace
     * separator typed as one backslash (legal in source) survives too. Double
     * quotes: ONLY `\\` is name-safe; a body still holding a lone backslash
     * carries an escape (`\n`, `\x4e`) whose value this reader refuses to
     * compute - null, and the blind-spot table names it.
     */
    private static function decodeAliasStringBody(string $body, string $quote): ?string
    {
        if ($quote === "\x00") {
            // VERBATIM (nowdoc): no escape law applies; the body IS the name.
            return $body;
        }

        if ($quote === "'") {
            if (str_contains($body, "\x00")) {
                return null;
            }
            $out = str_replace(["\\\\", "\\'"], ["\x00", "'"], $body);

            return str_replace("\x00", '\\', $out);
        }

        $out = str_replace('\\\\', "\x00", $body);
        if (str_contains($out, '\\')) {
            return null;
        }

        return str_replace("\x00", '\\', $out);
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
     * "ANY DEPTH" IS NOW LOAD-BEARING RATHER THAN ASPIRATIONAL. This phrase
     * and the class doc-block's copy of it were BOTH false while the loop below
     * ran a fixed eight passes: one rule carries the taint one link per pass, so
     * eight passes is a depth limit, and a reviewer measured a nine-link chain
     * truncating. It fails CLOSED - the site drops into the residue and the
     * unaccounted-for test reds - so the cost was a reader sent to the wrong
     * repair rather than a missed guard. The cap is gone (the loop already
     * stopped on a pass that added nothing, and the taint set only grows and is
     * bounded by the distinct names in one file, so nothing but the cap was ever
     * arbitrary), and the claim is pinned by
     * {@see testTheRootTaintFixpointResolvesAChainDeeperThanTheOldEightPassCap()}.
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

        do {
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

        } while (\count($tainted) !== $before);

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
     * DELEGATED, NOT DECLARED. The body here used to be its own
     * `array_filter(token_get_all(...))` over exactly
     * `[T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]` - a private re-declaration of
     * {@see DropsInsignificantTokensTrait::significantTokens()}, which is the
     * consolidated helper for precisely this and has other consumers, one of them
     * `tests/RuntimeTest.php` in this same change-set, whose own comment says
     * "shared rather than privately re-declared".
     *
     * WHY THE WRAPPER STAYS instead of the call sites moving. The name
     * `significant()` is what a dozen doc-blocks in this file cite, and a
     * one-line delegation cannot drift from the trait the way a copied body can.
     * That is the whole point: `DuplicatedTestHelperDriftTest` keys on the method
     * NAME, so a RENAMED copy is out of reach of the guard by construction -
     * which is exactly the shape this was, and the only reason it went unreported
     * until somebody read the two bodies side by side.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function significant(string $source): array
    {
        return array_values(self::significantTokens($source));
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
