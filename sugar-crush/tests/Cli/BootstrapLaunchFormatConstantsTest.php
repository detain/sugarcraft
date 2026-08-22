<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Config\ReadmeSettingsTierClaimTest;

/**
 * The launch-report formats that other files name are named, and the methods
 * that emit them do not keep a second copy.
 *
 * WHY THIS FILE EXISTS, AND IT IS NOT "CONSTANTS ARE TIDIER". E104 recorded
 * that every `sprintf()` in {@see Bootstrap} reaching stderr or the transcript
 * was pinned only by a test RE-TYPING it.
 *
 * THE FIGURE THAT USED TO SIT HERE WAS QUOTED WITHOUT ITS TREE, and it is the
 * mistake this whole file family exists to stop. WHAT IT SAID: "measured the
 * consequence: changing the launcher's format from `disabled %d of the %d` to
 * `removed %d of the %d` left the two guards that exist for that line green",
 * with no commit attached. WHAT IS TRUE NOW, re-measured at `06126017` — the
 * tree E118 actually replaced, PHP 8.3.6: that same mutation REDS in that
 * class. It was NOT blind. By then E104's finding had already been
 * half-answered by round 43, which replaced the re-typed literal with a regex
 * scrape of `Bootstrap.php`'s source text, and the scrape reads the literal —
 * so it reds on a literal change. The "green" reading describes the tree
 * BEFORE that scrape existed, which is a tree neither reading in this
 * doc-block was measured against.
 * WHY THE OLD READING STILL EARNS ITS PLACE: it is the only measurement of the
 * failure E104 named, and deleting it would leave the item reading as a
 * tidiness complaint. It is a historical verdict for the retyped-literal
 * shape, not a description of what E118 improved on.
 *
 * BOTH READINGS USED TO CARRY A `Tests:`/`Assertions:` TOTAL AND NO LONGER DO
 * (E188). A PHPUnit class total looks like a measurement and is also a
 * CARDINALITY OVER THE CLASS: any sibling test added anywhere beside the
 * mutated one moves it, with no relationship to the thing being measured.
 * Round 46 shipped three such figures and all three were invalidated by a
 * later commit of the SAME ROUND. What survives a sibling landing is the NAME
 * of the test that reds, so that is what this file quotes from here down; a
 * verdict with nothing red needs no name and says so in words.
 *
 * WHAT E118 ACTUALLY IMPROVED ON is the scrape, and its weakness is a
 * different one: it was coupled to the syntactic SHAPE of the code rather than
 * to the string, so a pure refactor broke it. Measured at `06126017`, turning
 * `warnPermissionConfig()`'s interpolation into
 * `sprintf(self::STDERR_LINE_FORMAT, $message)` — not one byte of output
 * changed — REDS that class. E118 promoted the formats with an external reader
 * to `public const` and pointed those guards at the names.
 *
 * THAT SWAP IS ONLY AN IMPROVEMENT UNDER ONE CONDITION, and this file is the
 * condition. A `public const` that the emitting code does not `sprintf()` FROM
 * is not a shared definition — it is the same duplication with a nicer name,
 * and it is strictly WORSE than the re-typed literal was, because the reader of
 * {@see \SugarCraft\Crush\Tests\Config\ReadmeRosterDriftTest} now believes the
 * README is being compared against the launcher when it is being compared
 * against a decoration. Concretely, the hole this closes: leave
 * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT} alone, re-inline a
 * DIFFERENT literal in `reportProjectTierToolRemovals()`, and every guard on
 * that line stays green while the launcher prints something else. Measured —
 * see this round's mutation table.
 *
 * WHAT IS *NOT* PINNED HERE, stated because the absence looks like an
 * oversight. `Bootstrap.php` still holds `sprintf()` call sites with a literal
 * format — HOW MANY IS DELIBERATELY NOT WRITTEN HERE, and that is this round's rule rather than laziness: a
 * cardinality in prose is invalidated by the next commit that adds a
 * `sprintf()`, and round 44 shipped one into `src/` that was correct in its
 * lane and wrong at master an hour later. The pair is asserted, with its
 * generator, by {@see testTheLiteralFormatCensusHasAGenerator()}; read it
 * there, where a new `sprintf()` moves the number and the assertion at the same
 * time.
 *
 * WHICH FORMATS ARE PROMOTED is decided by EXTERNAL READERSHIP: a format quoted
 * by `README.md`, `docs/SETTINGS.md` or a drift guard has a second party that
 * must agree with it, and a name is how two parties agree. A format read by
 * exactly one method is not improved by moving it away from the only code that
 * cares.
 *
 * THAT RULE HAS NOW BEEN WALKED ACROSS THE WHOLE FILE (E164) rather than
 * applied to the formats that happened to come up. WHAT THIS PARAGRAPH SAID:
 * "promoting the rest is recorded as a deferred finding rather than done".
 * WHAT IS TRUE NOW: every `sprintf()` in `Bootstrap.php` with a literal format
 * was walked and asked the question, the ones with a reader were promoted into
 * {@see NAMED_FORMATS}, and the rest were left inline ON PURPOSE —
 * `mcpConfigDecision()`'s two refusal
 * reasons and `trustedConfigDirPath()`'s home-ownership refusal. THE COUNTS
 * THAT USED TO BE IN THIS SENTENCE ARE GONE, and that is round 46's review
 * (MINOR 5) rather than tidying: it read "seven more were promoted, and the
 * three left inline" two paragraphs after the rule forbidding a cardinality in
 * prose, and those same two numbers had already been wrong once inside this
 * round — they were written as four and six and left behind by the next two
 * commits. The promoted set is `NAMED_FORMATS`, which is a list and counts
 * itself; the inline set is named here, which is what a reader actually needs.
 * Every reader
 * those three have asserts a FRAGMENT (`'outside the project tree'`,
 * `'running programs this repository chose'`,
 * `/cannot be established as yours/`), a deliberately loose coupling to an idea
 * rather than two parties agreeing on a sentence — and unlike E164's first
 * answer, that is MEASURED rather than grepped: rewording each of the three
 * OUTSIDE its documented fragment leaves the classes that could plausibly
 * cover them green. WHY THE ORIGINAL SENTENCE
 * STILL EARNS ITS PLACE: its reasoning is the reason the walk did not end in
 * promoting every literal it found. "Every literal is a constant" buys nothing and costs a
 * reader one indirection per line; the finding is which ones have a reader, and
 * saying "this one does not" is as much of an answer as promoting it.
 *
 * A WALK IS A CLAIM AND HAS TO BE MEASURED LIKE ONE, which E164's own first
 * answer was not, and it was wrong three times. (1) It read the readers of
 * `reportProjectTierRefusals()`'s `'ignoring %s — %s'` off two files that
 * mention the envelope in COMMENTS, concluded "fragment only", and left it
 * inline; rewording `ignoring` → `skipping` reds
 * {@see \SugarCraft\Crush\Tests\Cli\BootstrapLaunchNoticeRoutingTest::testARefusedProjectDirectoryReachesBothChannels()},
 * because that test reconstructs the whole envelope twice — once per channel.
 * THIS SENTENCE IS ALSO E188'S OWN WORKED EXAMPLE, which is why it is worth the
 * extra line: it used to end in a class total, and that total went stale in the
 * two rounds it took to write this paragraph and read it again. RE-MEASURED at
 * round 47 on PHP 8.3.6, scope = the eight classes in `tests/` that name the
 * refusal envelope at all, the same reword now reds FOUR tests rather than the
 * one the total was taken over —
 * {@see testTheTroubleshootingPageQuotesTheRefusalShapeTheLauncherActuallyPrints()},
 * {@see testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster()} and
 * {@see testTheSweepFindsAQuotedFormatOnAPageWhoseAnswerIsKnown()} in this file
 * joined it, none of which existed when the figure was taken. A name survives
 * that; a total does not. (2) and (3) The two `mcpClient()`
 * messages looked like fragment readers too — the clause everything asserts is
 * `'could not be fully started'`, which both of them carry. Rewording the spans
 * that clause does NOT cover gives `Failures: 3` and `Failures: 2`:
 * {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest} pins three
 * separate clauses across the pair, because its whole subject is that those two
 * lines must not collapse into each other.
 *
 * THE LESSON FOR THE NEXT WALK, and it is the reusable part: `grep` for a
 * format's WORDS finds the files that TALK about it; only a mutation finds the
 * files that DEPEND on it, and those are not the same set. And the mutation has
 * to land OUTSIDE every fragment already known to be asserted — a reword inside
 * the fragment kills for the reason you already knew and tells you nothing new.
 * That mistake was made here first: four "kills" that were all reruns of a
 * fragment, and re-placing the same four mutations outside the fragments turned
 * two of them into survivals.
 *
 * ONE PROMOTED CONSTANT USED NOT TO SATISFY THAT RULE, and the repair is worth
 * recording because the shape of it applies to the next such constant.
 * WHAT THIS PARAGRAPH SAID: {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE}
 * "has no external reader at all" — measured, `grep -rn "leaving no tools at
 * all" src/ tests/ docs/ README.md` returned exactly one hit, its own
 * declaration — and "until the no-survivors branch has a behavioural assertion
 * of its own (recorded as a deferred finding), {@see METHOD_LITERALS} is the
 * whole of what pins it".
 * WHAT IS TRUE NOW: it has one.
 * {@see \SugarCraft\Crush\Tests\Cli\BootstrapToolAndPermissionSettingsTest::testAProjectThatRemovesEveryToolReportsTheNoSurvivorsBranch()}
 * makes a real child launch remove every tool, which is the only thing in the
 * tree that makes the running program take that branch — deleting the branch
 * reds it. WHY THE OLD READING STILL EARNS ITS PLACE, and this is the part a
 * later reader needs: that behavioural case does NOT pin the constant's TEXT.
 * Its expectation renders from the same constant the child renders from, so with
 * respect to the wording it is a tautology — measured, a rewording of the
 * constant left the behavioural ASSERTION green. A separate literal copy of the
 * sentence lives beside it for exactly that reason, and the pair is the pin.
 * MEASURED AGAIN at round 47 on PHP 8.3.6, scope = that whole class: rewording
 * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE} now reds
 * {@see \SugarCraft\Crush\Tests\Cli\BootstrapToolAndPermissionSettingsTest::testAProjectThatRemovesEveryToolReportsTheNoSurvivorsBranch()}
 * — on the literal-copy assertion, not on the behavioural one, which is the
 * pair working exactly as described. The class total this paragraph used to
 * carry is gone under E188, and it had already drifted by an assertion before
 * anyone re-read it. The sibling constants need no such copy because README.md and the two
 * docs pages hold theirs.
 *
 * @see \SugarCraft\Crush\Tests\Config\ReadmeRosterDriftTest for the README end
 *      of the same claim — that file compares the constants to the page.
 * @see \SugarCraft\Crush\Tests\Cli\BootstrapToolAndPermissionSettingsTest for
 *      the behavioural end: a real child launch whose stderr carries the line.
 */
final class BootstrapLaunchFormatConstantsTest extends TestCase
{
    /**
     * One `sprintf()` conversion, as a pattern body without delimiters.
     *
     * EXTRACTED SO THERE IS ONE DEFINITION. {@see conversionSpecsIn()} counts
     * conversions with it and {@see shapePatternFor()} splits a format on it;
     * two copies of a pattern whose ALPHABET IS THE COVERAGE is the shape of
     * defect this whole file is about — the sweep would go on nominating pages
     * under an alphabet the counter had already outgrown, and neither end would
     * red. The alphabet itself, and the two forms an earlier version could not
     * express, are argued at {@see conversionSpecsIn()}.
     */
    private const CONVERSION_PATTERN = "%(?:%|(?:[0-9]+\\\$)?(?:[-+ 0#]|'.)*[0-9]*(?:\\.[0-9]+)?[bcdeEfFgGosuxX])";

