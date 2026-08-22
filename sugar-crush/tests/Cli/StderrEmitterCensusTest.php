<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;

/**
 * Every place in `src/` and `bin/` that can put a line on the user's stderr,
 * counted by channel, so a new one cannot arrive unnoticed.
 *
 * WHY THIS FILE EXISTS (E120). A full suite run prints 62 lines matching
 * `sugarcrush:` — reproduced independently at `06126017` by the supervisor and
 * again here — and nothing anywhere would have noticed a 63rd. That is not a
 * cosmetic complaint: this application takes the alternate screen roughly half
 * a second after launch (MEASURED elsewhere in this class family at 0.47s on a
 * real pty), and an unaccounted stderr write lands either on a frame it
 * corrupts or in a scrollback the user never sees. Which of those it is depends
 * on the call site, which is the argument for knowing what the call sites ARE.
 *
 * THIS DOES NOT ASSERT THE 62. Running the suite from inside the suite is not
 * available, the figure moves with the tests rather than with the application,
 * and it is a property of the HARNESS — the 62 are child-process launches whose
 * stderr the PHPUnit process inherits, and most of them are lines a test
 * deliberately provoked and then asserted on. What is pinned here is the
 * SOURCE: how many sites, in which files, on which channel. A 63rd runtime line
 * that comes from a new emitter reds here; a 63rd that comes from an existing
 * emitter being exercised once more by a new test does not, and should not.
 *
 * THE CHANNELS, AND THE LAST TWO ARE THE POINT OF THE EXERCISE. The census this
 * project already had —
 * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushAutoloadGuardTest}'s
 * doc-block, "the real census of raw `fwrite(STDERR, …)` call sites across
 * `src/` and `bin/` is ELEVEN" — is CORRECT, and this file asserts that it
 * stays correct ({@see testTheInheritedElevenSiteCensusStillAgreesWithTheScan()}).
 * It is also answering a narrower question than its readers have been taking
 * it to answer, and the gap is a matter of ALPHABET rather than of arithmetic:
 *
 *  1. `fwrite(STDERR, …)` — eleven sites. The channel that census describes.
 *  2. `STDERR` captured into a variable or property and written through later —
 *     ONE site, {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt}, whose
 *     `$err` defaults to `\STDERR` and which writes FOUR distinct
 *     `sugarcrush: ` shapes through it. A grep for `fwrite(STDERR` cannot see
 *     this file at all.
 *  3. `error_log(…)` — THIRTY-EIGHT sites across eleven files. MEASURED on this
 *     box, PHP 8.3.6, `ini_get('error_log')` is `''` and `php -r
 *     'error_log("x");' 2>file` puts `x` in the file: with no `error_log`
 *     destination configured, this IS stderr. Three of them appear in the
 *     baseline capture of a full suite run. It is by a wide margin the largest
 *     stderr channel in the application and it was not in the census at all.
 *  4. Literal message SHAPES that themselves carry the `sugarcrush: ` prefix.
 *     A LITERAL-BORNE PREFIX AND NOT "THE ROSTER A USER READS", which is what
 *     this line said when it was written, and the difference is the whole of
 *     the paragraph below.
 *  5. Call sites of the `warnPermissionConfig*` family — the funnel that
 *     applies the prefix at the EMITTER, via
 *     {@see \SugarCraft\Crush\Cli\Bootstrap::STDERR_LINE_FORMAT}, to a
 *     message that does not carry it.
 *
 * WHY CHANNEL 5 EXISTS, AND IT IS THIS FILE'S OWN ALPHABET TRAP SPRUNG ON
 * ITSELF. WHAT THE LINE ABOVE SAID: channel 4 is "the roster a user actually
 * reads". WHAT IS TRUE NOW, measured: channel 4 counts a literal that CONTAINS
 * `sugarcrush:`, and the largest producer of user-visible stderr in this
 * application does not write one. `Bootstrap`'s warnings are handed to
 * {@see \SugarCraft\Crush\Cli\Bootstrap::STDERR_LINE_FORMAT}, which adds the
 * prefix on the way out, so the message literals are invisible to a scan for
 * it — TWENTY-TWO call sites in `src/Cli/Bootstrap.php`, each producing a
 * distinct `sugarcrush: ` line, against a channel-4 credit of four for that
 * file. Off by roughly four times, in the blind direction.
 *
 * IT WAS NOT A THEORY. Round 45's review added one
 * `self::warnPermissionConfigOnce('a brand new user visible warning nobody
 * censused');` to `Bootstrap::reportProjectTierToolRemovals()` and ran all four
 * rosters: `OK (55 tests, 221 assertions)`, rc 0 — and PHPUnit's own output
 * carried the new line. A user-visible stderr write arrived unnoticed in the
 * same process as the census built to notice it, which is the defect this file
 * names in its first sentence.
 *
 * SO THE HEADLINE IS NOT "ELEVEN WAS WRONG", it is that a census reports what
 * its alphabet can express and this one's alphabet was written to match the
 * sites already known. Round 43's headline finding came from widening a fuzz's
 * pattern alphabet; round 45's first attempt at this file repeated the mistake
 * one channel over, and channel 5 is the repair. WHEN A CENSUS HERE REPORTS A
 * NUMBER, ASK WHAT ITS ALPHABET CANNOT EXPRESS BEFORE BELIEVING IT — the
 * shapes this one still cannot express are named by
 * {@see testNoStderrChannelOutsideTheScannedOnesHasAppeared()}, which is
 * the closest thing here to a claim of exhaustiveness.
 *
 * TRIAGE — WHY NOTHING IS SILENCED HERE, and silencing was the tempting
 * default. The baseline capture yields 36 distinct shapes under the
 * normalisation this round's report states — collapse `/tmp` paths and runs of
 * digits — and the round's brief reports 32 under a normalisation it does not
 * state. The two are NOT reconciled, and neither is quoted as a finding: a
 * shape count is an artefact of its normalisation, which is why the only figure
 * treated as a measurement here is the raw 62. Of those shapes, the large
 * majority are launch warnings a test PROVOKED and then ASSERTED on:
 * {@see BootstrapLaunchNoticeRoutingTest} reads the provider-fallback and
 * retention lines off stderr by design, {@see BootstrapToolAndPermissionSettingsTest}
 * reads the `disabledTools` line, {@see BootstrapHookFileTest} the untrusted
 * `hooks.yaml` line, {@see \SugarCraft\Crush\Tests\Cli\NonInteractiveTest} the
 * one-shot refusals. Those lines are the assertion. Muting them at the source
 * would delete the guard; muting them in the harness — teaching each child
 * spawn to keep stderr on its pipe instead of inheriting fd 2 — is a real and
 * separate improvement, in test files this lane does not own, and it is
 * recorded as a deferred finding rather than half-done here.
 *
 * E95 IS NOT REOPENED. The one MCP line is accepted permanently and round 44
 * rewrote its doc-block to argue from 62 rather than from 1. This file makes
 * the 62 visible; it does not relitigate the one.
 */
