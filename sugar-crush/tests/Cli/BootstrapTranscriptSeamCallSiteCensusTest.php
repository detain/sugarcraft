<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Support\FlattensSourceProseTrait;

/**
 * How many of {@see \SugarCraft\Crush\Cli\Bootstrap}'s launch warnings are
 * routed onto the transcript seam — counted, not remembered.
 *
 * WHY THIS FILE EXISTS. The number is quoted in prose in TEN places (this
 * file's {@see PROSE_SITES}) and it has gone stale three rounds running: it
 * read FOURTEEN when the answer was fifteen (round 42, E78), then still
 * FOURTEEN when the answer was sixteen (round 43, E86), and round 44 (E97)
 * found three sites still saying fourteen. A count that lives only in prose
 * drifts every time a call site is added, and nothing goes red. This file is
 * the thing that goes red.
 *
 * WHAT `PROSE_SITES` SAID WHEN IT WAS WRITTEN: "four places". WHAT IS TRUE NOW:
 * ten, and the four it had were not even the whole of what round 44 had
 * already touched — two more sentences quoting the count live inside files the
 * list ALREADY covered, and were left unanchored because the list was built by
 * remembering which sentences had been edited rather than by searching for the
 * ones that quote the number. Round 44's review mutated one of those
 * unanchored copies to FOURTEEN and the suite stayed green. WHY THE COUNT OF
 * SITES IS STATED HERE AT ALL rather than left to `count(self::PROSE_SITES)`:
 * a reader needs to know whether the list is a census or a sample, and the
 * only honest answer is the one with a method attached — see
 * {@see PROSE_SITES} for the search that produced it and for the one sentence
 * deliberately NOT in it.
 *
 * WHAT `grep` CAN AND CANNOT DO HERE, restated because the previous version of
 * this paragraph said "`grep` IS THE WRONG TOOL" flatly and that is not true.
 * MEASURED on this tree: `grep -c 'self::warnPermissionConfigInTranscript('
 * src/Cli/Bootstrap.php` gives 16 — the right answer, and the recipe
 * `docs/SETTINGS.md` quotes. `grep -c 'warnPermissionConfigInTranscript'`
 * gives 31, because most occurrences are the declaration and `{@see …}`
 * references inside doc-blocks explaining the split. So the trap is grepping
 * the bare IDENTIFIER, not grepping. WHY THE TOKEN SCAN STILL EARNS ITS PLACE
 * over the working `grep`: the `self::`-plus-paren form is a text pattern that
 * happens to coincide with the truth today. It misses a call whose `(` is put
 * on the next line, it counts a doc-block that quotes the call form verbatim
 * (which rule 7's three-part form actively invites — this very paragraph does
 * not do it only because it is careful to), and it counts a first-class
 * callable. {@see testANaiveSubstringCountIsLargerThanTheRealCallCount()} pins
 * the identifier-vs-call gap so a reader who gets 31 has a test telling them
 * why.
 *
 * HOW THE COUNT IS DEFINED. Strip T_WHITESPACE, T_COMMENT and T_DOC_COMMENT
 * from `token_get_all()`, then count every T_STRING equal to the method name
 * that is BOTH preceded by a scope token (`::` or `->`) AND followed by `(`
 * that is not the `(...)` of a first-class callable.
 *
 * WHAT EACH CLAUSE BUYS, and three of the four sentences that used to answer
 * this were wrong or unpinned (E228). WHAT IT SAID: "Stripping whitespace
 * first is what makes it survive a call reformatted across lines; requiring
 * the scope token is what excludes the `function` declaration; requiring the
 * paren is what excludes a string reference and a `[self::class, 'name']`
 * callable array."
 *
 * WHAT IS TRUE NOW, MEASURED clause by clause on PHP 8.3.6 by removing each
 * one from {@see countSeamCallSites()} and re-running every row of
 * {@see scanDefinitionCases()}:
 *
 *  - THE SCOPE-TOKEN CLAUSE was the one sentence that held. Removing it takes
 *    `the declaration` and `a bare function of the same name` from 0 to 1, so
 *    both rows kill it.
 *  - THE WHITESPACE STRIP was described correctly and pinned by nothing. With
 *    it removed, `a call reformatted across lines` still answers 1 — that
 *    fixture wraps INSIDE the argument list, where no whitespace ever sat
 *    between the tokens the scan reads. Not one provider row changed answer.
 *    The row that does kill it is `a call whose scope operator is spaced`,
 *    which puts the whitespace where the scan actually looks.
 *  - THE COMMENT STRIP was not claimed to buy anything and buys the same thing
 *    one token over: a block comment is legal between the `::` and the method
 *    name, and with the strip gone the scan reads a T_COMMENT as the preceding
 *    token and scores the call 0. Removing the strip took no provider row off
 *    its expected answer either; `a call with a comment between the scope
 *    operator and the name` is what now kills it.
 *  - THE PAREN CLAUSE sentence was FALSE. Removing the clause leaves both
 *    shapes it names at 0: a quoted method name in `[self::class, 'name']` and
 *    a string spelling the call form are `T_CONSTANT_ENCAPSED_STRING`, so it
 *    is the T_STRING requirement that excludes them and the paren has nothing
 *    to do with it. What the paren clause really excludes is a CLASS-CONSTANT
 *    reference of the same name — the method name after `::` with no call
 *    after it — which scans 1 without the clause, and which no row covered.
 *
 * WHY THE PARAGRAPH STILL EARNS ITS PLACE rather than being deleted along with
 * its errors: a reader deciding whether a clause can be simplified away needs
 * to know what each one is for, and three of these four clauses genuinely are
 * load-bearing. The repair is that every claim above is now a row in
 * {@see scanDefinitionCases()} that goes red when the clause it describes is
 * removed, so the next version of this paragraph cannot drift from the code
 * without something noticing.
 *
 * THE `(...)` CLAUSE IS NOT REDUNDANT, and the sentence it replaces claimed it
 * was. WHAT IT SAID: "requiring the paren is what excludes a
 * first-class-callable or a string reference." WHAT IS TRUE NOW, measured on
 * PHP 8.3.6: `self::warnPermissionConfigInTranscript(...)` lexes as T_STRING,
 * `(`, T_ELLIPSIS, `)` — the paren is immediately after the name, so the paren
 * requirement does not exclude it at all, and round 44's review planted one and
 * got a seventeenth call site out of the scan. WHY THIS MATTERS despite there
 * being no first-class callable of this method today: the failure is a
 * FABRICATED extra site, which reads as "someone added a call" and gets
 * answered by editing ten prose sentences to a wrong number. Every branch of
 * the definition above is exercised on synthetic sources by
 * {@see testTheScanDefinitionAgreesWithItsDocBlock()}, so the definition is
 * tested rather than merely written down.
 *
 * As a one-liner, for a reader who wants the number without a test run
 * (whitespace-sensitive, and it does NOT carry the `(...)` clause, so it
 * agrees with the scan only while no first-class callable exists — run it from
 * `sugar-crush/`, and note it dies rather than answering 0 from the wrong cwd,
 * which is what the previous version of this recipe did):
 *
 *     php -r '$t=token_get_all(@file_get_contents("src/Cli/Bootstrap.php")
 *     ?: die("run this from sugar-crush/\n"));$n=0;
 *     foreach($t as $i=>$x){if(is_array($x)&&$x[0]===T_STRING
 *     &&$x[1]==="warnPermissionConfigInTranscript"&&is_array($t[$i-1]??null)
 *     &&$t[$i-1][0]===T_DOUBLE_COLON&&($t[$i+1]??null)==="("){$n++;}}echo $n,"\n";'
 *
 * WHERE THE CONSTANT LIVES, AND WHY THIS FILE NO LONGER KEEPS ONE. WHAT THIS
 * PARAGRAPH SAID: "a `public const TRANSCRIPT_SEAM_CALL_SITES` on `Bootstrap`
 * itself would be the better home … when that happens,
 * `EXPECTED_CALL_SITES` becomes a second opinion rather than the only one, and
 * this test should assert the two agree." WHAT IS TRUE NOW: E118 landed in
 * round 45 and the constant is
 * {@see \SugarCraft\Crush\Cli\Bootstrap::TRANSCRIPT_SEAM_CALL_SITES}, but
 * the second half of that prescription was NOT implemented, on purpose. Two
 * hand-maintained integers that must agree with each other carry no more
 * evidence than one — neither is measured, the token scan is the only oracle
 * either can be checked against, and the pair's whole effect is that the next
 * person to add a call site has two places to bump instead of one. So this
 * file keeps no count of its own and reads `Bootstrap`'s.
 * WHY THE ORIGINAL REASONING STILL EARNS ITS PLACE: the argument for MOVING it
 * was right and is the reason it moved — the constant now sits in the file that
 * changes when the count changes, which is the file the next author has open.
 * (E118 and not E104: round 44's brief cited E104 for this, and E104 is a
 * different item — extracting `Bootstrap`'s stderr and launch-report `sprintf`
 * FORMATS to constants, which round 45 did in the same commit. The two are
 * neighbours and wanted the same treatment; they are not the same entry.)
 *
 * @see \SugarCraft\Crush\Tests\Cli\BootstrapLaunchNoticeRoutingTest for the
 *      behavioural guard — which call sites actually reach the transcript on a
 *      real launch. This file only counts them; that file proves they work.
 */