    /**
     * Each promoted FORMAT, the method obliged to `sprintf()` from it, and how
     * many conversions its callers pass.
     *
     * The conversion count is here because it is the one property of a format
     * that a caller can violate without any string looking wrong: `sprintf()`
     * in PHP 8 throws `ArgumentCountError` when the format asks for more than it
     * is given (measured on PHP 8.3.6: `3 arguments are required, 2 given`), so
     * an extra `%s` added to one of these constants is a fatal at the first
     * warning of every launch that raises one — not a cosmetic drift.
     *
     * @var array<string, array{method: string, conversions: int}>
     */
    private const NAMED_FORMATS = [
        'STDERR_LINE_FORMAT' => ['method' => 'warnPermissionConfig', 'conversions' => 1],
        'PROJECT_TIER_TOOL_REMOVAL_FORMAT' => [
            'method' => 'reportProjectTierToolRemovals',
            'conversions' => 5,
        ],
        'SKILL_SKIP_NOTICE_FORMAT' => ['method' => 'reportSkillSkips', 'conversions' => 5],
        'LAUNCH_NOTICE_OVERFLOW_FORMAT' => ['method' => 'launchNotices', 'conversions' => 2],
        'SESSION_RETENTION_SUMMARY_FORMAT' => ['method' => 'reportPrunedSessions', 'conversions' => 3],
        'SESSION_RETENTION_DETAIL_FORMAT' => ['method' => 'reportPrunedSessions', 'conversions' => 4],
        'PROJECT_TIER_REFUSAL_FORMAT' => ['method' => 'reportProjectTierRefusals', 'conversions' => 2],
        'MCP_PARTIAL_START_LOG_FORMAT' => ['method' => 'mcpClient', 'conversions' => 3],
        'MCP_PARTIAL_START_NOTICE_FORMAT' => ['method' => 'mcpClient', 'conversions' => 2],
    ];

    /**
     * Each promoted plain LITERAL and the method obliged to use it.
     *
     * @var array<string, string>
     */
    private const NAMED_LITERALS = [
        'PROJECT_TIER_TOOL_REMOVAL_LEAVING' => 'reportProjectTierToolRemovals',
        'PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE' => 'reportProjectTierToolRemovals',
    ];

    /**
     * THE SWEEP (E187): every page under `README.md` and `docs/` that quotes a
     * promoted format, and what checks that page.
     *
     * WHY A ROSTER AND NOT A THRESHOLD. Round 46 closed the same hole twice —
     * `README.md` had a guard on the tool-removal launch report,
     * `docs/SETTINGS.md` carried a byte-for-byte copy of the identical sample
     * and had none — and then found a third by sweeping the rest of the
     * promoted formats the same way. The sweep was run BY HAND, ONCE, at one
     * commit. The next format promoted, or the next page that decides to quote
     * a launch line, restores the same silence, and the failure mode is the
     * quiet one: a page that PROMISES agreement and is checked by nothing is
     * worse than one that paraphrases, because the promise is what stops the
     * next reader from checking it by hand.
     *
     * A VERDICT IS EITHER A GUARD OR A STATED REASON THERE IS NONE. A row whose
     * value contains `::` names the test that pins that page against that
     * constant, and {@see testEveryGuardTheReadersRosterNamesStillExists()}
     * checks the name resolves. Anything else is a sentinel saying, in as many
     * words, why the page needs no guard — today the only one is
     * {@see PAGE_QUOTE_IS_PROSE}.
     *
     * THE PAGE LIST IS DERIVED, NEVER LISTED, which is the half of E187 that a
     * hand-run sweep cannot have: a page ADDED next round is swept without
     * anyone remembering to add it here.
     *
     * WHAT THIS PARAGRAPH SAID about how: that {@see docPages()} "globs
     * `docs/*.md` beside `README.md`". WHAT IS TRUE NOW: the very next commit
     * of the round that wrote this sentence replaced that with
     * {@see markdownPagesUnder()}, which walks `docs/` RECURSIVELY and takes
     * every `*.md` at the package root — so the description outlived the
     * mechanism by one commit, in the same file, by the same hand. WHY THE
     * CORRECTION EARNS ITS PLACE RATHER THAN A SILENT EDIT: this is the second
     * time in three rounds that a mechanism written into a NEW comment was
     * inverted in fact, and the two paragraphs saying the same thing about the
     * same method is what let one of them rot unnoticed. {@see docPages()}
     * owns the description of HOW the list is derived; this one owns only the
     * fact THAT it is derived, and deliberately no longer restates the other.
     *
     * @var array<string, array<string, string>> constant => page => verdict
     */
    private const PAGE_QUOTES = [
        'STDERR_LINE_FORMAT' => [
            'README.md' => ReadmeSettingsTierClaimTest::class
                . '::testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem',
            'docs/ENVIRONMENT.md' => self::PAGE_QUOTE_IS_PROSE,
            'docs/SETTINGS.md' => ReadmeSettingsTierClaimTest::class
                . '::testTheSettingsPageQuotesTheSameLaunchReportByteForByte',
        ],
        'PROJECT_TIER_TOOL_REMOVAL_FORMAT' => [
            'README.md' => ReadmeSettingsTierClaimTest::class
                . '::testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem',
            'docs/SETTINGS.md' => ReadmeSettingsTierClaimTest::class
                . '::testTheSettingsPageQuotesTheSameLaunchReportByteForByte',
        ],
        'PROJECT_TIER_TOOL_REMOVAL_LEAVING' => [
            'README.md' => ReadmeSettingsTierClaimTest::class
                . '::testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem',
            'docs/SETTINGS.md' => ReadmeSettingsTierClaimTest::class
                . '::testTheSettingsPageQuotesTheSameLaunchReportByteForByte',
        ],
        'SESSION_RETENTION_SUMMARY_FORMAT' => [
            'docs/ENVIRONMENT.md' => self::class . '::testTheDocPagesQuoteTheRetentionSummaryTheLauncherActuallyPrints',
            'docs/SETTINGS.md' => self::class . '::testTheDocPagesQuoteTheRetentionSummaryTheLauncherActuallyPrints',
        ],
        'PROJECT_TIER_REFUSAL_FORMAT' => [
            'docs/TROUBLESHOOTING.md' => self::class
                . '::testTheTroubleshootingPageQuotesTheRefusalShapeTheLauncherActuallyPrints',
        ],
    ];

    /**
     * The verdict for a page that DESCRIBES a format's shape in a sentence
     * rather than quoting a line the launcher would print.
     *
     * `docs/ENVIRONMENT.md` is the only holder today: its
     * `SUGARCRUSH_DEBUG_COMMANDS` row says the transcript seam "already writes
     * a `sugarcrush: `-prefixed line to stderr", which matches
     * {@see Bootstrap::STDERR_LINE_FORMAT}'s shape because that shape is barely
     * more than the label. There is no sample to compare, so there is nothing a
     * guard could assert beyond what
     * {@see testTheRetentionDetailRowIndentsUnderTheEnvelopeItDuplicates()}
     * already derives from the same constant.
     */
    private const PAGE_QUOTE_IS_PROSE = '<prose about the shape, not a quotation of a rendered line>';

    /**
     * How many bytes of page text one `%s` is allowed to stand for.
     *
     * IT IS THE INSTRUMENT'S ALPHABET AND IT IS MEASURED, not a round number.
     * With the bound removed — `.*?` in place of `.{1,160}?` — the sweep
     * nominates `README.md` as a reader of
     * {@see Bootstrap::PROJECT_TIER_REFUSAL_FORMAT} (`ignoring %s — %s`),
     * because README.md contains the unrelated sentence "reject one at exit
     * `2` rather than ignoring it" and, some hundreds of bytes later, an em
     * dash. That is round 46's own false positive, the one a human had to
     * remove from the hand-run sweep by reading the page. MEASURED on PHP
     * 8.3.6 at round 47: with the bound in place the sweep nominates
     * `docs/TROUBLESHOOTING.md` and nothing else for that constant.
     *
     * THE COST IS STATED RATHER THAN HIDDEN, because it is the direction that
     * loses a finding: a page that quotes a launch line whose interpolated
     * field is longer than this is NOT nominated, and the sweep reports its
     * absence as silence. Nothing here can detect that, which is why the bound
     * is generous — every field the four quoting pages actually interpolate is
     * under sixty bytes — and why the fixture in
     * {@see testTheSweepFindsAQuotedFormatOnAPageWhoseAnswerIsKnown()} pins
     * both directions rather than only the one this file wants.
     */
    private const PAGE_QUOTE_FIELD_BYTES = 160;

    /**
     * PHPUnit's own two output forms. Never excused by an anchor — see
     * {@see classTotalsIn()} for why the carve-out is prose-only.
     */
    private const CLASS_TOTAL_LITERAL = '/\b(?:Tests:\s*\d+|Assertions:\s*\d+|OK \(\d+ tests?)/';

    /**
     * The PROSE form of the same figure, which is how every report in this
     * project writes one and which this guard could not see until round 47.
     */
    private const CLASS_TOTAL_PROSE = '/\d[\d,]*\s+(?:tests|assertions)\b/i';

    /**
     * What makes a prose figure history rather than a claim about the tree: the
     * round it was taken in, or a backticked commit sha. The sha alternative is
     * backtick-delimited rather than a bare word so that an all-hex English
     * word cannot pose as provenance.
     */
    private const CLASS_TOTAL_ANCHOR = '/\bround \d+\b|`[0-9a-f]{7,10}`/';

    /**
     * Every string literal each obliged method is allowed to hold, exactly.
     *
     * WHY AN EXACT SET AND NOT "NO FORMAT LITERALS", which is what this file
     * shipped first and what round 45's review broke. WHAT THE OLD GUARD SAID:
     * "A PRINTF CONVERSION IS THE MARKER" — a re-inlined literal is caught
     * because a format carries a `%s`. WHAT IS TRUE NOW, measured: the review
     * re-inlined `'WRECKED: no tools survive'` in place of
     * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE} while leaving the
     * constant's NAME mentioned in the body, ran the six classes that could
     * plausibly cover it, and no test in any of them redded — rc 0. (A "nothing
     * red" verdict is the one case E188 leaves without a name, because there is
     * no name to give; the class totals that used to stand in for it are gone.)
     * The
     * conversion marker cannot see a promoted literal that carries no
     * conversion, and {@see identifiersIn()} pins only that the name is
     * MENTIONED, never that it is USED. That is exactly the state this file's
     * own doc-block calls "the same duplication with a nicer name … strictly
     * WORSE than the re-typed literal".
     *
     * WHY THE REVIEW'S OWN PRESCRIPTION WAS NOT IMPLEMENTED. It asked for an
     * assertion that the constant's VALUE does not also appear as a literal in
     * the body. Measured against the mutation it was prescribed for: the
     * re-inlined literal was `'WRECKED: no tools survive'` and the constant's
     * value is `'leaving no tools at all'`, so a value-equality check passes on
     * precisely the case that motivated it. A prescription in a review is a
     * hypothesis; this one does not kill its own mutation.
     *
     * SO THE OBLIGATION IS AN ALLOWLIST, which is the conversion-free
     * generalisation that does work: a method that owns a named constant may
     * hold these literals and no others, so ANY new string in it — a format, a
     * message, a re-inlined constant that differs from the constant — is a
     * failure until someone classifies it. THE FRICTION IS THE FEATURE, the
     * same bargain {@see \SugarCraft\Crush\Tests\Cli\StderrEmitterCensusTest}'s
     * per-file rosters make: bumping the list is a fine response, not noticing
     * is not.
     *
     * @var array<string, list<string>>
     */
    private const METHOD_LITERALS = [
        'warnPermissionConfig' => [],
        'reportProjectTierToolRemovals' => ["'disabledTools'", "', '"],
        // The three agreement slots the notice interpolates. Every one of them
        // is plumbing for ONE sentence, which is why the sentence has a name
        // and these do not.
        'reportSkillSkips' => ["''", "'s'", "'was'", "'were'", "'it'", "'them'"],
        'launchNotices' => ["''", "'s'"],
        // Both halves of the retention report are named, so what is left here
        // is the plural pair and the four `$row` keys the detail line reads.
        'reportPrunedSessions' => ["'session'", "'sessions'", "'id'", "'updated_at'", "'messages'", "'message'"],
        // The full stop rtrim()ed off the reason, because STDERR_LINE_FORMAT
        // adds one of its own and two would read as a typo.
        'reportProjectTierRefusals' => ["'.'"],
        // The two decision keys it reads; both messages are named now.
        'mcpClient' => ["'path'", "'status'"],
    ];