final class StderrEmitterCensusTest extends TestCase
{
    /**
     * Channel 1: a literal `fwrite(STDERR, …)`.
     *
     * @var array<string, int>
     */
    private const DIRECT_SITES = [
        'bin/sugarcrush' => 1,
        'src/Cli/Bootstrap.php' => 2,
        'src/Cli/NonInteractive.php' => 6,
        'src/Cli/Subcommands.php' => 2,
    ];

    /**
     * Channel 2: `STDERR` reached anywhere OTHER than as `fwrite()`'s first
     * argument — captured into a property, defaulted into a parameter, passed
     * on. The complement of channel 1 by construction, which is what makes the
     * pair exhaustive over the `STDERR` constant.
     *
     * @var array<string, int>
     */
    private const INDIRECT_SITES = [
        'src/Cli/HeadlessPermissionPrompt.php' => 1,
    ];

    /**
     * Channel 3: `error_log()`, which with no `error_log` ini destination is
     * stderr.
     *
     * THE COUNTS ARE PINNED PER FILE AND THAT IS DELIBERATE FRICTION. Adding a
     * debug `error_log()` to a TUI application is a change worth a moment's
     * thought — the alternate screen is up, and a line written to fd 2 lands on
     * a frame the renderer believes it owns. This test is that moment. Bumping
     * the number is a perfectly good response; not noticing is not.
     *
     * @var array<string, int>
     */
    private const ERROR_LOG_SITES = [
        'src/Agents/AgentWorkerPool.php' => 1,
        'src/Agents/ForeignAgentPresetRegistry.php' => 2,
        'src/Agents/WorktreeManager.php' => 4,
        'src/Chat.php' => 1,
        'src/Cli/Bootstrap.php' => 1,
        'src/Commands/CommandLoader.php' => 5,
        'src/Memory/ForeignMemoryImporter.php' => 1,
        'src/Providers/SglangProvider.php' => 3,
        'src/Providers/ToolCallParser/DsmlToolCallParser.php' => 11,
        'src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php' => 7,
        'src/Skills/SkillLoader.php' => 2,
    ];

    /**
     * Channel 4: string literals carrying the user-visible `sugarcrush: `
     * prefix.
     *
     * COUNTS AND NOT TEXTS. Pinning the message texts would red on a typo fix,
     * which teaches people to update the fixture without reading it — the exact
     * failure that let a count go stale for three rounds elsewhere in this
     * family. A count reds on a message being ADDED or REMOVED, which is the
     * event worth a decision.
     *
     * @var array<string, int>
     */
    private const MESSAGE_SHAPES = [
        'bin/sugarcrush' => 4,
        'src/Cli/ArgvParser.php' => 14,
        'src/Cli/Bootstrap.php' => 4,
        'src/Cli/HeadlessPermissionPrompt.php' => 4,
        'src/Cli/NonInteractive.php' => 6,
        'src/Cli/Subcommands.php' => 11,
    ];