final class BootstrapTranscriptSeamCallSiteCensusTest extends TestCase
{
    /**
     * WHY THIS CONSUMER USES `flattened()` IN ONE PLACE AND NOT THE OTHER, kept
     * here rather than moved into the trait because it is true of this file
     * only (E224). {@see testTheProseSiteCountInThisFilesOwnDocBlocksMatchesTheList()}
     * matches against the flattened source; {@see testTheProseCountsMatchTheTokenScan()}
     * deliberately does NOT: those ten anchors each carry their own explicit
     * handling of the wrap they cross, they are proven against the files they
     * name, and re-pointing ten working anchors at a different input to gain
     * nothing is how a guard gets broken by its own improvement.
     *
     * BOTH NUMBERS IN THAT SENTENCE ARE ANCHORED, by
     * {@see SELF_COUNT_ANCHORS}, which is why the sentence lives in this file
     * and not in the trait: the anchors name the file they read, and a
     * paragraph about one consumer sitting in shared code would be a claim
     * about every consumer.
     */
    use FlattensSourceProseTrait;

    private const SEAM_METHOD = 'warnPermissionConfigInTranscript';

    /**
     * Every prose sentence that quotes the count, with the anchor that finds it
     * and how the number it should quote relates to the census.
     *
     * `offset` is what the sentence subtracts from the census: a site saying
     * "the OTHER fifteen" is speaking from inside one of the sixteen, so its
     * offset is 1. A site saying "SIXTEEN of Bootstrap's call sites are routed
     * here" speaks from outside and has offset 0.
     *
     * The anchors are deliberately the STABLE half of each sentence with the
     * number word as the only capture. A rewrite that drops the anchor makes
     * this test go red rather than silently pass on zero matches — see
     * {@see testTheProseCountsMatchTheTokenScan()}.
     *
     * HOW THIS LIST WAS BUILT, since the four rows it started with were built
     * by memory and round 44's review found five more. The search, re-runnable
     * from `sugar-crush/` (GNU grep 3.11 on this box; nothing here is
     * PHP-version-sensitive):
     *
     *     grep -rniE '\b(eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen)\b' \
     *         --include=*.php --include=*.md src tests docs README.md
     *
     * then keep the hits whose subject is the transcript seam. That is a wide
     * net — this tree spells a great many unrelated counts in words, the
     * sixteen fields of an `AgentPreset` and the eleven wired tools among them
     * — so the filtering step is a human reading each hit, and THAT is the part
     * that can go stale. It is written down anyway, because "nine" with a
     * flawed method beats "four" with none. BOTH OF THOSE WORDS ARE
     * HISTORICAL FIGURES — what the two searches produced when the sentence
     * was written — and the list is TEN now. They are deliberately not
     * corrected and deliberately not anchored by
     * {@see SELF_COUNT_ANCHORS}: correcting a quoted measurement to a later
     * measurement destroys the comparison the sentence exists to make.
     *
     * ONE SENTENCE USED TO BE DELIBERATELY NOT A ROW, AND THE PATTERN THAT
     * CLOSED IT IS THE PART WORTH KEEPING. WHAT THIS SAID:
     * "`Bootstrap::reportSkillSkips()`'s comment says a transcript 'also has to
     * carry ELEVEN other sources' and should say fifteen … this round's lane
     * does not own `src/Cli/Bootstrap.php` … adding this row would red the
     * suite until the sentence is corrected", and it named a test,
     * `testTheKnownStaleSentenceOutsideThisLaneIsStillStale()`, that ASSERTED
     * the gap: it failed the day the sentence was corrected, and its failure
     * message was the instruction for closing it. WHAT IS TRUE NOW: E119 landed
     * in round 45, the sentence says fifteen, the row is the
     * `Bootstrap::reportSkillSkips()` entry below, and that test is gone —
     * deleted in the commit that corrected the sentence, exactly as its own
     * message instructed.
     * WHY THE HISTORY STILL EARNS ITS PLACE, and this is the transferable part:
     * a lane that finds a defect in a file it may not touch has three options —
     * write it in a report nobody reads, leave a `TODO` nothing enforces, or
     * ASSERT THE DEFECT and make the assertion's failure message the repair
     * instruction. Only the third one closes. The cost is a test that reads
     * like a lie ("still stale" as a passing assertion) and that cost is worth
     * paying; the requirement is that the message says, in full, what to do
     * when it fails. Reach for this shape the next time a census has a hole
     * with an owner on the other side of it.
     *
     * @var array<string, array{file: string, anchor: string, offset: int}>
     */
    private const PROSE_SITES = [
        'Chat::withLaunchNotices() doc-block' => [
            'file' => 'src/Chat.php',
            'anchor' => '/\b([A-Za-z]+) OF \{@see \\\\SugarCraft\\\\Crush\\\\Cli\\\\Bootstrap\}\'S LAUNCH-WARNING CALL/',
            'offset' => 0,
        ],
        'BootstrapLaunchNoticeRoutingTest class doc-block, "the other N"' => [
            'file' => 'tests/Cli/BootstrapLaunchNoticeRoutingTest.php',
            'anchor' => '/this file is the guard for the other ([a-z]+)\b/i',
            'offset' => 1,
        ],
        'BootstrapLaunchNoticeRoutingTest class doc-block, "holds N calls"' => [
            'file' => 'tests/Cli/BootstrapLaunchNoticeRoutingTest.php',
            'anchor' => '/holds ([A-Za-z]+) calls to the seam by a token scan/',
            'offset' => 0,
        ],
        'BootstrapLaunchNoticeRoutingTest skipped-skills case, "carries N other sources"' => [
            'file' => 'tests/Cli/BootstrapLaunchNoticeRoutingTest.php',
            'anchor' => '/safe to seat in a transcript that also carries\s+\*?\s*([a-z]+)\b/i',
            'offset' => 1,
        ],
        'BootstrapLaunchNoticeRoutingTest skipped-skills case, "N seam call sites"' => [
            'file' => 'tests/Cli/BootstrapLaunchNoticeRoutingTest.php',
            'anchor' => '/\(([a-z]+) seam call sites by the token scan/i',
            'offset' => 0,
        ],
        'McpToolWiringTest partly-started-config case' => [
            'file' => 'tests/Integration/McpToolWiringTest.php',
            'anchor' => '/is not inherited from the other\s+\*?\s*([a-z]+) call sites/i',
            'offset' => 1,
        ],
        'Bootstrap::reportSkillSkips(), "carries N other sources"' => [
            'file' => 'src/Cli/Bootstrap.php',
            'anchor' => '/also has to carry\s+(?:\/\/)?\s*([a-z]+) other sources/i',
            'offset' => 1,
        ],
        'Bootstrap::chat(), the last-read comment' => [
            'file' => 'src/Cli/Bootstrap.php',
            'anchor' => '/([A-Za-z]+) call sites now routed onto the transcript seam/',
            'offset' => 0,
        ],
        'Bootstrap::mcpClient() catch, the driven-reachability comment' => [
            'file' => 'src/Cli/Bootstrap.php',
            'anchor' => '/not inherited from the other\s+(?:\/\/)?\s*([a-z]+) call sites/i',
            'offset' => 1,
        ],
        'docs/SETTINGS.md, the transcript-seam paragraph' => [
            'file' => 'docs/SETTINGS.md',
            'anchor' => '/\*\*([a-z]+)\*\* call sites in total/i',
            'offset' => 0,
        ],
    ];