    public function testEveryNamedFormatIsReferencedByTheMethodThatEmitsIt(): void
    {
        self::assertNotSame([], self::obligations(), 'the obligation roster is empty, so this is vacuous');

        foreach (self::obligations() as $constant => $method) {
            self::assertContains(
                $constant,
                self::identifiersIn(self::methodBody($method)),
                "Bootstrap::{$method}() no longer names Bootstrap::{$constant}. A promoted constant the "
                    . 'emitting code does not use is a second copy with a nicer name, and every guard that '
                    . 'reads the constant is then measuring a decoration rather than the launcher.',
            );
        }
    }

    /**
     * No method that owns a named format may hold a format literal of its own.
     *
     * A PRINTF CONVERSION IS THE MARKER, not equality with the constant's
     * value: the failure being closed is a re-inlined literal that DIFFERS from
     * the constant, so a check for the constant's own text would pass on exactly
     * the case that matters. Ordinary literals in these bodies — `'disabledTools'`,
     * the `', '` an `implode()` joins on — carry no conversion and are left alone.
     */
    public function testNoMethodThatOwnsANamedFormatAlsoHoldsALiteralOne(): void
    {
        foreach (self::obligations() as $constant => $method) {
            $offenders = self::formatLiteralsIn(self::methodBody($method));

            self::assertSame(
                [],
                $offenders,
                "Bootstrap::{$method}() holds a literal containing a printf conversion: "
                    . implode(' | ', $offenders) . ". It is obliged to format from Bootstrap::{$constant}; "
                    . 'a literal alongside it is the drift this file exists to catch.',
            );
        }
    }

    /**
     * Each obliged method holds exactly the literals it is allowed to hold.
     *
     * THE KNOWN-POSITIVE IS IN THIS TEST AND NOT IN THE SHARED PROVIDER,
     * because one of the two expectations is `[]`:
     * `warnPermissionConfig()`'s body is a single `fwrite()` and holds no string
     * at all, so a blinded {@see literalsIn()} would pass that row on its own.
     * An assertion of `[]` is not evidence unless something in the same test
     * proves the scanner still works.
     */
    public function testEachObligedMethodHoldsExactlyTheLiteralsItIsAllowed(): void
    {
        self::assertSame(
            ["'kept'", "'%s and more'"],
            self::literalsIn(self::methodBody('f', "<?php class B {\n"
                . "    private static function f(): void {\n"
                . "        self::emit('kept', 'kept', sprintf('%s and more', \$a));\n"
                . "    }\n}\n")),
            'literalsIn() no longer reports a body\'s literals in order and without duplicates; the '
                . 'expectations below are not measuring anything',
        );

        foreach (self::METHOD_LITERALS as $method => $allowed) {
            self::assertSame(
                $allowed,
                self::literalsIn(self::methodBody($method)),
                "Bootstrap::{$method}() holds a different set of string literals than this file allows. A "
                    . 'method that owns a named constant may hold plumbing strings and nothing else: a new '
                    . 'literal here is either a message the launcher prints — in which case it wants a name, '
                    . 'and a second party that reads the name — or it is plumbing, in which case add it to '
                    . 'self::METHOD_LITERALS. Re-inlining a promoted constant\'s text under a different '
                    . 'wording is the failure this list exists to catch, and it carries no printf conversion '
                    . 'for the marker in the test above to see.',
            );
        }
    }

    /**
     * Every method that owns a named constant has a literal allowlist.
     *
     * Without this, promoting a constant into {@see NAMED_FORMATS} or
     * {@see NAMED_LITERALS} while forgetting the allowlist row would leave the
     * new obligation covered by the conversion marker alone — which is the hole
     * the allowlist exists to close, silently reopened by the next promotion.
     */
    public function testEveryObligedMethodHasALiteralAllowlist(): void
    {
        foreach (self::obligations() as $constant => $method) {
            self::assertArrayHasKey(
                $method,
                self::METHOD_LITERALS,
                "Bootstrap::{$method}() owns Bootstrap::{$constant} but has no row in "
                    . 'self::METHOD_LITERALS, so nothing stops a re-inlined literal in it.',
            );
        }
    }

    public function testEachNamedFormatAsksForExactlyTheConversionsItsCallersPass(): void
    {
        foreach (self::NAMED_FORMATS as $constant => $spec) {
            $value = (string) \constant(Bootstrap::class . '::' . $constant);

            self::assertSame(
                $spec['conversions'],
                self::conversionsIn($value),
                "Bootstrap::{$constant} no longer asks for {$spec['conversions']} conversions. sprintf() "
                    . 'throws ArgumentCountError when a format asks for more than it is given, so this is a '
                    . 'launch-time fatal and not a cosmetic drift.',
            );

            // A % sequence this file cannot parse must not be read as "no
            // conversion" — that is how a positional format got past the
            // conversion marker in the first place, and on PHP 8 an
            // unrecognised sequence is a ValueError at the first launch that
            // formats it.
            self::assertSame(
                [],
                self::unparsedPercentsIn($value),
                "Bootstrap::{$constant} carries a % sequence this guard cannot account for. Either it is a "
                    . 'conversion form conversionSpecsIn() has not met — widen it — or sprintf() will throw '
                    . 'on it at launch.',
            );
        }
    }

    /**
     * KNOWN-POSITIVE FIXTURES FOR BOTH SCANNERS, in the same test that uses
     * them to assert an absence.
     *
     * `testNoMethodThatOwnsANamedFormatAlsoHoldsALiteralOne()` asserts `[]`, and
     * an assertion of `[]` is not evidence unless something proves the scanner
     * still works: round 44 emptied a census in this tree, mutated the scanner
     * to never match, and watched the "nothing is stale" assertion pass with
     * 18,228 assertions green. So both scanners are run here against sources
     * whose answer is known, including the exact shape the guard exists to
     * reject.
     */
    public function testTheScannersAnswerCorrectlyOnSourcesWhoseAnswerIsKnown(): void
    {
        $reInlined = "<?php class B {\n"
            . "    private static function f(): void {\n"
            . "        self::emit(sprintf('%s (disabledTools) removed %d of %d — %s — %s', \$a));\n"
            . "    }\n}\n";
        self::assertSame(
            ["'%s (disabledTools) removed %d of %d — %s — %s'"],
            self::formatLiteralsIn(self::methodBody('f', $reInlined)),
            'the format-literal scanner no longer sees a re-inlined format; every [] above is vacuous',
        );

        $fromConstant = "<?php class B {\n"
            . "    private static function f(): void {\n"
            . "        self::emit(sprintf(self::THE_FORMAT, \$a), 'disabledTools', ', ');\n"
            . "    }\n}\n";
        self::assertSame(
            [],
            self::formatLiteralsIn(self::methodBody('f', $fromConstant)),
            'the scanner reports a literal with no conversion in it; it would red on ordinary strings',
        );
        self::assertContains(
            'THE_FORMAT',
            self::identifiersIn(self::methodBody('f', $fromConstant)),
            'the identifier scanner cannot see a constant reference; the obligation check is vacuous',
        );
        self::assertNotContains(
            'THE_FORMAT',
            self::identifiersIn(self::methodBody('f', $reInlined)),
            'the identifier scanner reports a constant that is not there',
        );

        // Nested braces must not end the body early — a scanner that stopped at
        // the first `}` would see none of a method whose first statement is a
        // closure or an `if`, and would answer [] for every one of them.
        $nested = "<?php class B {\n"
            . "    private static function f(): void {\n"
            . "        if (true) { \$x = 1; }\n"
            . "        self::emit(sprintf('late %s', \$a));\n"
            . "    }\n}\n";
        self::assertSame(
            ["'late %s'"],
            self::formatLiteralsIn(self::methodBody('f', $nested)),
            'methodBody() stops at a nested closing brace, so it reads only the head of every method',
        );

        self::assertSame(2, self::conversionsIn('%s and %d'));
        self::assertSame(0, self::conversionsIn('100%% done'), '%% is an escaped percent, not a conversion');
        self::assertSame(1, self::conversionsIn("sugarcrush: %s.\n"));

        // The two forms the first version of this pattern could not express.
        self::assertSame(2, self::conversionsIn('%1$s and %2$s'), 'a positional format reads as no conversions');
        self::assertSame(1, self::conversionsIn("%'*10s"), 'a custom pad character reads as no conversions');
        self::assertSame(1, self::conversionsIn('%05.2f'));
        self::assertSame(1, self::conversionsIn('%-10s'));

        // Unparseable is loud, not zero.
        self::assertSame([], self::unparsedPercentsIn('%s and %1$d and 100%%'));
        self::assertSame(['%z'], self::unparsedPercentsIn('%z'), 'an unknown conversion reads as no percent');
        self::assertSame(
            ["'a %z b'"],
            self::formatLiteralsIn(self::methodBody('f', "<?php class B {\n"
                . "    private static function f(): void {\n"
                . "        self::emit('a %z b');\n"
                . "    }\n}\n")),
            'a literal carrying a % sequence this file cannot parse is passed over rather than reported',
        );
    }

    /**
     * A method this file names but cannot find is a FAILURE, not a skip.
     *
     * A guard that quietly ignores what it cannot parse has a hole shaped
     * exactly like the next defect: rename `warnPermissionConfig()` and every
     * assertion above would otherwise pass on an empty body.
     */
    public function testTheScannerRedsOnAMethodItCannotFind(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('noSuchMethodOnBootstrap');

        self::methodBody('noSuchMethodOnBootstrap');
    }