    /**
     * Channel 5: call sites of the `warnPermissionConfig*` family.
     *
     * THE ONE CHANNEL WHOSE MESSAGES CHANNEL 4 CANNOT SEE, and the reason it
     * exists — see this class's doc-block. The prefix these lines carry is
     * applied by {@see \SugarCraft\Crush\Cli\Bootstrap::STDERR_LINE_FORMAT}
     * at the emitter, so the message literal at the call site contains no
     * `sugarcrush:` for channel 4 to count.
     *
     * COUNTED AS CALL SITES AND NOT AS MESSAGES, which is a real difference:
     * one call site inside a loop is one row here and any number of lines on
     * the terminal. The event this roster exists to notice is a new PLACE that
     * warns, because that is the thing that gets a routing decision — stderr
     * alone, the transcript seam, or nowhere. How many times it then fires is a
     * property of the run.
     *
     * @var array<string, int>
     */
    private const PREFIXED_WRITER_SITES = [
        'src/Cli/Bootstrap.php' => 22,
    ];

    /**
     * Only the words a count in this file could plausibly take. A word outside
     * this map is a sentence the guard cannot read, and that is a FAILURE
     * rather than a skip.
     *
     * @var array<string, int>
     */
    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
        'twenty-one' => 21, 'twenty-two' => 22, 'twenty-three' => 23,
        'thirty-seven' => 37, 'thirty-eight' => 38, 'thirty-nine' => 39,
        'forty-two' => 42, 'forty-three' => 43, 'forty-four' => 44,
    ];

    public function testTheDirectFwriteStderrRosterIsUnchanged(): void
    {
        self::assertSame(self::DIRECT_SITES, self::census('direct'), self::message('fwrite(STDERR, …)'));
    }

    public function testTheIndirectStderrHandleRosterIsUnchanged(): void
    {
        self::assertSame(self::INDIRECT_SITES, self::census('indirect'), self::message('captured STDERR handle'));
    }

    public function testTheErrorLogRosterIsUnchanged(): void
    {
        self::assertSame(self::ERROR_LOG_SITES, self::census('error_log'), self::message('error_log()'));
    }

    public function testTheSugarcrushMessageShapeRosterIsUnchanged(): void
    {
        self::assertSame(self::MESSAGE_SHAPES, self::census('shape'), self::message('`sugarcrush: ` message'));
    }

    public function testThePrefixedWriterRosterIsUnchanged(): void
    {
        self::assertSame(
            self::PREFIXED_WRITER_SITES,
            self::census('prefixed'),
            self::message('warnPermissionConfig*()'),
        );
    }

    /**
     * Channel 5's one file decomposes into its three entry points, and the
     * transcript one is the count `Bootstrap` already declares.
     *
     * WHY THIS IS NOT A SECOND HAND-MAINTAINED INTEGER, which is the objection
     * the sibling census raises against exactly that shape and is right to.
     * `PREFIXED_WRITER_SITES` says 22 and
     * {@see \SugarCraft\Crush\Cli\Bootstrap::TRANSCRIPT_SEAM_CALL_SITES}
     * says 16; this test is what makes the second a COMPONENT of the first
     * rather than an unrelated number that happens to be smaller. Add a seam
     * call and both move together; add a stderr-only warning and only the total
     * moves, which is the distinction a reader of either census wants and
     * neither could previously make.
     *
     * WHICH CENSUS OWNS WHAT: the seam count and its ten prose sentences belong
     * to
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapTranscriptSeamCallSiteCensusTest},
     * and nothing here duplicates them. This file owns the OTHER two entry
     * points, which that file does not count and no census did before channel 5.
     */
    public function testTheWarnFamilyDecomposesIntoItsThreeEntryPoints(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');

        $direct = self::scan('prefixed:warnPermissionConfig', $source);
        $once = self::scan('prefixed:warnPermissionConfigOnce', $source);
        $seam = self::scan('prefixed:warnPermissionConfigInTranscript', $source);

        self::assertSame(
            Bootstrap::TRANSCRIPT_SEAM_CALL_SITES,
            $seam,
            'the transcript-seam component of channel 5 no longer equals Bootstrap::'
                . 'TRANSCRIPT_SEAM_CALL_SITES. One of the two scans is wrong, or a seam call was added '
                . 'without the sibling census noticing; do not bump either number until you know which.',
        );
        self::assertSame(1, $direct, 'the number of warnings that go to stderr and bypass the once-guard moved');
        self::assertSame(5, $once, 'the number of stderr-only, once-per-process warnings moved');
        self::assertSame(
            self::PREFIXED_WRITER_SITES['src/Cli/Bootstrap.php'],
            $direct + $once + $seam,
            'the three entry points no longer add up to the channel-5 roster; the scanner is double-counting '
                . 'or the family gained a fourth entry point',
        );
    }

    /**
     * NO STDERR CHANNEL OUTSIDE THE SCANNED ONES HAS APPEARED.
     *
     * This is the only claim of exhaustiveness this file makes, and it is
     * deliberately a narrow one: it does not prove that no other way to reach
     * fd 2 exists in PHP, it proves that the three OTHER ways this application
     * could plausibly acquire one have not been used. `php://stderr` and
     * `php://output` opened as streams, and `error_log()`'s three-argument
     * destination form, which routes somewhere channel 3's reasoning about
     * `ini_get('error_log')` does not cover.
     *
     * AN ASSERTION OF ZERO IS NOT EVIDENCE, so the positive control is IN THIS
     * TEST and not in the shared provider: round 44 emptied a census in this
     * tree, blinded the scanner, and watched "nothing is stale" pass with
     * 18,228 assertions green. If the scanner below stops working, the
     * synthetic source reds here before the zero can be believed.
     */
    public function testNoStderrChannelOutsideTheScannedOnesHasAppeared(): void
    {
        self::assertSame(
            2,
            self::scan('other', '<?php fopen("php://stderr", "w"); error_log("x", 3, "/tmp/f");'),
            'the other-channel scanner no longer sees a channel it exists to find; the [] below is vacuous',
        );
        self::assertSame(
            0,
            self::scan('other', '<?php error_log("x"); fwrite(STDERR, "y");'),
            'the other-channel scanner reports channels 1 and 3 as unscanned ones',
        );

        self::assertSame(
            [],
            self::census('other'),
            'src/ or bin/ acquired a stderr channel no scanner in this file covers — a php:// stream handle, '
                . 'or error_log()\'s destination form. Give it a channel and a roster: an emitter nothing '
                . 'counts is exactly what this file exists to prevent.',
        );
    }

    /**
     * The prose census this file supersedes must keep agreeing with the scan.
     *
     * READ-ONLY, and pinned rather than rewritten: the sentence lives in
     * `tests/Integration/BinSugarcrushAutoloadGuardTest.php`, which is not this
     * lane's file. It is right today. The reason to anchor it anyway is that it
     * is the sentence a reader finds FIRST when they ask how many stderr writes
     * this application has, and it answers a narrower question than they asked
     * — so the day channel 1 moves, the reader's first answer must move with it.
     */
    public function testTheInheritedElevenSiteCensusStillAgreesWithTheScan(): void
    {
        $path = \dirname(__DIR__, 2) . '/tests/Integration/BinSugarcrushAutoloadGuardTest.php';
        self::assertFileExists($path, 'the file carrying the prose census has moved');

        $flat = self::flattened((string) file_get_contents($path));

        $matched = preg_match_all(
            '/call sites across `src\/` and `bin\/` is ([A-Z]+)/',
            $flat,
            $all,
            PREG_SET_ORDER,
        );
        self::assertSame(
            1,
            $matched,
            "the prose census sentence matches {$matched} times, not once; re-point this anchor rather than "
                . 'dropping it — an unanchored prose count is how three rounds of staleness happened',
        );

        $scanned = array_sum(self::census('direct'));
        $word = strtolower($all[0][1]);
        self::assertArrayHasKey(
            $word,
            self::NUMBER_WORDS,
            "the prose census says \"{$all[0][1]}\", which this guard cannot read",
        );

        // THE SCAN AND NOT THE ROSTER, which is what this compared against
        // when it was written while its name and its failure message both said
        // "the scan". Transitively the same thing —
        // testTheDirectFwriteStderrRosterIsUnchanged() pins roster to scan — but
        // a reader debugging this failure would have gone looking in the wrong
        // one of the two.
        self::assertSame(
            $scanned,
            self::NUMBER_WORDS[$word],
            'BinSugarcrushAutoloadGuardTest still says the raw fwrite(STDERR, …) census is "' . $word
                . '" while the token scan counts ' . $scanned,
        );
    }

    /**
     * EVERY SCANNER, RUN AGAINST A SOURCE WHOSE ANSWER IS KNOWN, IN THE SAME
     * SUITE THAT TRUSTS IT ON SOURCES WHOSE ANSWER IS NOT.
     *
     * WHAT THIS PARAGRAPH FIRST CLAIMED, and it was wrong in the direction that
     * matters: "the four assertions above are `assertSame(<map>, <scan>)`, which
     * looks like evidence and is not — a blinded scanner returns `[]` for every
     * channel and the only thing that notices is a fixture." MEASURED, by
     * blinding {@see scan()} (drop every token before classification) and
     * running `--filter
     * StderrEmitterCensusTest::testTheDirectFwriteStderrRosterIsUnchanged`
     * ALONE: `Tests: 1, Assertions: 1, Failures: 1`. The roster assertions are
     * PRESENCE assertions — they compare against a non-empty map — so a dead
     * instrument reds them on its own. That is a real difference from an
     * `assertSame([], …)` census, which is the shape round 44's dead-instrument
     * failure actually had, and this file does not have it.
     *
     * WHY THE FIXTURES STILL EARN THEIR PLACE, narrowed to what they buy. FIRST,
     * they pin the CLASSIFICATION independently of the tree: `direct` and
     * `indirect` are defined as complements over the `STDERR` constant, and
     * `\STDERR` inside a comment, inside a doc-block and inside a string must
     * all count as none of the above. A roster that reds tells you a number
     * moved; only these rows tell you whether the SCAN moved or the tree did,
     * and that is the question someone about to bump a roster is asking.
     * SECOND, they are the only guard on a channel whose expected roster is
     * empty — there is none today, and the moment one is added (a `php://stderr`
     * handle, say, expected absent) the `[]` shape and its vacuity arrive with
     * it.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function scannerCases(): iterable
    {
        yield 'a direct write' => ['direct', '<?php fwrite(STDERR, "x");', 1];
        yield 'a namespaced direct write' => ['direct', '<?php \\fwrite(\\STDERR, "x");', 1];
        yield 'a direct write is not indirect' => ['indirect', '<?php fwrite(STDERR, "x");', 0];
        yield 'a captured handle' => ['indirect', '<?php $this->err = $err ?? \\STDERR;', 1];
        yield 'a defaulted parameter' => ['indirect', '<?php function f($h = STDERR) {}', 1];
        yield 'STDERR in a comment' => ['indirect', "<?php // fwrite(STDERR, 'x');\n\$x = 1;", 0];
        yield 'STDERR in a doc-block' => ['indirect', "<?php /** writes to STDERR */\n\$x = 1;", 0];
        yield 'the word inside a string' => ['indirect', '<?php $x = "STDERR";', 0];
        yield 'an error_log call' => ['error_log', '<?php error_log("x");', 1];
        yield 'a namespaced error_log call' => ['error_log', '<?php \\error_log("x");', 1];
        yield 'error_log named in prose' => ['error_log', "<?php // error_log() is stderr\n\$x = 1;", 0];
        yield 'a prefixed literal' => ['shape', "<?php \$x = 'sugarcrush: nope';", 1];
        yield 'a prefixed interpolation' => ['shape', '<?php $x = "sugarcrush: {$y} nope";', 1];
        yield 'the prefix only in a comment' => ['shape', "<?php // sugarcrush: nope\n\$x = 1;", 0];
        yield 'a warn call' => ['prefixed', '<?php self::warnPermissionConfigOnce("x");', 1];
        yield 'all three entry points' => [
            'prefixed',
            '<?php self::warnPermissionConfig("a"); self::warnPermissionConfigOnce("b"); '
                . 'self::warnPermissionConfigInTranscript("c");',
            3,
        ];
        yield 'one entry point out of three' => [
            'prefixed:warnPermissionConfigOnce',
            '<?php self::warnPermissionConfig("a"); self::warnPermissionConfigOnce("b"); '
                . 'self::warnPermissionConfigInTranscript("c");',
            1,
        ];
        yield 'the declaration is not a call' => [
            'prefixed',
            '<?php private static function warnPermissionConfig(string $m): void {}',
            0,
        ];
        yield 'a {@see} reference is not a call' => [
            'prefixed',
            "<?php /** {@see warnPermissionConfigOnce()} */\n\$x = 1;",
            0,
        ];
        yield 'a first-class callable is not a call' => [
            'prefixed',
            '<?php $f = self::warnPermissionConfigOnce(...);',
            0,
        ];
        yield 'an unscoped same-named function is not one of ours' => [
            'prefixed',
            '<?php warnPermissionConfigOnce("x");',
            0,
        ];
        yield 'a php:// stderr stream' => ['other', '<?php fopen("php://stderr", "w");', 1];
        yield 'a php:// output stream' => ['other', '<?php file_put_contents("php://output", $x);', 1];
        yield 'error_log with a destination' => ['other', '<?php error_log("x", 3, "/tmp/f");', 1];
        yield 'plain error_log is not an other channel' => ['other', '<?php error_log("x");', 0];
        yield 'error_log with a type but no destination' => ['other', '<?php error_log("x", 0);', 0];
        yield 'a source with nothing at all' => ['direct', '<?php echo 1;', 0];
        yield 'and nothing on the other channels' => ['error_log', '<?php echo 1;', 0];
        yield 'nor on channel five' => ['prefixed', '<?php echo 1;', 0];
        yield 'nor on the unscanned-channel scan' => ['other', '<?php echo 1;', 0];
    }

    /** @dataProvider scannerCases */
    public function testEachScannerAnswersCorrectlyOnASourceWhoseAnswerIsKnown(
        string $channel,
        string $source,
        int $expected,
    ): void {
        self::assertSame($expected, self::scan($channel, $source));
    }

    /**
     * A channel name this file cannot answer for is a FAILURE, not a zero.
     *
     * `scan('prefixed:noSuchThing', …)` used to be indistinguishable from a
     * genuine zero, which is a hole shaped exactly like the next typo: a
     * decomposition test asking for an entry point that had been renamed would
     * have reported 0 sites and passed.
     */
    public function testTheScannerRedsOnAnEntryPointItCannotAnswerFor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('noSuchEntryPoint');

        self::scan('prefixed:noSuchEntryPoint', '<?php echo 1;');
    }

    /**
     * A file the roster names but the tree does not have is a FAILURE.
     *
     * WHAT THIS SAID, and it contradicted itself inside one sentence: "deleting
     * `src/Cli/Subcommands.php` would take its two sites out of the scan AND
     * out of nothing else — the roster would simply stop mentioning a file …
     * This asserts the direction the maps cannot."
     * WHAT IS TRUE NOW: the rosters are `const`s. Deleting the file drops the
     * key from {@see census()} and leaves it in the roster, so
     * `assertSame(<roster>, <census>)` reds on its own — the clause immediately
     * after the dash says exactly that, and the claim before the dash is the
     * false one. This test is REDUNDANT with the four roster assertions, in
     * both directions.
     * WHY IT STILL EARNS ITS PLACE: the message. A roster assertion failing on
     * a deleted file prints an array diff and leaves the reader to notice that
     * one key is a path that no longer exists; this prints the path and says
     * so. It is kept for the diagnosis, not for the coverage, and calling it
     * coverage is what produced the sentence above.
     */
    public function testEveryFileTheRostersNameExists(): void
    {
        $named = array_unique(array_merge(
            array_keys(self::DIRECT_SITES),
            array_keys(self::INDIRECT_SITES),
            array_keys(self::ERROR_LOG_SITES),
            array_keys(self::MESSAGE_SHAPES),
            array_keys(self::PREFIXED_WRITER_SITES),
        ));
        self::assertNotSame([], $named, 'the rosters are empty, so every assertion here is vacuous');

        foreach ($named as $file) {
            self::assertFileExists(\dirname(__DIR__, 2) . '/' . $file, "{$file} is named by a roster but is gone");
        }
    }

    /**
     * Every cardinality this file states in prose, matched to the roster that
     * generates it.
     *
     * WHY THIS EXISTS AND WHY IT IS EMBARRASSING. This file's whole subject is
     * that a count nobody derives goes stale, and it shipped six of its own —
     * one per channel, plus the two in the channel-5 paragraph — hand-typed
     * from numbers the tests below derive, in a round whose sibling census had
     * just built the anchoring machinery for exactly this. All six were correct
     * the day they were written, which is the only thing that is ever true of
     * them.
     *
     * MATCHED AGAINST {@see flattened()} AND NOT THE RAW BYTES, for the reason
     * the sibling census gives: a doc-block wraps at 80 columns with ` * ` on
     * every continuation, so a sentence is never those bytes in a row. Two of
     * the six cross a wrap. The flattener's own known-positive control is the
     * first assertion in the test, because a flattener returning `''` would
     * make every anchor fail open into a zero match — which this treats as a
     * failure, not a skip.
     *
     * @return list<array{anchor: string, expected: int, what: string}>
     */
    private static function selfCountAnchors(): array
    {
        return [
            [
                'anchor' => '/`fwrite\(STDERR, …\)` — ([a-z]+) sites/',
                'expected' => array_sum(self::DIRECT_SITES),
                'what' => 'channel 1, the fwrite(STDERR, …) total',
            ],
            [
                'anchor' => '/written through later — ([A-Z]+) site,/',
                'expected' => array_sum(self::INDIRECT_SITES),
                'what' => 'channel 2, the captured-handle total',
            ],
            [
                'anchor' => '/and which writes ([A-Z]+) distinct/',
                'expected' => self::MESSAGE_SHAPES['src/Cli/HeadlessPermissionPrompt.php'],
                'what' => "channel 4's credit for HeadlessPermissionPrompt",
            ],
            [
                'anchor' => '/`error_log\(…\)` — ([A-Z-]+) sites across/',
                'expected' => array_sum(self::ERROR_LOG_SITES),
                'what' => 'channel 3, the error_log() total',
            ],
            [
                'anchor' => '/sites across ([a-z]+) files\. MEASURED/',
                'expected' => \count(self::ERROR_LOG_SITES),
                'what' => 'channel 3, the number of files it spans',
            ],
            [
                'anchor' => '/it — ([A-Z-]+) call sites in/',
                'expected' => self::PREFIXED_WRITER_SITES['src/Cli/Bootstrap.php'],
                'what' => "channel 5's count for Bootstrap",
            ],
            [
                'anchor' => '/against a channel-4 credit of ([a-z]+) for that/',
                'expected' => self::MESSAGE_SHAPES['src/Cli/Bootstrap.php'],
                'what' => "channel 4's credit for Bootstrap, the blind one",
            ],
        ];
    }

    public function testEveryCardinalityThisFileStatesInProseHasItsGenerator(): void
    {
        // KNOWN-POSITIVE CONTROL FIRST. Assembled rather than written whole:
        // this test scans its own file, so a fixture spelling an anchor phrase
        // contiguously here becomes a second match for it and reds the
        // uniqueness assertion on the scaffolding. The sibling census measured
        // that happening; so did round 45's first attempt at its own anchors.
        $sentence = 'written through later — ' . 'NINE' . ' site,';
        $fixture = "    /**\n     * written through later —\n     * " . 'NINE' . " site, it says.\n     */\n";
        self::assertSame(
            ' /** ' . $sentence . ' it says. */ ',
            self::flattened($fixture),
            'flattened() no longer joins a wrapped doc-block sentence; every anchor below would fail open',
        );

        $own = self::flattened((string) file_get_contents(__FILE__));

        foreach (self::selfCountAnchors() as $site) {
            $matched = preg_match_all($site['anchor'], $own, $all, PREG_SET_ORDER);
            self::assertSame(
                1,
                $matched,
                "{$site['anchor']} matches this file {$matched} times, not once. Zero means the sentence "
                    . "stating {$site['what']} was rewritten past its anchor — re-point it, do not drop the "
                    . 'row. More than one means two sentences now state it through one anchor, and only one '
                    . 'of them is being checked.',
            );

            $word = strtolower($all[0][1]);
            self::assertArrayHasKey(
                $word,
                self::NUMBER_WORDS,
                "{$site['anchor']} captured \"{$all[0][1]}\", which is not a number word this guard can "
                    . 'read. Widen self::NUMBER_WORDS or re-point the anchor; do not leave it unparsed.',
            );
            self::assertSame(
                $site['expected'],
                self::NUMBER_WORDS[$word],
                "the prose for {$site['what']} says \"{$word}\" but its roster generates {$site['expected']}",
            );
        }
    }

    // ── the scanners ─────────────────────────────────────────────────────

    /**
     * `$source` with doc-block and line-comment continuation markers removed
     * and every run of whitespace collapsed to one space.
     *
     * DELIBERATELY A SECOND COPY of
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapTranscriptSeamCallSiteCensusTest}'s
     * private method of the same name, and the duplication is recorded rather
     * than resolved: the shared home for it is a test-support trait, and adding
     * one is outside the file set round 45's lane may touch. Consolidating the
     * two is a deferred finding. What is NOT duplicated is the risk — this copy
     * has its own known-positive control, in
     * {@see testEveryCardinalityThisFileStatesInProseHasItsGenerator()}, and
     * before this method existed the same expression sat inline in
     * {@see testTheInheritedElevenSiteCensusStillAgreesWithTheScan()} with no
     * control at all.
     */
    private static function flattened(string $source): string
    {
        // `\*(?!/)` — the CONTINUATION marker, never the terminator. Letting
        // `*/` be stripped too would run the end of one doc-block into the
        // start of the next, and an anchor could then match a "sentence" that
        // spans two of them and exists in neither.
        $joined = (string) preg_replace('#\n\s*(?:\*(?!/)|//)[ \t]?#', ' ', $source);

        return (string) preg_replace('/\s+/', ' ', $joined);
    }

    private static function message(string $channel): string
    {
        return "The roster of {$channel} sites in src/ and bin/ moved. That is not automatically wrong — but "
            . 'a new stderr write in this application needs a decision: does it belong on '
            . 'Bootstrap::warnPermissionConfigInTranscript()\'s transcript seam (it names something the '
            . 'session can no longer DO), on stderr alone (the user\'s config is malformed but the session '
            . 'is intact), or nowhere (it is debug output). Make the decision, then update the roster.';
    }

    /** @return array<string, int> file => count, files with zero omitted */
    private static function census(string $channel): array
    {
        $out = [];
        foreach (self::sources() as $relative => $absolute) {
            $n = self::scan($channel, (string) file_get_contents($absolute));
            if ($n > 0) {
                $out[$relative] = $n;
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, string> relative path => absolute path */
    private static function sources(): array
    {
        $root = \dirname(__DIR__, 2);
        $out = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[ltrim(str_replace($root, '', $file->getPathname()), '/')] = $file->getPathname();
            }
        }
        $out['bin/sugarcrush'] = $root . '/bin/sugarcrush';

        return $out;
    }

    /**
     * How many sites of `$channel` are in `$source`.
     *
     * TOKENS AND NOT TEXT, for the reason the sibling seam census gives at
     * length: `grep` counts the doc-blocks, and this application's doc-blocks
     * discuss `fwrite(STDERR, …)` and `error_log()` more often than they call
     * them — MEASURED on `src/Cli/Bootstrap.php`, PHP 8.3.6, `grep -c
     * 'fwrite(STDERR'` gives 4 where the token scan gives 2, and `grep -c
     * 'error_log('` gives 10 where the scan gives 1.
     */
    private static function scan(string $channel, string $source): int
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $warnFamily = [
            'warnPermissionConfig' => true,
            'warnPermissionConfigOnce' => true,
            'warnPermissionConfigInTranscript' => true,
        ];
        $onlyEntryPoint = str_starts_with($channel, 'prefixed:')
            ? substr($channel, \strlen('prefixed:'))
            : null;
        if ($onlyEntryPoint !== null && !isset($warnFamily[$onlyEntryPoint])) {
            throw new \RuntimeException("no warnPermissionConfig entry point named {$onlyEntryPoint}()");
        }

        $count = 0;
        foreach ($significant as $i => $token) {
            $name = self::callableName($token);

            if ($channel === 'prefixed' || $onlyEntryPoint !== null) {
                if ($name === null || !isset($warnFamily[$name])) {
                    continue;
                }
                if ($onlyEntryPoint !== null && $name !== $onlyEntryPoint) {
                    continue;
                }
                if (self::isSelfScopedCall($significant, $i)) {
                    $count++;
                }

                continue;
            }

            if ($channel === 'other') {
                if (
                    \is_array($token)
                    && \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match('#php://(?:stderr|output)#i', $token[1]) === 1
                ) {
                    $count++;
                }

                // error_log()'s THREE-argument form: the destination argument
                // takes it somewhere channel 3's `ini_get('error_log')` is ''
                // reasoning does not describe, so it is a separate channel and
                // not a third error_log site.
                if ($name === 'error_log' && ($significant[$i + 1] ?? null) === '(') {
                    if (self::argumentCount($significant, $i + 1) >= 3) {
                        $count++;
                    }
                }

                continue;
            }

            if ($channel === 'shape') {
                if (
                    \is_array($token)
                    && \in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && str_contains($token[1], 'sugarcrush:')
                ) {
                    $count++;
                }

                continue;
            }

            if ($channel === 'error_log') {
                if ($name === 'error_log' && ($significant[$i + 1] ?? null) === '(') {
                    $count++;
                }

                continue;
            }

            if ($name !== 'STDERR') {
                continue;
            }

            $isFirstArgumentOfFwrite = ($significant[$i - 1] ?? null) === '('
                && self::callableName($significant[$i - 2] ?? null) === 'fwrite';

            if ($channel === 'direct' ? $isFirstArgumentOfFwrite : !$isFirstArgumentOfFwrite) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether the token at `$i` is a scoped call — `self::name(`, `$x->name(`
     * — rather than a declaration or a first-class callable.
     *
     * ALL THREE CLAUSES ARE LOAD-BEARING, and the sibling seam census measured
     * why: requiring the scope token is what excludes `function name(`, and
     * excluding `(...)` is what stops a first-class callable being counted as a
     * call — PHP 8.3.6 lexes `self::f(...)` as T_STRING, `(`, T_ELLIPSIS, `)`,
     * so the paren requirement alone does not exclude it. Round 44's review
     * planted one in that file and got a fabricated extra site out of the scan.
     *
     * @param list<array{0: int, 1: string}|string> $significant
     */
    private static function isSelfScopedCall(array $significant, int $i): bool
    {
        $previous = $significant[$i - 1] ?? null;
        if (!\is_array($previous) || !\in_array($previous[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true)) {
            return false;
        }
        if (($significant[$i + 1] ?? null) !== '(') {
            return false;
        }

        $after = $significant[$i + 2] ?? null;

        return !(\is_array($after) && $after[0] === T_ELLIPSIS);
    }

    /**
     * How many top-level arguments the call whose `(` sits at `$open` passes.
     *
     * Depth-tracked, so a comma inside a nested call or array is not an
     * argument of this one. A call with no arguments answers 0.
     *
     * @param list<array{0: int, 1: string}|string> $significant
     */
    private static function argumentCount(array $significant, int $open): int
    {
        $depth = 0;
        $commas = 0;
        $sawToken = false;

        for ($i = $open; $i < \count($significant); $i++) {
            $token = $significant[$i];
            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }
            if (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $sawToken ? $commas + 1 : 0;
                }

                continue;
            }
            if ($depth === 1) {
                $sawToken = true;
                if ($token === ',') {
                    $commas++;
                }
            }
        }

        throw new \RuntimeException('a call opened at this token never closes; the scan cannot answer for it');
    }

    /**
     * The name a `T_STRING`/`T_NAME_FULLY_QUALIFIED` token denotes, leading
     * backslash stripped — `\fwrite` and `fwrite` are the same call, and
     * `\STDERR` and `STDERR` the same constant.
     */
    private static function callableName(array|string|null $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }
        if (!\in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        return ltrim($token[1], '\\');
    }
}