    /**
     * The sentences in THIS file that state how many {@see PROSE_SITES} rows
     * there are, so that count has a generator too.
     *
     * WHY A CENSUS OF THE CENSUS, which does look like a joke. This class's
     * doc-block argues at length that a number living only in prose goes stale,
     * and then states its own count of prose sites in every sentence listed
     * below, in prose, with nothing checking any of them. It read "four" when
     * the list held four, then "nine", and round 45 made it ten — every step a
     * hand-edit across all of them, which is the same failure mode one rung up.
     * WHERE THE RECURSION STOPS, and it did not stop where this paragraph
     * claimed. WHAT IT SAID: "(No numeral in this paragraph, deliberately … it
     * stops at `count(self::SELF_COUNT_ANCHORS)`, WHICH IS NOT QUOTED
     * ANYWHERE.)" WHAT IS TRUE NOW: it was quoted twice, both times in
     * {@see testTheProseSiteCountInThisFilesOwnDocBlocksMatchesTheList()} —
     * "an assertion of the form `all seven anchors matched`" and "turning the
     * seven assertions below into vacuous passes" — and both said SEVEN while
     * the list held eight rows. The clause asserting the number was not quoted
     * is precisely what made two wrong copies of it invisible, in the file
     * whose entire subject is unanchored numbers in prose.
     * WHY THE RULE STILL EARNS ITS PLACE: the recursion does have to stop, or a
     * sentence counting the sentences that count the sentences needs a row of
     * its own. It stops HERE, one level up, and the way it stops is that
     * `count(self::SELF_COUNT_ANCHORS)` is NOT SPELLED OUT ANYWHERE — the two
     * sentences that used to spell it now say "every anchor" and "the
     * assertions below", which do not go stale when a row is added. If you find
     * yourself wanting to write the number, add the row instead.
     *
     * The generator for the level below is `count(self::PROSE_SITES)`, which
     * cannot drift from the list because it IS the list.
     *
     * MATCHED AGAINST A FLATTENED COPY OF THE SOURCE, never the raw bytes. A
     * doc-block wraps at 80 columns with ` * ` on every continuation, so a
     * sentence is never those bytes in a row — round 44 shipped an
     * `assertStringNotContainsString(<sentence>, $rawSource)` that survived
     * re-adding the very sentence it existed to forbid. {@see flattened()}
     * removes the continuation markers first; its own correctness is checked on
     * a synthetic wrapped fixture in
     * {@see testTheProseSiteCountInThisFilesOwnDocBlocksMatchesTheList()},
     * because a flattener that silently produced nothing would make every
     * anchor below fail open into a zero-match — which this test treats as a
     * failure, not a skip.
     *
     * WHAT IS DELIBERATELY NOT HERE, AND THE CRITERION — because "I did not
     * think of it" and "it is excluded" look identical from the outside, and
     * round 45's review found four sentences that were the first while reading
     * as the second. THE CRITERION IS TENSE. A sentence saying what the count
     * IS gets a row; a sentence saying what the count WAS is a quoted
     * measurement, and correcting one to a later measurement destroys the
     * comparison it exists to make. Two sentences are excluded under it. One is
     * `"nine" with a flawed method beats "four" with none`, in
     * {@see PROSE_SITES}' doc-block. The other is the sentence three paragraphs
     * up that walks the count from four through nine to its value today: it is
     * a HISTORY, its last clause is dated by the round that produced it, and a
     * later round correcting that clause upward would be writing false history
     * rather than fixing a stale number.
     *
     * EVERYTHING ELSE IN THE PRESENT TENSE IS A ROW. Round 45 added four, and
     * they are quoted here with the number elided — `the list is … now`,
     * `all … anchors match exactly once today`, `those … anchors each carry`,
     * `re-pointing … working anchors` — because spelling one contiguously in
     * this file makes it a SECOND match for its own anchor and reds the
     * uniqueness assertion on the scaffolding. Measured: it did, on the first
     * attempt at this paragraph. All four were written by the same lane that
     * built this list, in the same commit, and none was pinned. That is not an
     * argument against the machinery; it is the measurement of how fast a
     * number in prose escapes it, taken on the author who was thinking about
     * the problem hardest.
     *
     * IT IS NOT CONFINED TO THIS FILE, and that is the whole reason it is a
     * list of `{file, anchor}` and not a list of patterns. E118 moved the count
     * of CALL sites onto `Bootstrap`, and the doc-block that went with it states
     * the number of PROSE sites twice — in `src/`, where nothing in `tests/`
     * would have looked. A self-census that only scans itself is aimed at the
     * one file whose author was thinking about the problem.
     *
     * @var list<array{file: string, anchor: string}>
     */
    private const SELF_COUNT_ANCHORS = [
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/quoted in prose in ([A-Za-z]+) places/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/WHAT IS TRUE NOW: ([a-z]+), and the four it had/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/answered by editing ([a-z]+) prose sentences to a wrong number/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/one integer that ([a-z]+) prose sentences are then corrected against/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/the repair is ([a-z]+) sentences edited/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/rather than in the number ([a-z]+) sentences are corrected against/'],
        ['file' => 'src/Cli/Bootstrap.php',
            'anchor' => '/quoted in prose in ([a-z]+) sentences across/'],
        ['file' => 'src/Cli/Bootstrap.php',
            'anchor' => '/Bump this, bump the ([a-z]+) sentences, in one commit/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/and the list is ([A-Z]+) now/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/all ([a-z]+) anchors match exactly once today/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/those ([a-z]+) anchors each carry/'],
        ['file' => 'tests/Cli/BootstrapTranscriptSeamCallSiteCensusTest.php',
            'anchor' => '/re-pointing ([a-z]+) working anchors/'],
    ];