    /**
     * The figure this class's doc-block quotes, derived rather than counted.
     *
     * It is asserted as a RANGE-FREE exact pair on purpose: a new `sprintf()`
     * with a literal format in `Bootstrap.php` reds here, and the right response
     * is to ask whether the new format has an external reader — if it does, it
     * wants a name; if it does not, bump the figure. That question being asked
     * once per new format IS the value; the number itself is worth nothing.
     */
    public function testTheLiteralFormatCensusHasAGenerator(): void
    {
        $census = self::sprintfCensus(self::bootstrapSource());

        self::assertSame(
            12,
            $census['calls'],
            "Bootstrap.php's sprintf() call-site count moved; see this test's doc-block",
        );
        self::assertSame(3, $census['literal'], 'a sprintf() in Bootstrap.php gained or lost a literal format');
        self::assertSame(
            \count(self::NAMED_FORMATS),
            $census['constant'],
            'Bootstrap.php formats from a different number of constants than this file names as promoted',
        );

        // A RE-INLINED INTERPOLATED FORMAT IS THE FAILURE E163 NAMES, and until
        // this round it read as a promotion rather than as a regression. Zero
        // is the whole of the claim: any `sprintf("… {$x} …")` in this file reds.
        self::assertSame(
            0,
            $census['interpolated'],
            'a sprintf() in Bootstrap.php builds its format by interpolation. That is neither a promoted '
            . 'constant nor an inline literal any of the guards above can read: METHOD_LITERALS sees the '
            . 'pieces, not the sentence, and formatLiteralsIn() sees a fragment with no conversion in it',
        );
        self::assertSame(
            [],
            $census['other'],
            'a sprintf() first argument this census cannot classify: ' . implode(' | ', $census['other'])
            . '. Widen classifyFirstArgument() or hand-classify the site; a shape counted as "not a '
            . 'literal" by default reads as a promotion, which is the direction that hides a regression',
        );

        // KNOWN-ANSWER CONTROL FOR THE CENSUS ITSELF, widened to the shapes the
        // scanner can actually MEET (E162). The version this replaced covered a
        // literal, a constant reference and the word inside a string — none of
        // which exercise the reason a bare `T_STRING` + `(` is not enough:
        // `$o->sprintf(`, `Foo::sprintf(` and `function sprintf(` tokenize the
        // same way and were all counted as calls to the global function, and
        // `\sprintf(` is a single `T_NAME_FULLY_QUALIFIED` and was counted as
        // none. An assertion about this file's own census is not evidence
        // unless something proves the scanner can still tell those apart — and
        // this control is a KNOWN POSITIVE for every bucket, including the two
        // whose real-tree expectation is zero.
        //
        // THAT SENTENCE USED TO QUOTE THE REAL-TREE CENSUS AS `12/8/0/2` and it
        // was stale before the round that wrote it ended: the E164 promotions
        // three commits later moved two literals into constants, so the figures
        // the assertions above actually carry are 12 calls / 3 literal /
        // 9 constant / 0 interpolated. The quote is dropped rather than
        // refreshed, because it was a SECOND COPY of four numbers that already
        // sit ten lines up in executable form — a copy that cannot red when it
        // drifts, which is the whole failure mode this control exists to
        // prevent. Read the assertions, not a comment about them.
        self::assertSame(
            [
                'calls' => 6,
                'literal' => 2,
                'interpolated' => 1,
                'constant' => 2,
                'other' => ["T_VARIABLE '\$format'"],
            ],
            self::sprintfCensus(
                "<?php\n"
                . "sprintf('a %s', 1);\n"                    // literal
                . "\\sprintf('b %s', 2);\n"                  // literal, fully qualified — was invisible
                . "sprintf(self::F, 3);\n"                   // constant reference
                . "sprintf(\\Some\\Where::G, 4);\n"          // constant reference, qualified
                . "sprintf(\"c {\$x} d\", 5);\n"             // interpolated — was read as a constant
                . "sprintf(\$format, 6);\n"                  // a variable: neither, and must be named
                . "\$o->sprintf('not this', 7);\n"           // a method
                . "\$o?->sprintf('nor this', 8);\n"
                . "Other::sprintf('nor this', 9);\n"
                . "function sprintf(\$x) {}\n"               // a declaration
                . "\$s = 'sprintf(';\n",                     // the word inside a string
            ),
            'the sprintf census miscounts a source whose answer is known',
        );

        // …and the `other` bucket is a KNOWN POSITIVE too, in the same test:
        // an assertion of [] above is not evidence that anything can populate it.
        $unclassifiable = self::sprintfCensus("<?php sprintf(\$format, 1); sprintf(self::pick(), 2);");
        self::assertSame(2, $unclassifiable['calls']);
        self::assertSame(0, $unclassifiable['literal'] + $unclassifiable['constant']);
        self::assertCount(
            2,
            $unclassifiable['other'],
            'the census can no longer name a first argument it does not understand, so the [] above is vacuous',
        );
        self::assertStringContainsString('T_VARIABLE', $unclassifiable['other'][0]);
    }

    /**
     * NO FORMAT IN `Bootstrap.php` IS BUILT THROUGH A printf-FAMILY FUNCTION
     * {@see sprintfCensus()} CANNOT SEE.
     *
     * WHY A SECOND SCANNER RATHER THAN A WIDER CENSUS. Everything this file
     * asserts about literal formats — which are promoted, which are inline on
     * purpose, that none is interpolated — is scoped by what the census can
     * find, and the census recognises the single name `sprintf`. That is the
     * shape of hole rule 11 is about: an instrument reports zero offenders and
     * the reason is its alphabet, not the tree. `printf`, `vsprintf`, `vprintf`,
     * `fprintf` and `vfprintf` all take a format, and a literal one reached
     * through any of them would be invisible to every guard above.
     *
     * MEASURED, AND LATENT RATHER THAN LIVE. On PHP 8.3.6 at round 46 the
     * real-tree answer is `[]` — every printf-family token in `Bootstrap.php` is
     * a `sprintf`. Round 46's review reached the same answer by a different
     * instrument, `grep -nEo '\b(v?s?printf|vfprintf|fprintf)\('`, which
     * returned 18 hits, all `sprintf`; the two disagree by construction and both
     * are right, because that grep counts the doc-blocks and this scanner drops
     * `T_COMMENT`/`T_DOC_COMMENT` before it starts. Neither figure is written
     * into an assertion — the assertion is the emptiness.
     *
     * AND THE EMPTINESS IS NOT EVIDENCE ON ITS OWN, which is why the
     * known-positive fixture is in THIS test rather than a neighbouring one.
     * Round 44 emptied a census, asserted "nothing is stale", and stayed green
     * with the scanner mutated to never match; the fixture is what makes an
     * assertion of `[]` mean something. It also carries the negative shapes,
     * because a scanner that matches everything is as useless as one that
     * matches nothing: `sprintf` itself must stay out (the census owns it), and
     * so must a method, a nullsafe method, a static, a declaration and the word
     * inside a string literal.
     */
    public function testNoPrintfFamilyCallEscapesTheCensusAlphabet(): void
    {
        self::assertSame(
            [],
            self::printfFamilyCallsIn(self::bootstrapSource()),
            'Bootstrap.php reaches a printf-family function other than sprintf(). Every guard in this file '
            . 'that reasons about literal formats is scoped by sprintfCensus(), which recognises the name '
            . '`sprintf` and no other, so this call site and whatever format it carries are invisible to '
            . 'all of them. Either route it through sprintf() or widen the census to cover it — and note '
            . 'that fprintf()/vfprintf() take the stream first, so classifyFirstArgument() cannot simply '
            . 'be pointed at their first argument',
        );

        // KNOWN-POSITIVE FIXTURE FOR THE SCANNER THAT JUST ANSWERED [].
        self::assertSame(
            ['printf', 'vsprintf', 'vprintf', 'fprintf', 'vfprintf'],
            self::printfFamilyCallsIn(
                "<?php\n"
                . "printf('a %s', 1);\n"                     // the plain case
                . "\\vsprintf('b %s', [2]);\n"               // fully qualified — the shape T_STRING misses
                . "vprintf('c %s', [3]);\n"
                . "fprintf(STDERR, 'd %s', 4);\n"            // format is the SECOND argument
                . "vfprintf(STDERR, 'e %s', [5]);\n"
                . "sprintf('not this', 6);\n"                // the census owns sprintf
                . "\$o->printf('nor this', 7);\n"            // a method
                . "\$o?->printf('nor this', 8);\n"
                . "Other::printf('nor this', 9);\n"          // a static
                . "function printf(\$x) {}\n"                // a declaration
                . "\\Foo\\printf('nor this', 10);\n"         // a namespaced function, not the global one
                . "\$s = 'printf(';\n",                      // the word inside a string
            ),
            'the printf-family scanner miscounts a source whose answer is known, so the [] above is vacuous',
        );
    }

