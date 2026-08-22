<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * How many of {@see \SugarCraft\Crush\Cli\Bootstrap}'s launch warnings are
 * routed onto the transcript seam — counted, not remembered.
 *
 * WHY THIS FILE EXISTS. The number is quoted in prose in NINE places (this
 * file's {@see PROSE_SITES}) and it has gone stale three rounds running: it
 * read FOURTEEN when the answer was fifteen (round 42, E78), then still
 * FOURTEEN when the answer was sixteen (round 43, E86), and round 44 (E97)
 * found three sites still saying fourteen. A count that lives only in prose
 * drifts every time a call site is added, and nothing goes red. This file is
 * the thing that goes red.
 *
 * WHAT `PROSE_SITES` SAID WHEN IT WAS WRITTEN: "four places". WHAT IS TRUE NOW:
 * nine, and the four it had were not even the whole of what round 44 had
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
 * that is not the `(...)` of a first-class callable. Stripping whitespace
 * first is what makes it survive a call reformatted across lines; requiring
 * the scope token is what excludes the `function` declaration; requiring the
 * paren is what excludes a string reference and a `[self::class, 'name']`
 * callable array.
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
 * answered by editing nine prose sentences to a wrong number. Every branch of
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
 * WHY THE CONSTANT LIVES HERE AND NOT ON `Bootstrap`. A `public const
 * TRANSCRIPT_SEAM_CALL_SITES` on `Bootstrap` itself would be the better home:
 * it would sit next to the thing it counts, and the prose sites could `{@see}`
 * it instead of spelling a word. It is not there because `Bootstrap.php` was
 * another lane's file in the round that wrote this test, and reaching into it
 * would have collided. Promoting it is backlog **E118**; when that happens,
 * {@see EXPECTED_CALL_SITES} becomes a second opinion rather than the only one,
 * and this test should assert the two agree. (E118 and not E104: round 44's
 * brief cited E104 for this, and E104 is a different item — extracting
 * `Bootstrap`'s stderr `sprintf` FORMATS to constants. The two are neighbours
 * and want the same treatment, so they are worth doing together, but they are
 * not the same entry.)
 *
 * @see \SugarCraft\Crush\Tests\Cli\BootstrapLaunchNoticeRoutingTest for the
 *      behavioural guard — which call sites actually reach the transcript on a
 *      real launch. This file only counts them; that file proves they work.
 */
final class BootstrapTranscriptSeamCallSiteCensusTest extends TestCase
{
    /**
     * The number of `warnPermissionConfigInTranscript(` CALL sites in
     * `Bootstrap.php`, by the definition in this class's doc-block.
     *
     * Bumping this is the correct response to adding a call site — but bump the
     * nine {@see PROSE_SITES} in the same commit, which is the whole point of
     * {@see testTheProseCountsMatchTheTokenScan()} failing alongside it.
     */
    private const EXPECTED_CALL_SITES = 16;

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
     * flawed method beats "four" with none.
     *
     * ONE SENTENCE IS DELIBERATELY NOT A ROW, and it is not an oversight:
     * `Bootstrap::reportSkillSkips()`'s comment says a transcript "also has to
     * carry ELEVEN other sources" and should say fifteen. It is backlog E119
     * and it lives in `src/Cli/Bootstrap.php`, which this round's lane does not
     * own — and unlike the two Bootstrap rows below, which only READ the file,
     * adding this row would red the suite until the sentence is corrected.
     * {@see testTheKnownStaleSentenceOutsideThisLaneIsStillStale()} pins the
     * gap so it closes itself: the day E119 lands, that test fails and tells
     * whoever landed it to move the sentence into this list.
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

    public function testTheTokenScanFindsExactlyTheExpectedNumberOfCallSites(): void
    {
        self::assertSame(
            self::EXPECTED_CALL_SITES,
            self::countSeamCallSites(),
            'A call site was added to or removed from Bootstrap::warnPermissionConfigInTranscript(). '
                . 'Update self::EXPECTED_CALL_SITES and every sentence in self::PROSE_SITES in the same commit.',
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
            $matched = preg_match($site['anchor'], $source, $m);

            self::assertSame(
                1,
                $matched,
                "{$label}: the anchor no longer matches {$site['file']}. The sentence was rewritten past it. "
                    . 'Re-point the anchor in self::PROSE_SITES — do not delete the row, that is how the count '
                    . 'went stale three rounds running.',
            );

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
     * whose whole output is one integer that nine prose sentences are then
     * corrected against. If it over-counts by one, the correct response LOOKS
     * like "someone added a call site" and the repair is nine sentences edited
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
        yield 'a doc-block reference' => ["<?php /** {@see {$m}()} */ \$x = 1;", 0];
        yield 'a line comment quoting the call form' => ["<?php // self::{$m}(\n\$x = 1;", 0];
        yield 'a string spelling the call form' => ["<?php \$x = 'self::{$m}(';", 0];
        yield 'a first-class callable' => ["<?php \$f = self::{$m}(...);", 0];
        yield 'a callable array' => ["<?php \$f = [self::class, '{$m}'];", 0];
        yield 'a bare function of the same name' => ["<?php {$m}('x');", 0];
        yield 'two calls and one callable' => ["<?php self::{$m}('a'); \$f = self::{$m}(...); self::{$m}('b');", 2];

        // A known-answer control for the harness itself: a source with nothing
        // of the sort in it must answer 0, so a scan that has silently started
        // counting something else shows up here rather than in the number nine
        // sentences are corrected against.
        yield 'a source with no mention at all' => ['<?php echo 1;', 0];
    }

    /** @dataProvider scanDefinitionCases */
    public function testTheScanDefinitionAgreesWithItsDocBlock(string $source, int $expected): void
    {
        self::assertSame($expected, self::countSeamCallSites($source));
    }

    /**
     * One sentence in the family is knowingly stale and knowingly not a
     * {@see PROSE_SITES} row. This is the pin that makes the gap close itself.
     *
     * `Bootstrap::reportSkillSkips()` says a transcript "also has to carry
     * eleven other sources" where the answer is fifteen — backlog E119, in a
     * file this lane does not own. A silent gap in a census is the shape of the
     * next defect, so the gap is ASSERTED: when E119 lands, this test fails,
     * and its message is the instruction for closing it. Delete this test in
     * the same commit that adds the row.
     */
    public function testTheKnownStaleSentenceOutsideThisLaneIsStillStale(): void
    {
        $matched = preg_match(
            '/also has to carry\s+(?:\/\/)?\s*([a-z]+) other sources/i',
            self::bootstrapSource(),
            $m,
        );

        self::assertSame(
            1,
            $matched,
            'the known-stale sentence in Bootstrap::reportSkillSkips() has been rewritten past this anchor. '
                . 'If it now quotes the right number, add it to self::PROSE_SITES with offset 1 and delete '
                . 'this test; if it still quotes a wrong one, re-point this anchor.',
        );

        self::assertSame(
            'eleven',
            strtolower($m[1]),
            'E119 appears to have landed: Bootstrap::reportSkillSkips() no longer says "eleven". Move that '
                . "sentence into self::PROSE_SITES with 'offset' => 1 and delete this test — it exists only "
                . 'to keep the deliberate hole in the census visible while the sentence is out of this '
                . "lane's ownership.",
        );

        self::assertNotSame(
            self::NUMBER_WORDS['eleven'],
            self::countSeamCallSites() - 1,
            'the seam has shrunk to twelve call sites, which would make the "eleven other sources" sentence '
                . 'accidentally correct and this pin meaningless. Re-derive E119 before trusting either.',
        );
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