    /**
     * Only the words a count in this family could plausibly take. A number word
     * outside this map is not "unknown, skip it" — it is a sentence this guard
     * cannot read, and {@see testTheProseCountsMatchTheTokenScan()} fails on
     * it rather than passing. A guard that quietly ignores what it cannot parse
     * has a hole shaped exactly like the next defect.
     *
     * @var array<string, int>
     */
    private const NUMBER_WORDS = [
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
        'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20,
    ];

    /**
     * `Bootstrap`'s own declaration of the count agrees with the token scan.
     *
     * The DECLARATION is the thing under test and the scan is the oracle, which
     * is why the constant is not simply computed: the point of
     * {@see \SugarCraft\Crush\Cli\Bootstrap::TRANSCRIPT_SEAM_CALL_SITES} is
     * to be a statement a human wrote in the file a human edits, and a statement
     * nothing can contradict is not a statement.
     */
    public function testTheTokenScanFindsExactlyTheExpectedNumberOfCallSites(): void
    {
        self::assertSame(
            Bootstrap::TRANSCRIPT_SEAM_CALL_SITES,
            self::countSeamCallSites(),
            'A call site was added to or removed from Bootstrap::warnPermissionConfigInTranscript(). '
                . 'Update Bootstrap::TRANSCRIPT_SEAM_CALL_SITES and every sentence in self::PROSE_SITES '
                . 'in the same commit.',
        );
    }