    /**
     * THE SECOND PARTY, made a real assertion rather than a claim in a
     * doc-block: the two pages that quote the retention summary are rendered
     * FROM {@see Bootstrap::SESSION_RETENTION_SUMMARY_FORMAT}.
     *
     * WHAT THIS PARAGRAPH USED TO SAY, and why it is rewritten rather than
     * deleted (round 46's review, MAJOR 3). IT SAID: "the only one of the six
     * promoted formats whose external reader is a DOC PAGE rather than a test …
     * the other five have their readers in `tests/`". Two things were wrong
     * with that. First the arithmetic: it was written when
     * {@see NAMED_FORMATS} held six, and two later commits in the same round
     * promoted three more without moving the sentence — which is precisely why
     * this file's own class doc-block refuses to write a cardinality into prose,
     * and the rule was broken one screen below where it is stated. Second, and
     * worse, the CLAIM. MEASURED at round 46 by rendering each promoted format's
     * longest literal span and searching the flattened pages:
     * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT} is quoted by README.md
     * AND by `docs/SETTINGS.md`, and {@see Bootstrap::PROJECT_TIER_REFUSAL_FORMAT}
     * by `docs/TROUBLESHOOTING.md`. Doc-page readers are the normal case here,
     * not the exception this claimed to be.
     *
     * WHY THE CASE STILL EARNS ITS OWN TEST: not scarcity, but that a page an
     * operator reads and a line the launcher prints have to agree and neither
     * one is going to notice the other drifting. The tool-removal sample has had
     * that guard since E152 — see
     * {@see \SugarCraft\Crush\Tests\Config\ReadmeSettingsTierClaimTest}, which
     * covers both of its pages. The retention summary's pages are covered here,
     * and the refusal format's page is covered by nothing yet; it quotes the
     * shape with `<path>`/`<reason>` placeholders rather than a rendered sample,
     * so it needs a different instrument and is recorded rather than faked.
     *
     * THE SAMPLE ARGUMENTS ARE THE PAGES' OWN — three sessions, thirty days —
     * because these are illustrative sentences, not captures. What is pinned is
     * the SHAPE around them: reword the format and both pages stop matching.
     *
     * WHITESPACE IS FLATTENED, and that is load-bearing rather than defensive.
     * MEASURED: `docs/SETTINGS.md` wraps this very sentence across a line break
     * between `30+ days` and `(ids on stderr)`, so a raw `str_contains()`
     * against the file finds nothing at all — the same trap a doc-block's
     * ` * ` continuation markers set for prose matching in source.
     */
    public function testTheDocPagesQuoteTheRetentionSummaryTheLauncherActuallyPrints(): void
    {
        $rendered = sprintf(Bootstrap::SESSION_RETENTION_SUMMARY_FORMAT, 3, 'sessions', 30);

        foreach (['docs/ENVIRONMENT.md', 'docs/SETTINGS.md'] as $page) {
            $path = \dirname(__DIR__, 2) . '/' . $page;
            self::assertFileExists($path, "{$page} is quoted as a reader of the retention summary but is gone");

            $flat = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($path));

            self::assertStringContainsString(
                $rendered,
                $flat,
                "{$page} no longer quotes what Bootstrap::SESSION_RETENTION_SUMMARY_FORMAT renders. Either "
                . 'the launcher was reworded and the page was not, or the page was edited away from the '
                . 'line it is describing; both are the drift a name exists to make loud',
            );
        }
    }

    /**
     * THE OTHER DOC PAGE THAT QUOTES A PROMOTED FORMAT, and it quotes a SHAPE
     * rather than a sample, which is why it needed its own instrument.
     *
     * HOW IT WAS FOUND, and the method is the part worth keeping. Round 46
     * closed the same hole twice on the tool-removal line — README.md had a
     * guard, `docs/SETTINGS.md` carried a byte-for-byte copy of the identical
     * sample and had none. So every promoted format was then swept the same
     * way: take its longest span of literal text between conversions, flatten
     * every page, and ask which pages contain it. MEASURED on PHP 8.3.6, that
     * sweep leaves exactly this one unguarded reader.
     *
     * A WEAK NEEDLE IS PART OF THE SWEEP'S ALPHABET AND IT LIED ONCE, which is
     * why this is recorded rather than trusted. This format's longest literal
     * span is `'ignoring '` — nine characters of ordinary English. The sweep
     * reported README.md as a reader too, and README.md's hit is the unrelated
     * sentence "reject one at exit `2` rather than ignoring it". A span short
     * enough to occur by accident cannot answer "who reads this"; it can only
     * nominate candidates for a human to check, and that check is what removed
     * README.md from this test's page list.
     *
     * PLACEHOLDERS, NOT ARGUMENTS. `docs/TROUBLESHOOTING.md` shows operators
     * the shape of the line rather than a rendered instance, so the expectation
     * substitutes the page's own `<path>` and `<reason>` for the two `%s`. That
     * pins strictly less than a rendered sample would — the fields are the
     * page's invention, not the launcher's — but it pins the ENVELOPE, which is
     * the part that carries meaning: the word `ignoring`, the field order, and
     * the spaced em-dash between them.
     */
    public function testTheTroubleshootingPageQuotesTheRefusalShapeTheLauncherActuallyPrints(): void
    {
        $page = 'docs/TROUBLESHOOTING.md';
        $path = \dirname(__DIR__, 2) . '/' . $page;

        self::assertFileExists($path, "{$page} is quoted as a reader of the project-tier refusal but is gone");

        $flat = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($path));

        self::assertStringContainsString(
            sprintf(Bootstrap::PROJECT_TIER_REFUSAL_FORMAT, '<path>', '<reason>'),
            $flat,
            "{$page} no longer quotes the shape Bootstrap::PROJECT_TIER_REFUSAL_FORMAT renders. Either the "
            . 'launcher was reworded and the page was not, or the page renamed its placeholders; the first '
            . 'is drift and the second means this expectation has to learn the new names',
        );
    }

    /**
     * The retention DETAIL row carries its own copy of the stderr envelope, and
     * that relationship is asserted rather than only explained.
     *
     * {@see Bootstrap::SESSION_RETENTION_DETAIL_FORMAT}'s doc-block says the
     * duplication is deliberate — these rows are a continuation indented under
     * the summary, and routing them through the seam would seed one transcript
     * row per deleted session. A justification is not a pin: with nothing
     * checking it, changing {@see Bootstrap::STDERR_LINE_FORMAT}'s label would
     * leave the summary saying `sugarcrush:` and its own continuation rows
     * saying something else, and the only symptom is a scrollback that looks
     * subtly wrong to a human and to nothing else.
     *
     * DERIVED FROM THE ENVELOPE, never retyped: the label is whatever
     * `STDERR_LINE_FORMAT` puts before its conversion, so the two move together
     * by construction and this test reds only when they come APART.
     *
     * The full stop is asserted ABSENT on purpose. The envelope adds one
     * because it wraps a sentence; a continuation row ending in an id list is
     * not one, and a `.` after `messages)` would read as part of the count.
     */
    public function testTheRetentionDetailRowIndentsUnderTheEnvelopeItDuplicates(): void
    {
        $at = strpos(Bootstrap::STDERR_LINE_FORMAT, '%s');
        self::assertIsInt($at, 'STDERR_LINE_FORMAT no longer has a conversion to take a label from');
        $label = substr(Bootstrap::STDERR_LINE_FORMAT, 0, $at);
        self::assertNotSame('', $label, 'the stderr envelope has no label, so there is nothing to indent under');

        self::assertStringStartsWith(
            $label . '  ',
            Bootstrap::SESSION_RETENTION_DETAIL_FORMAT,
            'the retention detail rows no longer open with the stderr envelope\'s own label plus the indent '
            . 'that makes them read as a continuation of the summary above them',
        );
        self::assertStringEndsWith("\n", Bootstrap::SESSION_RETENTION_DETAIL_FORMAT);
        self::assertStringEndsWith(
            ")\n",
            Bootstrap::SESSION_RETENTION_DETAIL_FORMAT,
            'a continuation row gained sentence punctuation; the envelope adds the full stop because it '
            . 'wraps a sentence, and these rows are not one',
        );
    }

    /**
     * THE SWEEP ITSELF: no page quotes a promoted format without this file
     * knowing about it (E187).
     *
     * WHAT REDS, AND WHAT THE RIGHT RESPONSE IS. A page appears under a
     * constant it is not listed against — someone quoted a launch line in the
     * docs and nothing checks it, so either give it a guard and name the guard
     * here, or record why it needs none. A page DISAPPEARS from a constant it
     * is listed against — the page stopped quoting the line, which usually
     * means the launcher was reworded and the page was edited to follow, and
     * the guard named in the roster is now pinning nothing. Both are the drift
     * a name exists to make loud, and neither is visible from either end alone.
     *
     * SET-VALUED ON PURPOSE, NOT COUNT-VALUED. The assertion is over
     * (constant, page) PAIRS. A page that gains a second sentence matching a
     * format it is already listed against does not move it, which is what
     * makes this survivable for the neighbouring lanes that own the doc pages:
     * only a genuinely new relationship reds.
     */
    public function testEveryDocPageQuotingAPromotedFormatIsOnTheReadersRoster(): void
    {
        $pages = self::docPages();

        // The glob is the sweep's reach. An empty or truncated page map would
        // make every assertion below pass by finding nothing at all, which is
        // the exact shape of the round-44 census that stayed green with its
        // scanner dead.
        self::assertGreaterThan(4, \count($pages), 'docPages() found almost nothing; the sweep has no reach');
        foreach (['README.md', 'docs/SETTINGS.md', 'docs/ENVIRONMENT.md', 'docs/TROUBLESHOOTING.md'] as $required) {
            self::assertArrayHasKey($required, $pages, "the sweep no longer reaches {$required}");
        }

        $found = [];
        foreach (self::sweepableFormats() as $constant => $value) {
            $quoting = self::pagesQuoting($value, $pages);
            if ($quoting !== []) {
                $found[$constant] = $quoting;
            }
        }

        $expected = [];
        foreach (self::PAGE_QUOTES as $constant => $verdicts) {
            $expected[$constant] = array_keys($verdicts);
            sort($expected[$constant]);
        }
        ksort($expected);
        ksort($found);

        self::assertSame(
            $expected,
            $found,
            'the set of doc pages quoting a promoted launch format is not the set this file has classified. '
            . 'A page that appeared quotes a line the launcher prints and is checked by nothing — give it a '
            . 'guard and name the guard in self::PAGE_QUOTES, or record there why it needs none. A page that '
            . 'vanished stopped quoting the line, so whatever guard the roster names for it is pinning a '
            . 'sentence that is no longer on the page',
        );
    }

    /**
     * KNOWN-POSITIVE AND KNOWN-NEGATIVE FIXTURES FOR THE SWEEP, in the same
     * test rather than beside it.
     *
     * The test above asserts a SET, and four of the nine promoted formats
     * contribute nothing to it. An assertion that "these five and no others are
     * quoted" is not evidence unless something in the same test proves the
     * scanner can still find a quotation: round 44 emptied a census, mutated
     * its scanner to never match, and watched the absence assertion pass with
     * 18,228 assertions green.
     *
     * THE NEGATIVE CASES ARE THE POINT AS MUCH AS THE POSITIVE ONE. A scanner
     * that matches everything nominates every page and the roster degenerates
     * into a list of the docs directory.
     */
    public function testTheSweepFindsAQuotedFormatOnAPageWhoseAnswerIsKnown(): void
    {
        // The sample as a page would really carry it: wrapped across a line
        // break inside a fence, and preceded by a blockquote marker on the
        // continuation. Flattening is what makes this findable at all — the
        // same trap a doc-block's ` * ` continuation markers set for prose
        // matching in source.
        $pages = self::flattenPages([
            'quotes.md' => "The launcher reports it at startup:\n\n```text\n"
                . "sugarcrush: /repo/.sugar-crush/settings.json (disabledTools) disabled 10 of the\n"
                . "> 12 tools your own settings left — removed: Bash, Edit — leaving: Read\n"
                . "```\n",
            'paraphrases.md' => "A project's disabledTools list can take away tools your own settings left.\n",
        ]);

        self::assertSame(
            ['quotes.md'],
            self::pagesQuoting(Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT, $pages),
            'the sweep can no longer find a rendered launch report on a page that carries one; every set '
            . 'the test above asserts is vacuous',
        );
        self::assertSame(
            [],
            self::pagesQuoting(Bootstrap::SKILL_SKIP_NOTICE_FORMAT, $pages),
            'the sweep nominates a page for a format that page does not contain',
        );

        // THE BOUND, IN BOTH DIRECTIONS. `ignoring %s — %s` is nine characters
        // of ordinary English plus a spaced em dash, and round 46's hand-run
        // sweep nominated README.md on it. The bound is what removes that,
        // and the second page here is the honest statement of its limit: a
        // coincidence whose em dash lands CLOSE enough is still nominated, and
        // only a human reading the page can settle it. That is what the
        // roster's verdict column is for.
        $coincidence = self::flattenPages([
            'far.md' => 'reject one at exit `2` rather than ignoring it.' . str_repeat(' filler', 40) . ' — and on.',
            'near.md' => 'reject one at exit `2` rather than ignoring it, which — as noted — is deliberate.',
        ]);
        self::assertSame(
            ['near.md'],
            self::pagesQuoting(Bootstrap::PROJECT_TIER_REFUSAL_FORMAT, $coincidence),
            'the field bound no longer bounds anything: with an unbounded wildcard every page containing '
            . 'the word "ignoring" and an em dash anywhere after it is nominated as a reader',
        );

        // An escaped `%%` is literal text, not a field. A shape builder that
        // split on it would ask for a wildcard where a per-cent sign belongs
        // and match pages that carry neither.
        $percent = self::flattenPages([
            'literal.md' => 'the cache was 100% warm',
            'wild.md' => 'the cache was 100 somethings warm',
        ]);
        self::assertSame(['literal.md'], self::pagesQuoting('the cache was 100%% warm', $percent));

        // A format that is pure literal text has no fields at all, and must
        // still be matchable.
        self::assertSame(
            ['literal.md'],
            self::pagesQuoting('was 100% warm', $percent),
        );

        // AND A PAGE THE MATCHER CANNOT PARSE GOES RED, NEVER QUIET. This is
        // the arm a `=== 1` test cannot have: `preg_match()` on a `/u` pattern
        // answers `false` for a subject that is not valid UTF-8, and `false`
        // is not `1`, so the page used to leave the sweep indistinguishable
        // from one that carries no quotation. `\xC3\x28` is a truncated
        // two-byte sequence — the shortest thing a stray `latin-1` byte in a
        // markdown page actually looks like.
        $unreadable = self::flattenPages([
            'mojibake.md' => "\xC3\x28 the cache was 100% warm",
        ]);
        try {
            self::pagesQuoting('was 100% warm', $unreadable);
            self::fail('a page the matcher cannot parse was silently recorded as quoting nothing');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('mojibake.md', $e->getMessage());
        }
    }

    /**
     * KNOWN-ANSWER CONTROL FOR THE COLLECTOR, not for the scanner.
     *
     * {@see testTheSweepFindsAQuotedFormatOnAPageWhoseAnswerIsKnown()} proves
     * the matcher can still find a quotation in text it is handed. It says
     * nothing about whether the text is handed over at all, and those are two
     * different instruments with the same failure mode: a collector that
     * quietly stops returning a page makes the roster assertion above pass by
     * finding nothing on it, which is round 44's dead census wearing a
     * different hat.
     *
     * THE THREE SHAPES ARE THE ONES THE OLD ALPHABET COULD NOT EXPRESS —
     * a root page that is not `README.md`, a page nested under `docs/`, and a
     * non-markdown file that must NOT be collected. The last is as load-bearing
     * as the first two: a collector that takes everything would drag
     * `docs/*.json` fixtures and any generated HTML into the sweep, and the
     * roster would then have to grow a row per file rather than per page.
     */
    public function testTheSweepCollectsPagesFromATreeWhoseAnswerIsKnown(): void
    {
        // Unique per run, and torn down by exact path: sibling suites share
        // /tmp and a glob-delete here would take their fixtures with it.
        $root = sys_get_temp_dir() . '/sc_lane_c_sweep_' . bin2hex(random_bytes(8));
        $made = [];
        $write = static function (string $relative, string $text) use ($root, &$made): void {
            $path = $root . '/' . $relative;
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }
            file_put_contents($path, $text);
            $made[] = $path;
        };

        try {
            $write('README.md', 'root readme');
            $write('CHANGELOG.md', 'root changelog');
            $write('notes.txt', 'not markdown');
            $write('docs/FLAT.md', 'a flat docs page');
            $write('docs/nested/DEEP.md', 'a nested docs page');
            $write('docs/nested/schema.json', '{}');

            $found = self::markdownPagesUnder($root);

            self::assertSame(
                ['CHANGELOG.md', 'README.md', 'docs/FLAT.md', 'docs/nested/DEEP.md'],
                array_keys($found),
                'the sweep no longer collects the pages it claims to reach; every set the roster asserts is '
                . 'as large as the collector, and no larger',
            );
            self::assertSame('a nested docs page', $found['docs/nested/DEEP.md']);
        } finally {
            // Exact paths only, deepest first.
            foreach (array_reverse($made) as $path) {
                @unlink($path);
            }
            @rmdir($root . '/docs/nested');
            @rmdir($root . '/docs');
            @rmdir($root);
        }
    }

    /**
     * NO DOC-BLOCK IN THIS FILE FAMILY QUOTES A PHPUNIT CLASS TOTAL (E188).
     *
     * A `Tests:` / `Assertions:` pair reads as a measurement and is also a
     * CARDINALITY OVER A CLASS: any sibling test added anywhere beside the one
     * that was mutated moves it, with no relationship at all to the thing being
     * measured. Round 46 shipped three of them and every one was invalidated by
     * a LATER COMMIT OF THE SAME ROUND. The rule survived as prose in the
     * backlog and prose does not red, which is why it is a test now.
     *
     * WHAT IS BANNED AND WHAT IS NOT. Three shapes are refused: PHPUnit's two
     * output forms — `Tests: <n>` / `Assertions: <n>` and `OK (<n> tests, …)` —
     * and the PROSE form, a figure followed by the word for what it counts.
     * Two things are deliberately allowed. A `Failures: <n>` counts the tests
     * that red, which is a property of the mutation rather than of the class,
     * and survives a sibling landing beside it. And a prose figure ANCHORED in
     * its own sentence — to a round, or to a backticked commit sha — is
     * history rather than a claim about the tree, and no later commit can
     * invalidate it. Naming the failing tests is still better and the
     * doc-blocks above do, but a rule that reds on the honest form as well as
     * the rotten one gets deleted rather than obeyed.
     *
     * THE PROSE SHAPE WAS ADDED AFTER THE HEADLINE ABOVE WAS ALREADY ABSOLUTE.
     * WHAT THIS GUARD CLAIMED when it landed: that no doc-block in this family
     * quotes a class total, full stop. WHAT WAS TRUE: it read only PHPUnit's
     * two literal forms, so a prose total mutated into a guarded file SURVIVED
     * — and one was already live in scope, in a file the same commit had just
     * swept for exactly this. WHY THE EPISODE IS RECORDED RATHER THAN QUIETLY
     * FIXED: an absolute claim paired with a narrow instrument is the defect
     * class this whole file is about, and it was committed inside the fix for
     * it. The alphabet of a guard is part of its claim.
     *
     * THE SCOPE IS THE TWO FILES THIS LANE OWNS, stated rather than widened: a
     * historical figure taken at a NAMED COMMIT is a different animal from one
     * taken at "the tree as it was", and deciding that for a file whose author
     * is not here is not this guard's business. Widening it is a backlog item,
     * not a silent reach.
     *
     * THE FIXTURE IS THE POINT (rule 15). This asserts an ABSENCE, and an
     * absence proves nothing unless something in the same test shows the
     * scanner can still find a presence — round 44 mutated a census's scanner
     * to never match and watched 18,228 assertions stay green. The fixture also
     * pins the FLATTENING: a doc-block wraps at 80 columns with ` * ` on every
     * continuation, so a long total is routinely NOT those bytes in a row, and
     * a scanner that matches the raw source misses exactly the sentences most
     * likely to carry one.
     *
     * AND THE FIXTURE IS ASSEMBLED FROM PARTS RATHER THAN WRITTEN OUT, which is
     * not a style choice: a guard whose known-positive needle is a literal in
     * its own source is a guard that reds on itself. The first draft of this
     * one did, four times over.
     */
    public function testNoDocBlockInThisLanesFilesQuotesAPhpunitClassTotal(): void
    {
        // KNOWN-POSITIVE FIRST, so a dead scanner cannot pass the real check.
        // Assembled, never written out: see the doc-block.
        // The wrap falls INSIDE each figure, not between them. That is the
        // shape the flattening exists for, and the first draft got it wrong:
        // it wrapped between the two, where every token is still contiguous on
        // its own line, so deleting the flattening left the fixture GREEN.
        $tests = 'Tests';
        $assertions = 'Assertions';
        $wrapped = "    /**\n     * measured at `06126017`: that mutation gives `{$tests}:\n"
            . "     * 14, {$assertions}:\n     * 92, Failures: 1`, so it was not blind.\n     */\n";
        self::assertSame(
            [$tests . ': 14', $assertions . ': 92'],
            self::classTotalsIn($wrapped),
            'the class-total scanner can no longer see a total wrapped across a doc-block continuation, '
            . 'which is the only shape a long one ever has; the absence asserted below is vacuous',
        );
        self::assertSame(
            [],
            self::classTotalsIn("    // the mutation reds two tests: `Failures: 2` on PHP 8.3.6\n"),
            'the scanner refuses a Failures: count, which is a property of the mutation and not of the class',
        );

        // THE PROSE ARM, which the literal fixture above cannot exercise and
        // which round 47's review found a live instance of. Assembled from
        // parts for the same reason everything else here is.
        $five = '5';
        $twentySeven = '27';
        self::assertSame(
            [$five . ' tests', $twentySeven . ' assertions'],
            self::classTotalsIn("     * a {$five} tests / {$twentySeven} assertions class at the time.\n"),
            'the scanner cannot see a class total written as prose, which is the form every report in this '
            . 'project uses; the absence asserted below is blind to the commonest shape of the thing it bans',
        );

        // AND THE ANCHORED FORM IS ALLOWED, because a figure that names the
        // event it was taken at cannot be invalidated by a later commit. Both
        // anchor shapes, since a guard that only understands one of them
        // quietly reds correct history written in the other.
        self::assertSame(
            [],
            self::classTotalsIn("     * round 44 watched {$twentySeven} assertions stay green.\n"),
            'the scanner reds a round-anchored historical figure, which is provenance rather than rot',
        );
        self::assertSame(
            [],
            self::classTotalsIn("     * measured at `06126017`, {$twentySeven} assertions redded.\n"),
            'the scanner reds a sha-anchored historical figure',
        );

        // THE WINDOW IS THE SENTENCE, AND THAT IS THE HALF THAT ROTS QUIETLY.
        // An anchor in the sentence BEFORE is proximity, not provenance: widen
        // the window and every figure within a paragraph of the word "round"
        // is excused, which is how a carve-out becomes a hole. Same anchor,
        // same figure, one sentence apart — and it must still be reported.
        self::assertSame(
            [$twentySeven . ' assertions'],
            self::classTotalsIn("     * That was round 44. It watched {$twentySeven} assertions stay green.\n"),
            'the anchor window has grown past the sentence; a figure a paragraph away from any round number '
            . 'is now excused, which is every figure in this file',
        );

        foreach (['tests/Cli/BootstrapLaunchFormatConstantsTest.php', 'tests/Config/ReadmeSettingsTierClaimTest.php'] as $relative) {
            $path = \dirname(__DIR__, 2) . '/' . $relative;
            $source = @file_get_contents($path);
            if ($source === false) {
                // A file the guard cannot read must be loud, never "it is fine".
                throw new \RuntimeException("{$relative} could not be read; this guard cannot answer for it");
            }

            self::assertSame(
                [],
                self::classTotalsIn($source),
                "{$relative} quotes a PHPUnit class total. It reads as evidence and is a cardinality over "
                . 'that class: the next test added anywhere beside the one measured invalidates it, which '
                . 'is how round 46 shipped three stale ones in a single round. Name the tests that red '
                . 'instead, or say "nothing redded" if nothing did',
            );
        }
    }

    /**
     * Every UNANCHORED class-scoped total `$source` quotes, in order.
     *
     * Continuation markers are flattened first — see the caller for why that is
     * load-bearing rather than defensive.
     *
     * TWO SHAPES, AND THE SECOND ONE WAS THE HOLE. This scanner used to read
     * only PHPUnit's own two output forms — `Tests: <n>` / `Assertions: <n>`
     * and `OK (<n> tests, …)` — while the guard above it claimed, without
     * qualification, that no doc-block in this family quotes a class total.
     * Round 47's review mutated a PROSE total into a guarded file and it
     * SURVIVED. Prose is the form this project's own reports and briefs
     * actually use, so the instrument was blind to the commonest shape of the
     * thing it was named after — and a live instance was sitting inside its
     * own scope, in a file the same commit had just swept.
     *
     * AN ANCHORED FIGURE IS A DIFFERENT ANIMAL AND IS ALLOWED. A count that
     * names the event it was taken at cannot be invalidated by a later commit:
     * it is history, not a claim about the tree. So a prose figure whose OWN
     * SENTENCE carries an anchor — `round <n>`, or a backticked commit sha —
     * is passed over, and one whose sentence does not is reported. The window
     * is the sentence deliberately: an anchor a paragraph away is not
     * provenance, it is proximity, and the difference is exactly what
     * {@see testNoDocBlockInThisLanesFilesQuotesAPhpunitClassTotal()}'s
     * next-sentence fixture pins.
     *
     * THE CARVE-OUT IS PROSE-ONLY, and that asymmetry is a decision rather than
     * an oversight. PHPUnit's two literal forms are runner output: they read as
     * a fresh measurement of the current tree whatever sentence they sit in,
     * which is precisely how round 46 shipped three stale ones. Prose is how a
     * sentence cites history. Whether an anchored LITERAL should also be
     * allowed is a real question and not this guard's to settle — this file's
     * own known-positive fixture is one, and the guard still refuses it, which
     * is the behaviour the fixture depends on.
     *
     * @return list<string>
     */
    private static function classTotalsIn(string $source): array
    {
        $flat = (string) preg_replace('/\s*\n\s*(?:\*|\/\/)?\s*/', ' ', $source);

        $found = [];

        // PHPUnit's own output, in either form. Never excused by an anchor.
        preg_match_all(self::CLASS_TOTAL_LITERAL, $flat, $literals, PREG_OFFSET_CAPTURE);
        $spans = [];
        foreach ($literals[0] as [$hit, $offset]) {
            $spans[] = [$offset, $offset + \strlen($hit)];
            $found[$offset] = (string) preg_replace('/\s+/', ' ', $hit);
        }

        // The prose form: a figure, then the word `tests` or `assertions`. No
        // example is written out here — this scanner reads its own file, and
        // the first draft of the guard above learned that lesson the hard way.
        // The fixture assembles one from parts instead.
        preg_match_all(self::CLASS_TOTAL_PROSE, $flat, $prose, PREG_OFFSET_CAPTURE);
        foreach ($prose[0] as [$hit, $offset]) {
            foreach ($spans as [$from, $to]) {
                // Already reported as part of an `OK (…)`; not a second figure.
                if ($offset >= $from && $offset < $to) {
                    continue 2;
                }
            }
            if (preg_match(self::CLASS_TOTAL_ANCHOR, self::sentenceAround($flat, $offset)) === 1) {
                continue;
            }
            $found[$offset] = (string) preg_replace('/\s+/', ' ', $hit);
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * The sentence of `$flat` containing the byte at `$offset`.
     *
     * Bounded by `. ` on either side, which is coarse on purpose: an
     * abbreviation splits the sentence early, the window shrinks, and a figure
     * is MORE likely to be reported. A guard that fails toward loud on text it
     * cannot parse is the right direction for this one.
     */
    private static function sentenceAround(string $flat, int $offset): string
    {
        $start = 0;
        $previous = strrpos(substr($flat, 0, $offset), '. ');
        if ($previous !== false) {
            $start = $previous + 2;
        }

        $next = strpos($flat, '. ', $offset);

        return substr($flat, $start, ($next === false ? \strlen($flat) : $next + 1) - $start);
    }

    /**
     * Every guard {@see PAGE_QUOTES} names still exists.
     *
     * A roster row is a claim that some test pins that page against that
     * constant. Deleting or renaming the guard leaves the row asserting
     * nothing while still reading, to the next person who opens this file, as
     * though the page were covered — which is the same failure as a promoted
     * constant the emitting code does not use.
     */
    public function testEveryGuardTheReadersRosterNamesStillExists(): void
    {
        $checked = 0;
        foreach (self::PAGE_QUOTES as $constant => $verdicts) {
            foreach ($verdicts as $page => $verdict) {
                if (!str_contains($verdict, '::')) {
                    self::assertSame(
                        self::PAGE_QUOTE_IS_PROSE,
                        $verdict,
                        "self::PAGE_QUOTES[{$constant}][{$page}] is neither a Class::method guard reference "
                        . 'nor one of this file\'s stated reasons for there being no guard',
                    );

                    continue;
                }

                [$class, $method] = explode('::', $verdict, 2);
                self::assertTrue(
                    method_exists($class, $method),
                    "self::PAGE_QUOTES[{$constant}][{$page}] names {$verdict}, which does not exist. The "
                    . 'page is listed as guarded and is not',
                );
                $checked++;
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'no roster row names a guard at all, so this test proves nothing about any of them',
        );
        self::assertFalse(
            method_exists(self::class, 'testNoSuchGuardWasEverWritten'),
            'method_exists() answers true for a method that does not exist; the check above is vacuous',
        );
    }

    /**
     * Every promoted constant is either swept or excused, and being excused is
     * a decision recorded here rather than a silence.
     *
     * A GUARD MUST GO RED ON WHAT IT CANNOT PARSE. {@see sweepableFormats()}
     * is the sweep's domain, and the way this file loses coverage is by a
     * newly promoted constant not being in it — a page could then quote it
     * freely. The domain is derived from {@see NAMED_FORMATS} and
     * {@see NAMED_LITERALS} rather than typed out, so a promotion widens the
     * sweep in the same commit that makes it.
     */
    public function testTheSweepsDomainIsEveryPromotedConstant(): void
    {
        self::assertSame(
            array_keys(self::obligations()),
            array_keys(self::sweepableFormats()),
            'the sweep no longer covers exactly the promoted constants; one of them can now be quoted by a '
            . 'doc page without this file noticing',
        );

        foreach (self::PAGE_QUOTES as $constant => $_) {
            self::assertArrayHasKey(
                $constant,
                self::sweepableFormats(),
                "self::PAGE_QUOTES lists readers for {$constant}, which the sweep does not cover",
            );
        }
    }

    // ── the scanners ─────────────────────────────────────────────────────

    /**
     * Every promoted constant's VALUE, keyed by name — the sweep's domain.
     *
     * @return array<string, string>
     */
    private static function sweepableFormats(): array
    {
        $out = [];
        foreach (self::obligations() as $constant => $_) {
            $out[$constant] = (string) \constant(Bootstrap::class . '::' . $constant);
        }

        return $out;
    }

    /**
     * Every markdown page this package ships, flattened.
     *
     * DERIVED, NOT LISTED — that is the half of the sweep a hand-run pass
     * cannot have. A page added next round is swept without anyone remembering
     * to extend a roster.
     *
     * THE PAGE SET IS THE SWEEP'S ALPHABET, and round 47 widened it rather
     * than believing its own zero. WHAT THIS SAID: "`README.md` and every page
     * under `docs/`", implemented as `glob('docs/*.md')` beside one hard-coded
     * `README.md`. WHAT IS TRUE NOW: {@see markdownPagesUnder()} walks `docs/`
     * RECURSIVELY and takes every `*.md` at the package root, so
     * `CHANGELOG.md`, `CALIBER_LEARNINGS.md` and a page filed under
     * `docs/<anything>/` are swept too — three shapes the old alphabet could
     * not express at all, each of which would have restored exactly the
     * silence E187 exists to end. WHY THE OLD READING STILL EARNS ITS PLACE:
     * it is why the widening is free. MEASURED on PHP 8.3.6 at round 47 —
     * `docs/` holds no subdirectory today, and neither `CHANGELOG.md` nor
     * `CALIBER_LEARNINGS.md` quotes any promoted format —
     * so the wider alphabet nominates the SAME (constant, page) set the
     * narrow one did. A widening that changes no verdict today is the only
     * kind that can be adopted without renegotiating the roster, and it is
     * the reach, not the verdict, that was the hole.
     *
     * @return array<string, string> package-relative page path => flattened text
     */
    private static function docPages(): array
    {
        return self::flattenPages(self::markdownPagesUnder(\dirname(__DIR__, 2)));
    }

    /**
     * Every `*.md` at `$root` plus every `*.md` anywhere beneath `$root/docs`,
     * RAW — keyed by the path relative to `$root`, sorted.
     *
     * SEPARATE FROM {@see docPages()} AND TAKING ITS ROOT AS A PARAMETER so
     * the collector can be run against a tree whose answer is known.
     * {@see testTheSweepCollectsPagesFromATreeWhoseAnswerIsKnown()} is that
     * control, and it is the half of rule 15 the sweep did not have: the
     * scanner had a known-positive fixture from the day it landed, the
     * COLLECTOR had none, and a collector that silently returns fewer pages
     * makes every "no unguarded quote" verdict above it vacuous in exactly the
     * way a dead scanner would.
     *
     * @return array<string, string> path relative to $root => raw text
     */
    private static function markdownPagesUnder(string $root): array
    {
        $paths = [];
        $rootPages = glob($root . '/*.md');
        if ($rootPages === false) {
            throw new \RuntimeException('the root markdown glob failed; the sweep cannot answer for any page');
        }
        foreach ($rootPages as $page) {
            $paths[] = basename($page);
        }

        if (is_dir($root . '/docs')) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/docs', \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($walk as $entry) {
                if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'md') {
                    $paths[] = substr($entry->getPathname(), \strlen($root) + 1);
                }
            }
        }
        sort($paths);

        $out = [];
        foreach ($paths as $path) {
            $text = @file_get_contents($root . '/' . $path);
            if ($text === false) {
                // A page the sweep cannot read must be loud. Answering "it
                // quotes nothing" for it is a hole shaped exactly like the
                // next unguarded quotation.
                throw new \RuntimeException("{$path} could not be read; the sweep cannot answer for it");
            }
            $out[$path] = $text;
        }

        return $out;
    }

    /**
     * Collapse each page's runs of whitespace to one space.
     *
     * LOAD-BEARING RATHER THAN DEFENSIVE, and measured: `docs/SETTINGS.md`
     * wraps the retention summary across a line break between `30+ days` and
     * `(ids on stderr)`, so a raw `str_contains()` against that file finds
     * nothing at all. Markdown wraps prose wherever the column runs out, which
     * means every multi-word needle in this file has to be matched flat.
     *
     * @param array<string, string> $pages
     *
     * @return array<string, string>
     */
    private static function flattenPages(array $pages): array
    {
        return array_map(
            static fn(string $text): string => (string) preg_replace('/\s+/', ' ', $text),
            $pages,
        );
    }

    /**
     * Which of `$pages` quote `$format`.
     *
     * THE SHAPE, NOT THE LONGEST SPAN, and the difference is what makes this a
     * test rather than a nomination list. Round 46's hand-run sweep took each
     * format's longest run of literal text between conversions and searched for
     * that one string; for `ignoring %s — %s` the longest run is `'ignoring '`,
     * nine characters of ordinary English, and the sweep duly nominated
     * README.md over an unrelated sentence. Matching the WHOLE format — every
     * literal span in order, with each conversion standing for a bounded run of
     * page text — uses all of the format's text instead of the best ninth of
     * it, and MEASURED on PHP 8.3.6 it drops that false positive while keeping
     * `docs/TROUBLESHOOTING.md`, which quotes the shape with `<path>` and
     * `<reason>` substituted for the two conversions. A `%s` replaced by a
     * placeholder NAME is still a quotation of the format, and it is how that
     * page hid.
     *
     * @param array<string, string> $pages already flattened
     *
     * @return list<string>
     */
    private static function pagesQuoting(string $format, array $pages): array
    {
        $pattern = self::shapePatternFor($format);

        $out = [];
        foreach ($pages as $page => $text) {
            $hit = preg_match($pattern, $text);
            if ($hit === false) {
                // A PAGE THE MATCHER CANNOT PARSE MUST BE LOUD, and `=== 1`
                // alone made it silent. {@see shapePatternFor()} emits a `/u`
                // pattern, and on PHP 8.3.6 `preg_match()` answers a subject
                // that is not valid UTF-8 with `false` — which is `!== 1`, so
                // the page dropped out of the sweep reporting "quotes
                // nothing", the exact verdict an unguarded quotation would
                // also produce. {@see markdownPagesUnder()} already refuses a
                // page it cannot READ for this reason; the matcher was
                // swallowing what the collector refuses.
                throw new \RuntimeException(\sprintf(
                    '%s could not be matched against the shape of "%s" (%s); the sweep cannot answer for it',
                    $page,
                    $format,
                    \preg_last_error_msg(),
                ));
            }
            if ($hit === 1) {
                $out[] = $page;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * `$format` as a pattern matching any rendering of it: literal spans
     * quoted, each conversion a bounded wildcard.
     *
     * `%%` IS LITERAL TEXT AND MUST NOT BECOME A WILDCARD — it is on
     * {@see conversionSpecsIn()}'s alphabet because `sprintf()` consumes it,
     * but it emits a per-cent sign rather than an argument, so splitting on it
     * would ask a page for a field where a `%` belongs.
     *
     * A LEADING OR TRAILING CONVERSION CONTRIBUTES NO WILDCARD. `%s (…)` needs
     * no run of page text before the space to be a quotation of itself, and
     * demanding one only makes the match harder to read in a failure message.
     */
    private static function shapePatternFor(string $format): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $format));

        // A sentinel no format can contain: token_get_all()-safe, and NUL is
        // rejected by every literal in this file's domain.
        $escaped = str_replace('%%', "\0", $flat);
        $parts = preg_split('/' . self::CONVERSION_PATTERN . '/', $escaped);
        if ($parts === false) {
            throw new \RuntimeException("the conversion split failed for format: {$format}");
        }
        $parts = array_map(static fn(string $p): string => str_replace("\0", '%', $p), $parts);

        $pattern = '';
        foreach ($parts as $i => $part) {
            if ($i > 0 && $part !== '' && $parts[$i - 1] !== '') {
                $pattern .= '.{1,' . self::PAGE_QUOTE_FIELD_BYTES . '}?';
            }
            $pattern .= preg_quote($part, '/');
        }

        return '/' . $pattern . '/u';
    }


    /** @return array<string, string> constant name => the method obliged to use it */
    private static function obligations(): array
    {
        $out = self::NAMED_LITERALS;
        foreach (self::NAMED_FORMATS as $constant => $spec) {
            $out[$constant] = $spec['method'];
        }

        return $out;
    }

    private static function bootstrapSource(): string
    {
        return (string) file_get_contents((string) (new \ReflectionClass(Bootstrap::class))->getFileName());
    }

    /**
     * The significant tokens of one method's body, brace-matched.
     *
     * BRACE-MATCHED AND NOT `strpos("\n    }\n")`: indentation is a convention a
     * reformat may change, and a body that happens to contain a nested `}` at
     * class-body indentation inside a heredoc would truncate silently. The
     * fixture in {@see testTheScannersAnswerCorrectlyOnSourcesWhoseAnswerIsKnown()}
     * covers the nesting case.
     *
     * @return list<array{0: int, 1: string}|string>
     */
    private static function methodBody(string $method, ?string $source = null): array
    {
        $significant = [];
        foreach (token_get_all($source ?? self::bootstrapSource()) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $at = null;
        foreach ($significant as $i => $token) {
            $previous = $significant[$i - 1] ?? null;
            if (
                \is_array($token) && $token[0] === T_STRING && $token[1] === $method
                && \is_array($previous) && $previous[0] === T_FUNCTION
            ) {
                $at = $i;

                break;
            }
        }

        if ($at === null) {
            throw new \RuntimeException("Bootstrap has no method {$method}(); this guard cannot answer for it");
        }

        $depth = 0;
        $body = [];
        for ($i = $at; $i < \count($significant); $i++) {
            $token = $significant[$i];
            if ($token === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
            if ($depth >= 1) {
                $body[] = $token;
            }
        }

        throw new \RuntimeException("Bootstrap::{$method}() has no locatable end");
    }

    /**
     * Every string literal in `$body` that carries a printf conversion.
     *
     * @param list<array{0: int, 1: string}|string> $body
     *
     * @return list<string>
     */
    private static function formatLiteralsIn(array $body): array
    {
        $out = [];
        foreach ($body as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (!\in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (self::conversionsIn($token[1]) > 0 || self::unparsedPercentsIn($token[1]) !== []) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /**
     * Every bare identifier in `$body` — enough to see a `self::CONST`
     * reference without caring which scope operator reached it.
     *
     * @param list<array{0: int, 1: string}|string> $body
     *
     * @return list<string>
     */
    private static function identifiersIn(array $body): array
    {
        $out = [];
        foreach ($body as $token) {
            if (\is_array($token) && $token[0] === T_STRING) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /**
     * Every `%` sequence in `$format` that `sprintf()` would recognise, as
     * matched text.
     *
     * THE ALPHABET IS PART OF THE COVERAGE, and this pattern's first version
     * could not express two of the forms PHP accepts. MEASURED on PHP 8.3.6:
     * `%1$s and %2$s` answered 0 conversions and `%'*10s` answered 0, so a
     * re-inlined POSITIONAL format was not an offender at all —
     * {@see formatLiteralsIn()} gates on the count being above zero. Round 45's
     * review re-inlined exactly that and the guard whose doc-block says a
     * printf conversion is the marker stayed silent; the mutation died on an
     * unrelated census count instead.
     *
     * The order of the optional groups is the order `sprintf()` parses them:
     * argnum, then flags (`'` takes the next character as the pad), then width,
     * then precision, then the specifier.
     *
     * @return list<string>
     */
    private static function conversionSpecsIn(string $format): array
    {
        preg_match_all('/' . self::CONVERSION_PATTERN . '/', $format, $m);

        /** @var list<string> $out */
        $out = $m[0];

        return $out;
    }

    /** How many conversions `sprintf()` would consume from `$format`; `%%` is not one. */
    private static function conversionsIn(string $format): int
    {
        $specs = self::conversionSpecsIn($format);

        return \count($specs) - \count(array_filter($specs, static fn(string $c): bool => $c === '%%'));
    }

    /**
     * The `%` sequences in `$format` that {@see conversionSpecsIn()} could not
     * account for.
     *
     * A GUARD MUST GO RED ON WHAT IT CANNOT PARSE, NOT SILENTLY SKIP IT. Every
     * `%` in a string reaching `sprintf()` as a format is either a conversion
     * or an escaped `%%`; anything else is a `ValueError` at runtime on PHP 8,
     * and answering "zero conversions" for it is a hole shaped exactly like the
     * next format this pattern has not met. {@see formatLiteralsIn()} reports a
     * literal carrying one as an offender rather than passing it over.
     *
     * @return list<string>
     */
    private static function unparsedPercentsIn(string $format): array
    {
        $remainder = str_replace(self::conversionSpecsIn($format), '', $format);

        preg_match_all('/%.{0,3}/s', $remainder, $m);

        /** @var list<string> $out */
        $out = $m[0];

        return $out;
    }

    /**
     * Every string literal in `$body`, in source order, without duplicates.
     *
     * The token text and not the decoded value: `'disabledTools'` and
     * `"disabledTools"` are different literals to a reader deciding whether one
     * of them is a re-inlined message, and collapsing them would hide the
     * quoting change that usually comes with a re-inline.
     *
     * @param list<array{0: int, 1: string}|string> $body
     *
     * @return list<string>
     */
    private static function literalsIn(array $body): array
    {
        $out = [];
        foreach ($body as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (!\in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (!\in_array($token[1], $out, true)) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /**
     * Every `sprintf()` CALL SITE in `$source`, classified by what its first
     * argument is.
     *
     * WHY THREE BUCKETS AND NOT TWO — E163, and it is the direction of the lie
     * that makes it worth the code. The first cut asked one question,
     * "is the first token `T_CONSTANT_ENCAPSED_STRING`?", and everything else
     * fell into a single not-a-literal bucket. MEASURED on PHP 8.3.6:
     * `sprintf("a {$x} b", 1)` does not open with that token at all — the lexer
     * splits an interpolated double-quoted string into a bare `"` character
     * token, `T_ENCAPSED_AND_WHITESPACE`, the interpolation, and a closing `"`.
     * So an INTERPOLATED format landed in the same bucket as
     * `self::STDERR_LINE_FORMAT`. Today both non-literal sites in
     * `Bootstrap.php` really are constants, so the two-bucket census answered
     * correctly by accident — and the accident is load-bearing: re-inline a
     * promoted format as `"… {$path} …"` and the census would have read it as
     * one MORE promoted site. The classifier said the opposite of the truth in
     * precisely the case the guard exists to catch.
     *
     * `other` IS LOUD, NOT A SHRUG. A heredoc, a nowdoc, a variable, a call —
     * anything this classifier has not met — is named in `other` rather than
     * quietly counted as a constant, so
     * {@see testTheLiteralFormatCensusHasAGenerator()} reds and someone
     * classifies it. A guard that silently ignores what it cannot parse has a
     * hole shaped exactly like the next defect.
     *
     * THE CALL-SITE TEST IS ALSO NARROWER THAN IT WAS (E162). `sprintf` as a
     * bare `T_STRING` followed by `(` is also how `$o->sprintf(`,
     * `Foo::sprintf(` and `function sprintf(` tokenize — measured, same box —
     * so all three used to be counted as calls to the global function. They are
     * excluded by their preceding token now, and the fixture in
     * {@see testTheScannersAnswerCorrectlyOnSourcesWhoseAnswerIsKnown()} runs
     * each shape through this scanner. In the other direction, `\sprintf(` is
     * `T_NAME_FULLY_QUALIFIED` on PHP 8 and was MISSED entirely; it is counted
     * now.
     *
     * @return array{calls: int, literal: int, interpolated: int, constant: int, other: list<string>}
     */
    private static function sprintfCensus(string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $out = ['calls' => 0, 'literal' => 0, 'interpolated' => 0, 'constant' => 0, 'other' => []];
        foreach ($significant as $i => $token) {
            if (!self::isGlobalSprintfName($token)) {
                continue;
            }
            if (($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            // A method or a declaration wearing the same name. `$o->sprintf(`,
            // `$o?->sprintf(`, `Foo::sprintf(`, `function sprintf(` and
            // `new sprintf(` all put `sprintf` in a T_STRING followed by `(`.
            $previous = $significant[$i - 1] ?? null;
            if (\is_array($previous) && \in_array($previous[0], [
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
                T_DOUBLE_COLON,
                T_FUNCTION,
                T_NEW,
            ], true)) {
                continue;
            }

            $out['calls']++;
            $kind = self::classifyFirstArgument($significant, $i + 2);
            if ($kind === 'other') {
                $out['other'][] = self::describeToken($significant[$i + 2] ?? null);

                continue;
            }
            $out[$kind]++;
        }

        return $out;
    }

    /** Whether `$token` names the global `sprintf`, qualified or not. */
    private static function isGlobalSprintfName(array|string $token): bool
    {
        return self::isGlobalFunctionNamed($token, ['sprintf']);
    }

    /**
     * Whether `$token` names one of the global functions `$names`, qualified or
     * not.
     *
     * @param list<string> $names
     */
    private static function isGlobalFunctionNamed(array|string $token, array $names): bool
    {
        if (!\is_array($token)) {
            return false;
        }
        if ($token[0] === T_STRING) {
            return \in_array(strtolower($token[1]), $names, true);
        }

        // `\sprintf(` — one token on PHP 8, and invisible to a T_STRING test.
        // Only a leading separator is stripped, so `\Foo\sprintf` stays out.
        if ($token[0] !== T_NAME_FULLY_QUALIFIED) {
            return false;
        }

        return \in_array(ltrim(strtolower($token[1]), '\\'), $names, true);
    }

    /**
     * Every printf-FAMILY call in `$source` that is NOT `sprintf()`, by name.
     *
     * THE CENSUS'S ALPHABET IS ONE FUNCTION NAME, AND THAT IS WHAT THIS GUARDS.
     * {@see sprintfCensus()} is what lets this file claim that every literal
     * format in `Bootstrap.php` is either promoted or inline on purpose, and the
     * whole of that claim rests on finding the call sites. It looks for
     * `sprintf` and for nothing else. `printf`, `vsprintf`, `vprintf`,
     * `fprintf` and `vfprintf` each take a format string too, and a literal
     * format reached through any of them is not merely miscounted — it is
     * reported as not existing, which reads as "no offender here".
     *
     * LATENT, NOT LIVE, AND MEASURED SO: on PHP 8.3.6 at round 46 every
     * printf-family token in `Bootstrap.php` is a `sprintf`, so the real-tree
     * answer is `[]`. A hole nothing has fallen into yet is exactly when an
     * absence guard is cheap to install and worthless to trust — hence the
     * known-positive fixture inside
     * {@see testNoPrintfFamilyCallEscapesTheCensusAlphabet()} rather than beside
     * it.
     *
     * IT REPORTS NAMES, NOT A COUNT, because the useful failure message is which
     * function was reached for.
     *
     * IT DOES NOT CLASSIFY THE FORMAT, deliberately, and stops at "one is here".
     * `fprintf()` and `vfprintf()` take the STREAM first and the format second,
     * so {@see classifyFirstArgument()} would read the wrong argument for two of
     * the five and answer `other` — a shape that already means something else.
     * The honest report is that the census cannot speak for this site at all,
     * and that is what a red here says.
     *
     * @return list<string>
     */
    private static function printfFamilyCallsIn(string $source): array
    {
        $names = ['printf', 'vprintf', 'vsprintf', 'fprintf', 'vfprintf'];

        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $out = [];
        foreach ($significant as $i => $token) {
            if (!self::isGlobalFunctionNamed($token, $names)) {
                continue;
            }
            if (($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            // The same method/declaration shapes {@see sprintfCensus()} excludes.
            $previous = $significant[$i - 1] ?? null;
            if (\is_array($previous) && \in_array($previous[0], [
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
                T_DOUBLE_COLON,
                T_FUNCTION,
                T_NEW,
            ], true)) {
                continue;
            }

            /** @var array{0: int, 1: string} $token */
            $out[] = ltrim(strtolower($token[1]), '\\');
        }

        return $out;
    }

    /**
     * `literal`, `interpolated`, `constant` or `other` for the argument
     * starting at `$at`.
     *
     * @param list<array{0: int, 1: string}|string> $significant
     */
    private static function classifyFirstArgument(array $significant, int $at): string
    {
        $token = $significant[$at] ?? null;
        if ($token === null) {
            return 'other';
        }

        // The lexer's own answer: a double-quoted string it had to split for an
        // interpolation opens with a bare `"`, never with the encapsed-string
        // token. A backtick opens a shell-exec expression, which is neither.
        if ($token === '"') {
            return 'interpolated';
        }
        if (!\is_array($token)) {
            return 'other';
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return 'literal';
        }

        // A name, possibly `A::B` or `\A\B::C`. It is a CONSTANT reference only
        // if nothing calls it — `self::format()` is a computed format and has
        // to be classified by hand rather than counted as a promotion.
        $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
        if (!\in_array($token[0], $nameTokens, true)) {
            return 'other';
        }

        $i = $at;
        while (true) {
            $next = $significant[$i + 1] ?? null;
            if (\is_array($next) && $next[0] === T_DOUBLE_COLON) {
                $after = $significant[$i + 2] ?? null;
                if (!\is_array($after) || !\in_array($after[0], $nameTokens, true)) {
                    return 'other';
                }
                $i += 2;

                continue;
            }

            break;
        }

        return ($significant[$i + 1] ?? null) === '(' ? 'other' : 'constant';
    }

    /** A short, stable description of a token for an `other` row's failure message. */
    private static function describeToken(array|string|null $token): string
    {
        if ($token === null) {
            return '<end of file>';
        }
        if (!\is_array($token)) {
            return 'char ' . var_export($token, true);
        }

        return token_name($token[0]) . ' ' . var_export($token[1], true);
    }
}
