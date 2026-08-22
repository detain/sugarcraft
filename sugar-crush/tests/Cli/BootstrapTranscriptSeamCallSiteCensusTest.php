<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * How many of {@see \SugarCraft\Crush\Cli\Bootstrap}'s launch warnings are
 * routed onto the transcript seam — counted, not remembered.
 *
 * WHY THIS FILE EXISTS. The number is quoted in prose in four places (this
 * file's {@see PROSE_SITES}) and it has gone stale three rounds running: it
 * read FOURTEEN when the answer was fifteen (round 42, E78), then still
 * FOURTEEN when the answer was sixteen (round 43, E86), and round 44 (E97)
 * found three of the four sites still saying fourteen. A count that lives only
 * in prose drifts every time a call site is added, and nothing goes red. This
 * file is the thing that goes red.
 *
 * WHY `grep` IS THE WRONG TOOL, and why every drift so far came from reaching
 * for it. The identifier `warnPermissionConfigInTranscript` appears in
 * `Bootstrap.php` far more often than it is CALLED: most occurrences are the
 * declaration and `{@see …}` references inside doc-blocks explaining the split.
 * {@see assertTheProseCountsMatchTheTokenScan()} pins that gap explicitly, so a
 * future reader who reaches for `grep -c` and gets a number roughly double the
 * real one has a test telling them why.
 *
 * HOW THE COUNT IS DEFINED. Strip T_WHITESPACE, T_COMMENT and T_DOC_COMMENT
 * from `token_get_all()`, then count every T_STRING equal to the method name
 * that is BOTH preceded by a scope token (`::` or `->`) AND followed by `(`.
 * Stripping whitespace first is what makes it survive a call reformatted
 * across lines; requiring the scope token is what excludes the `function`
 * declaration; requiring the paren is what excludes a first-class-callable or
 * a string reference. As a one-liner, for a reader who wants the number
 * without a test run (whitespace-sensitive, but correct on the current file):
 *
 *     php -r '$t=token_get_all(file_get_contents("src/Cli/Bootstrap.php"));$n=0;
 *     foreach($t as $i=>$x){if(is_array($x)&&$x[0]===T_STRING
 *     &&$x[1]==="warnPermissionConfigInTranscript"&&is_array($t[$i-1]??null)
 *     &&$t[$i-1][0]===T_DOUBLE_COLON&&($t[$i+1]??null)==="("){$n++;}}echo $n,"\n";'
 *
 * WHY THE CONSTANT LIVES HERE AND NOT ON `Bootstrap`. A `public const
 * TRANSCRIPT_SEAM_CALL_SITES` on `Bootstrap` itself would be the better home:
 * it would sit next to the thing it counts, and the prose sites could `{@see}`
 * it instead of spelling a word. It is not there because `Bootstrap.php` was
 * another lane's file in the round that wrote this test, and reaching into it
 * would have collided. Promoting it is backlog **E104**; when that happens,
 * {@see EXPECTED_CALL_SITES} becomes a second opinion rather than the only one,
 * and this test should assert the two agree.
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
     * four {@see PROSE_SITES} in the same commit, which is the whole point of
     * {@see assertTheProseCountsMatchTheTokenScan()} failing alongside it.
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
     * {@see assertTheProseCountsMatchTheTokenScan()}.
     *
     * @var array<string, array{file: string, anchor: string, offset: int}>
     */
    private const PROSE_SITES = [
        'Chat::withLaunchNotices() doc-block' => [
            'file' => 'src/Chat.php',
            'anchor' => '/\b([A-Za-z]+) OF \{@see \\\\SugarCraft\\\\Crush\\\\Cli\\\\Bootstrap\}\'S LAUNCH-WARNING CALL/',
            'offset' => 0,
        ],
        'BootstrapLaunchNoticeRoutingTest class doc-block' => [
            'file' => 'tests/Cli/BootstrapLaunchNoticeRoutingTest.php',
            'anchor' => '/this file is the guard for the other ([a-z]+)\b/i',
            'offset' => 1,
        ],
        'BootstrapLaunchNoticeRoutingTest skipped-skills case' => [
            'file' => 'tests/Cli/BootstrapLaunchNoticeRoutingTest.php',
            'anchor' => '/safe to seat in a transcript that also carries\s+\*?\s*([a-z]+)\b/i',
            'offset' => 1,
        ],
        'McpToolWiringTest partly-started-config case' => [
            'file' => 'tests/Integration/McpToolWiringTest.php',
            'anchor' => '/is not inherited from the other\s+\*?\s*([a-z]+) call sites/i',
            'offset' => 1,
        ],
    ];

    /**
     * Only the words a count in this family could plausibly take. A number word
     * outside this map is not "unknown, skip it" — it is a sentence this guard
     * cannot read, and {@see assertTheProseCountsMatchTheTokenScan()} fails on
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
     */
    private static function countSeamCallSites(): int
    {
        $significant = [];
        foreach (token_get_all(self::bootstrapSource()) as $token) {
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

            if ($scoped && ($significant[$i + 1] ?? null) === '(') {
                $calls++;
            }
        }

        return $calls;
    }
}