    /**
     * `grep -c` overstates the count, and this is the assertion that says so in
     * a place a reader will find. It does not pin the prose occurrences to an
     * exact number — that would go red on every doc-block edit and teach people
     * to update the number without reading it. It pins the DIRECTION: naive
     * substring counting is strictly larger, so a number obtained that way is
     * always wrong.
     */
    public function testANaiveSubstringCountIsLargerThanTheRealCallCount(): void
    {
        $source = self::bootstrapSource();
        $naive = substr_count($source, self::SEAM_METHOD);

        self::assertGreaterThan(
            self::countSeamCallSites(),
            $naive,
            'grep no longer overstates the count — if the doc-block prose that made it overstate has gone, '
                . 'the warning against grepping in this class doc-block needs rewriting, not deleting.',
        );
    }

    /**
     * Every prose sentence quoting the count agrees with the token scan.
     *
     * This is the assertion that makes E97 not happen a fourth time. An anchor
     * that stops matching is a FAILURE, not a skip: a sentence rewritten past
     * its anchor is exactly the moment a stale number gets planted, so the
     * guard demands the maintainer re-point it deliberately.
     */
    public function testTheProseCountsMatchTheTokenScan(): void
    {
        $census = self::countSeamCallSites();

        foreach (self::PROSE_SITES as $label => $site) {
            $path = \dirname(__DIR__, 2) . '/' . $site['file'];
            self::assertFileExists($path, "{$label}: the file quoting the count has moved");

            $source = (string) file_get_contents($path);

            // preg_match_ALL, and the count asserted. `preg_match()` returns 1
            // for "the first of two", so it is a presence check wearing a
            // uniqueness check's clothes — the defect
            // {@see \SugarCraft\Crush\Tests\Config\ReadmeRosterDriftTest}
            // measured in its own locators and fixed a round before this file
            // did. It matters MORE here than there: a second sentence matching
            // the same anchor is by construction a second copy of the count,
            // and this guard would have read the first one and reported
            // agreement while the second said something else. MEASURED on this
            // tree, PHP 8.3.6: all ten anchors match exactly once today, so
            // this tightening reds on nothing that exists.
            $matched = preg_match_all($site['anchor'], $source, $all, PREG_SET_ORDER);

            self::assertSame(
                1,
                $matched,
                "{$label}: the anchor matches {$site['file']} {$matched} times, not once. Zero means the "
                    . 'sentence was rewritten past it — re-point the anchor in self::PROSE_SITES, do not '
                    . 'delete the row, that is how the count went stale three rounds running. More than one '
                    . 'means there are now two sentences quoting the count through one anchor, and only one '
                    . 'of them can be checked.',
            );

            $m = $all[0];
            $word = strtolower($m[1]);
            self::assertArrayHasKey(
                $word,
                self::NUMBER_WORDS,
                "{$label}: the anchor captured \"{$m[1]}\", which is not a number word this guard can read. "
                    . 'Widen self::NUMBER_WORDS or re-point the anchor; do not leave it unparsed.',
            );

            self::assertSame(
                $census - $site['offset'],
                self::NUMBER_WORDS[$word],
                "{$label}: the prose says \"{$word}\" but the token scan counts {$census} call sites "
                    . "(this site's sentence should quote " . ($census - $site['offset']) . ').',
            );
        }
    }

