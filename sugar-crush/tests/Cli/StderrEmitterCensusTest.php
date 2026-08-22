<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;

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
 * FOUR CHANNELS, AND THE FOURTH IS THE POINT OF THE EXERCISE. The census this
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
 *  4. Literal message SHAPES carrying the `sugarcrush: ` prefix — forty-three
 *     chunks across six files, which is the roster a user actually reads.
 *
 * SO THE HEADLINE IS NOT "ELEVEN WAS WRONG", it is that a census reports what
 * its alphabet can express and this one's alphabet was written to match the
 * sites already known. Round 43's headline finding came from widening a fuzz's
 * pattern alphabet; this is the same lesson in a different instrument.
 *
 * TRIAGE — WHY NOTHING IS SILENCED HERE, and silencing was the tempting
 * default. Of the 36 distinct runtime shapes in the baseline capture (36 under
 * the normalisation in this class's report — collapse `/tmp` paths and digits;
 * a coarser normalisation gives 32, and the shape count is an artefact of the
 * normalisation, which is why only the raw 62 is quoted as a figure), the large
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

        $flat = (string) preg_replace(
            '/\s+/',
            ' ',
            (string) preg_replace('#\n\s*(?:\*(?!/)|//)[ \t]?#', ' ', (string) file_get_contents($path)),
        );

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

        $words = ['TEN' => 10, 'ELEVEN' => 11, 'TWELVE' => 12, 'THIRTEEN' => 13, 'FOURTEEN' => 14];
        $word = strtoupper($all[0][1]);
        self::assertArrayHasKey($word, $words, "the prose census says \"{$word}\", which this guard cannot read");
        self::assertSame(
            array_sum(self::DIRECT_SITES),
            $words[$word],
            'BinSugarcrushAutoloadGuardTest still says the raw fwrite(STDERR, …) census is "' . $word
                . '" while the token scan counts ' . array_sum(self::DIRECT_SITES),
        );
    }

    /**
     * EVERY SCANNER, RUN AGAINST A SOURCE WHOSE ANSWER IS KNOWN, IN THE SAME
     * SUITE THAT TRUSTS IT ON SOURCES WHOSE ANSWER IS NOT.
     *
     * The four assertions above are `assertSame(<map>, <scan>)`, which looks
     * like evidence and is not: a scanner that had been blinded would return
     * `[]` for every channel and the only thing that notices is a fixture. Round
     * 44 shipped exactly that failure elsewhere in this tree — a census
     * asserting "nothing is stale" passed with 18,228 assertions green while the
     * instrument was dead.
     *
     * The negative rows matter as much as the positive ones: `\STDERR` inside a
     * comment, and the word inside a string, must NOT be counted, or the
     * rosters above would be pinning doc-blocks.
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
        yield 'a source with nothing at all' => ['direct', '<?php echo 1;', 0];
        yield 'and nothing on the other channels' => ['error_log', '<?php echo 1;', 0];
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
     * A file the roster names but the tree does not have is a FAILURE.
     *
     * Without this, deleting `src/Cli/Subcommands.php` would take its two sites
     * out of the scan AND out of nothing else — the roster would simply stop
     * mentioning a file, and `assertSame` compares what the scan produced with
     * what the roster claims, so a file present in the roster and absent from
     * the tree is caught, while the reverse is what the maps are for. This
     * asserts the direction the maps cannot: that every named file is real.
     */
    public function testEveryFileTheRostersNameExists(): void
    {
        $named = array_unique(array_merge(
            array_keys(self::DIRECT_SITES),
            array_keys(self::INDIRECT_SITES),
            array_keys(self::ERROR_LOG_SITES),
            array_keys(self::MESSAGE_SHAPES),
        ));
        self::assertNotSame([], $named, 'the rosters are empty, so every assertion here is vacuous');

        foreach ($named as $file) {
            self::assertFileExists(\dirname(__DIR__, 2) . '/' . $file, "{$file} is named by a roster but is gone");
        }
    }

    // ── the scanners ─────────────────────────────────────────────────────

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

        $count = 0;
        foreach ($significant as $i => $token) {
            $name = self::callableName($token);

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