    /**
     * Every branch of the definition in this class's doc-block, on sources
     * whose answer is known.
     *
     * WHY THIS EXISTS AND IS NOT PARANOIA. Round 43's mutation runner in this
     * same tree silently overrode the `--filter` it was passed, so every row of
     * its results table was measuring something other than what it reported,
     * and it was caught only by running it against a case whose answer was
     * already known. This file is a harness of exactly that shape: a scanner
     * whose whole output is one integer that ten prose sentences are then
     * corrected against. If it over-counts by one, the correct response LOOKS
     * like "someone added a call site" and the repair is ten sentences edited
     * to a wrong number.
     *
     * The `first-class callable` row is not hypothetical: with the scan as
     * round 44 first wrote it, that source answered 1.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function scanDefinitionCases(): iterable
    {
        $m = self::SEAM_METHOD;

        yield 'a static call' => ["<?php self::{$m}('x');", 1];
        yield 'an instance call' => ["<?php \$o->{$m}('x');", 1];
        yield 'a nullsafe call' => ["<?php \$o?->{$m}('x');", 1];
        yield 'the declaration' => ["<?php class B { private static function {$m}(string \$m): void {} }", 0];
        yield 'a call reformatted across lines' => ["<?php self::{$m}(\n    sprintf(\n        'x',\n    ),\n);", 1];

        // THE ROW ABOVE DOES NOT PIN THE WHITESPACE STRIP AND WAS BELIEVED TO
        // (E228). Its newlines are inside the ARGUMENT LIST; the scan only ever
        // looks at the three tokens `self`, `::`, `<name>` and the `(` after
        // them, and no whitespace sits between those. MEASURED, PHP 8.3.6: with
        // T_WHITESPACE taken out of the strip it still answers 1, and so did
        // every other row in this provider. This is the row that puts the
        // whitespace where the scan reads, and it fails in both directions — 0
        // with the strip removed, 0 with the scan dead.
        yield 'a call whose scope operator is spaced' => ["<?php self :: {$m} ('x');", 1];

        // AND THE SAME HOLE ONE TOKEN OVER, for T_COMMENT/T_DOC_COMMENT. A
        // block comment is legal between `::` and the method name, so with the
        // comment strip removed the token before the name is a T_COMMENT and
        // the call scores 0. Nothing pinned that either: the two comment rows
        // below answer 0 whether the strip is there or not, because
        // `token_get_all()` hands a whole comment back as ONE token and the
        // method name never appears as a T_STRING inside one. They are real
        // rows — they kill a grep-shaped reimplementation, see the note at the
        // bottom of this provider — but they are not rows about the strip.
        yield 'a call with a comment between the scope operator and the name' => [
            "<?php self::/* c */{$m}('x');",
            1,
        ];

        // THE CLASS-CONSTANT REFERENCE, which is what the paren clause really
        // excludes — not the callable array and not the string spelling, both
        // of which are excluded by the T_STRING requirement and answer 0 with
        // the paren clause gone (MEASURED, PHP 8.3.6). Written as the reference
        // BESIDE a live call rather than alone, so the expected value is one
        // only a live scanner produces: 2 with the paren clause removed, 0 with
        // the scan dead. A lone reference asserting 0 would have survived both.
        yield 'a class-constant reference of the same name beside a live call' => [
            "<?php \$x = self::{$m}; self::{$m}('y');",
            1,
        ];
        yield 'a doc-block reference' => ["<?php /** {@see {$m}()} */ \$x = 1;", 0];
        yield 'a line comment quoting the call form' => ["<?php // self::{$m}(\n\$x = 1;", 0];
        yield 'a string spelling the call form' => ["<?php \$x = 'self::{$m}(';", 0];
        yield 'a first-class callable' => ["<?php \$f = self::{$m}(...);", 0];
        yield 'a callable array' => ["<?php \$f = [self::class, '{$m}'];", 0];
        yield 'a bare function of the same name' => ["<?php {$m}('x');", 0];
        yield 'two calls and one callable' => ["<?php self::{$m}('a'); \$f = self::{$m}(...); self::{$m}('b');", 2];

        // A known-answer control for the harness itself: a source with nothing
        // of the sort in it must answer 0, so a scan that has silently started
        // counting something else shows up here rather than in the number ten
        // sentences are corrected against.
        //
        // THE ONLY ROW HERE WITH NO POSITIVE COMPONENT, AND DELIBERATELY SO. It
        // cannot have one without ceasing to be what it is, and E228's sweep
        // named it rather than leaving the omission to look like an oversight.
        // What it kills is a scan that counts SOMETHING in a source containing
        // nothing of the sort — measured, a scan mutated to increment once per
        // significant token answers 4 here. What it cannot kill is a dead scan,
        // and it is not asked to: eight rows in this provider expect a non-zero
        // count, so a dead instrument reds the provider whatever this row does.
        yield 'a source with no mention at all' => ['<?php echo 1;', 0];

        // WHAT THE FOUR PURE-NEGATIVE ROWS ABOVE ACTUALLY KILL, recorded
        // because E228's whole subject is a fixture credited with catching a
        // mutation it survives. MEASURED, PHP 8.3.6, by mutating
        // {@see countSeamCallSites()} and re-running this provider:
        // `a doc-block reference`, `a line comment quoting the call form`,
        // `a string spelling the call form` and `a callable array` each answer
        // 0 under EVERY structural mutation of the clause list — the scope
        // token, the paren, the ellipsis guard and both halves of the strip.
        // The mutation they do kill is the one this class's doc-block warns a
        // reader about at length: the scan reimplemented as a substring count.
        // Against `substr_count($source, self::SEAM_METHOD)` all four go to 1;
        // against `substr_count($source, 'self::' . self::SEAM_METHOD . '(')`
        // the comment row and the string row go to 1. That is a real defect to
        // be guarded against and these are the rows that guard it — but it is
        // NOT the branch-by-branch coverage of the definition they were filed
        // under, which is why the three rows added above exist.
    }

    /** @dataProvider scanDefinitionCases */
    public function testTheScanDefinitionAgreesWithItsDocBlock(string $source, int $expected): void
    {
        self::assertSame($expected, self::countSeamCallSites($source));
    }

    /**
     * Every sentence in this file that states the size of {@see PROSE_SITES}
     * agrees with `count(self::PROSE_SITES)`.
     *
     * THE FLATTENER IS CHECKED IN THE SAME TEST, on a fixture whose answer is
     * known, because an assertion of the form "every anchor matched" is
     * worthless if the thing they were matched against could be empty. Round 44
     * proved that exact point elsewhere in this tree: a census assertion of
     * "nothing is stale" passed with 18,228 assertions green while the scanner
     * had been mutated to never match, and only a known-positive fixture caught
     * it.
     */
    public function testTheProseSiteCountInThisFilesOwnDocBlocksMatchesTheList(): void
    {
        // KNOWN-POSITIVE CONTROL FIRST. A wrapped doc-block whose sentence is
        // split across a continuation marker must come back joined; if
        // flattened() ever returns '' or leaves the markers, this fails here
        // rather than turning the assertions below into vacuous passes.
        // THE FIXTURE SENTENCE IS ASSEMBLED, not written whole, and that is not
        // style. This test scans its OWN file, so a fixture spelling the anchor
        // phrase contiguously in source becomes a second match for it and the
        // uniqueness assertion below reds on the test's own scaffolding —
        // measured, it did, before this concatenation.
        $sentence = 'quoted in prose in ' . 'TEN';
        $fixture = "    /**\n     * {$sentence}\n     * places, it says.\n     */\n";
        self::assertSame(
            ' /** ' . $sentence . ' places, it says. */ ',
            self::flattened($fixture),
            'flattened() no longer joins a wrapped doc-block sentence; every anchor below would fail open',
        );
        self::assertSame(
            1,
            preg_match(self::SELF_COUNT_ANCHORS[0]['anchor'], self::flattened($fixture)),
            'the first anchor no longer matches a sentence built to satisfy it; the scanner is not working',
        );

        $expected = \count(self::PROSE_SITES);
        $flat = [];

        foreach (self::SELF_COUNT_ANCHORS as $site) {
            $path = \dirname(__DIR__, 2) . '/' . $site['file'];
            self::assertFileExists($path, "{$site['file']}: the file stating the site count has moved");
            $flat[$site['file']] ??= self::flattened((string) file_get_contents($path));

            $anchor = $site['anchor'];
            $matched = preg_match_all($anchor, $flat[$site['file']], $all, PREG_SET_ORDER);
            self::assertSame(
                1,
                $matched,
                "{$anchor} matches {$site['file']} {$matched} times, not once. A sentence stating the size "
                    . 'of self::PROSE_SITES was rewritten past its anchor, or a second one now states it '
                    . 'too. Re-point the anchor; do not drop it.',
            );

            $word = strtolower($all[0][1]);
            self::assertArrayHasKey(
                $word,
                self::NUMBER_WORDS,
                "{$anchor} captured \"{$all[0][1]}\", which is not a number word this guard can read",
            );
            self::assertSame(
                $expected,
                self::NUMBER_WORDS[$word],
                "{$anchor} in {$site['file']} says \"{$word}\" but self::PROSE_SITES holds "
                    . "{$expected} rows",
            );
        }
    }

    /**
     * The scan must not be able to answer for a file it did not read. A typo in
     * the path would otherwise make every count above zero-and-green.
     */
    public function testTheScanReadsTheFileItClaimsTo(): void
    {
        self::assertStringContainsString(
            'private static function ' . self::SEAM_METHOD . '(',
            self::bootstrapSource(),
            'the census is pointed at a file that does not declare the seam',
        );
    }

    private static function bootstrapSource(): string
    {
        $path = \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The definition in this class's doc-block, executed.
     *
     * @param ?string $source PHP source to scan; defaults to `Bootstrap.php`.
     *                        Overridden only by
     *                        {@see testTheScanDefinitionAgreesWithItsDocBlock()},
     *                        so that every branch of the definition is
     *                        exercised on sources whose answer is known before
     *                        the scan's verdict is trusted on one whose answer
     *                        is not.
     */
    private static function countSeamCallSites(?string $source = null): int
    {
        $significant = [];
        foreach (token_get_all($source ?? self::bootstrapSource()) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $calls = 0;
        foreach ($significant as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_STRING || $token[1] !== self::SEAM_METHOD) {
                continue;
            }

            $previous = $significant[$i - 1] ?? null;
            $scoped = \is_array($previous)
                && \in_array($previous[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

            if (!$scoped || ($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            // `self::seam(...)` is a first-class callable, not a call. Its `(`
            // sits immediately after the name exactly as a real call's does —
            // measured on PHP 8.3.6, it lexes as T_STRING, '(', T_ELLIPSIS,
            // ')' — so the paren test above cannot tell them apart, and the
            // class doc-block used to claim it could.
            $after = $significant[$i + 2] ?? null;
            if (\is_array($after) && $after[0] === T_ELLIPSIS && ($significant[$i + 3] ?? null) === ')') {
                continue;
            }

            $calls++;
        }

        return $calls;
    }
}
