<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\CommandLoader;

/**
 * `CommandLoader` reports its refusals through the collectors, and puts them on
 * stderr only when asked.
 *
 * WHY THE GATE (E154). Three of this loader's five refusal sites are paired
 * with a collector that {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} drains
 * onto the transcript seam — which already writes a `sugarcrush: `-prefixed
 * line to stderr AND seeds a transcript row — so the raw `error_log()` copy was
 * the same sentence on the same channel twice. The other two are per-FILE and
 * had no collector at all, which is why one was added rather than the line
 * simply removed: gating a diagnostic that has nowhere else to go is a deletion
 * wearing a flag.
 *
 * THE COLLECTOR ASSERTIONS ARE THE POINT, and the env one is not optional
 * either. WHAT THIS SAID: "a gate can be tested with two `putenv()`s and prove
 * only that a conditional works; what matters is that the refusal still EXISTS
 * after the walk regardless of what any channel did with it."
 * WHAT IS TRUE NOW: the first clause talked this file out of the one test that
 * covers the switch users are told to set, and the omission was load-bearing.
 * Every reporting test here forced the constructor override
 * (`reportRefusals: true`), which never reaches `getenv()`; the off-by-default
 * ones assert silence, which is also what a loader that ignores the env
 * produces. MEASURED: with `debugRefusalsRequested()` mutated to
 * `return false;` the WHOLE suite stayed green — the documented switch was
 * inert and nothing noticed. {@see testTheDebugEnvVarPutsTheRefusalsBackOnStderr()}
 * is what closes that, and {@see testZeroReadsAsOff()} only distinguishes `0`
 * from "on" because that test proves "on" is reachable at all.
 * WHY THE SECOND CLAUSE STILL EARNS ITS PLACE: it is still the reason the
 * collector assertions come first and the reason the per-file skip got a
 * collector before it got a gate. The refusal surviving the walk is the
 * invariant; which channel carries it is a routing choice.
 */
final class CommandLoaderRefusalReportingTest extends TestCase
{
    private string $logFile = '';

    private string|false $previousErrorLog = false;

    private string $root = '';

    protected function setUp(): void
    {
        // Names no sibling lane's suite owns — three suites share /tmp.
        $suffix = bin2hex(random_bytes(8));
        $this->logFile = sys_get_temp_dir() . '/sc_a46_cmdloader_' . $suffix . '.log';
        $this->root = sys_get_temp_dir() . '/sc_a46_cmdroot_' . $suffix;

        mkdir($this->root . '/.sugar-crush/commands', 0o700, true);

        // Redirected rather than captured: with no destination set,
        // `error_log()` goes to the SAPI logger, which under the CLI is fd 2 —
        // past PHPUnit's capture and into the suite's own output.
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);

        // Exact paths only, never a glob.
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testAnUnparseableCommandFileIsCollectedAndSilent(): void
    {
        $this->writeUnparseableCommand();

        $loader = new CommandLoader();
        $loader->loadFromDirectory($this->root . '/.sugar-crush/commands');

        self::assertNotSame([], $loader->skippedFiles(), 'the skip was not recorded anywhere at all');
        self::assertStringContainsString(
            'Failed to load command from',
            implode("\n", $loader->skippedFiles()),
        );
        self::assertSame(
            '',
            $this->captured(),
            'the per-file skip reached stderr with ' . CommandLoader::DEBUG_REFUSALS_ENV . ' unset',
        );
    }

    /**
     * The same walk, reported. This is the known-positive for the assertion of
     * an absence above: without it, a loader that had stopped detecting the bad
     * file at all would pass that test.
     */
    public function testTheSameSkipReachesStderrWhenReportingIsForcedOn(): void
    {
        $this->writeUnparseableCommand();

        $loader = new CommandLoader(reportRefusals: true);
        $loader->loadFromDirectory($this->root . '/.sugar-crush/commands');

        self::assertNotSame([], $loader->skippedFiles());
        self::assertStringContainsString('Failed to load command from', $this->captured());
    }

    public function testADirectoryRefusalIsCollectedAndSilent(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0o700, true);

        $loader = new CommandLoader();
        // Anchored to a tree the directory does not live under, which is the
        // containment refusal `loadFromDirectory()` raises first.
        $loader->loadFromDirectory($outside, $this->root . '/.sugar-crush/commands');

        self::assertArrayHasKey($outside, $loader->refusedDirectories());
        self::assertSame(
            '',
            $this->captured(),
            'a directory refusal that Bootstrap already routes onto the transcript seam was ALSO put on '
                . 'stderr raw — that duplication is what ' . CommandLoader::DEBUG_REFUSALS_ENV . ' gates',
        );
    }

    public function testTheDirectoryRefusalComesBackWhenReportingIsForcedOn(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0o700, true);

        $loader = new CommandLoader(reportRefusals: true);
        $loader->loadFromDirectory($outside, $this->root . '/.sugar-crush/commands');

        self::assertArrayHasKey($outside, $loader->refusedDirectories());
        self::assertStringContainsString('Skipping commands directory', $this->captured());
    }

    /**
     * THE ENV SWITCH ITSELF, exercised through the null default.
     *
     * `new CommandLoader()` and not `new CommandLoader(reportRefusals: true)`:
     * the override short-circuits `debugRefusalsRequested()` entirely, so every
     * other reporting test in this file passes whether `getenv()` is consulted
     * or not. This is the only test in the suite that reds when the env read is
     * removed — verified by mutating that method to `return false;`, which
     * leaves the rest of the suite untouched.
     *
     * The exemplar is
     * {@see \SugarCraft\Crush\Tests\Skills\SkillLoaderTest::testTheDebugEnvVarPutsTheSkipsBackOnTheLog()},
     * which this loader's gate was copied from and whose test this file had not
     * copied with it.
     */
    public function testTheDebugEnvVarPutsTheRefusalsBackOnStderr(): void
    {
        $previous = getenv(CommandLoader::DEBUG_REFUSALS_ENV);
        putenv(CommandLoader::DEBUG_REFUSALS_ENV . '=1');

        try {
            $this->writeUnparseableCommand();
            $outside = $this->root . '/outside';
            mkdir($outside, 0o700, true);

            $loader = new CommandLoader();
            $loader->loadFromDirectory($this->root . '/.sugar-crush/commands');
            $loader->loadFromDirectory($outside, $this->root . '/.sugar-crush/commands');

            // Both arms of the gate: the per-FILE skip, whose only other reader
            // is the dormant collector, and the DIRECTORY refusal, which the
            // transcript seam also carries.
            $captured = $this->captured();
            self::assertStringContainsString('Failed to load command from', $captured);
            self::assertStringContainsString('Skipping commands directory', $captured);

            // ...and the collectors still hold them, so this is the reporting
            // half being switched on rather than the walk behaving differently.
            self::assertNotSame([], $loader->skippedFiles());
            self::assertArrayHasKey($outside, $loader->refusedDirectories());
        } finally {
            if ($previous === false) {
                putenv(CommandLoader::DEBUG_REFUSALS_ENV);
            } else {
                putenv(CommandLoader::DEBUG_REFUSALS_ENV . '=' . $previous);
            }
        }
    }

    /**
     * `0` is off, matching every other `SUGARCRUSH_*` switch.
     *
     * Pinned because the natural spelling of the guard — `getenv(…) !== false`
     * — reads `=0` as ON, handing a user who set it to zero the opposite of
     * what they asked for.
     */
    public function testZeroReadsAsOff(): void
    {
        $previous = getenv(CommandLoader::DEBUG_REFUSALS_ENV);
        putenv(CommandLoader::DEBUG_REFUSALS_ENV . '=0');

        try {
            $this->writeUnparseableCommand();
            $loader = new CommandLoader();
            $loader->loadFromDirectory($this->root . '/.sugar-crush/commands');

            self::assertNotSame([], $loader->skippedFiles());
            self::assertSame('', $this->captured());
        } finally {
            if ($previous === false) {
                putenv(CommandLoader::DEBUG_REFUSALS_ENV);
            } else {
                putenv(CommandLoader::DEBUG_REFUSALS_ENV . '=' . $previous);
            }
        }
    }

    /**
     * THE DORMANCY OF {@see CommandLoader::skippedFiles()}, PINNED.
     *
     * Nothing in `src/` drains it, and that is a decision with a reason —
     * see the property's doc-block: the launch report prints one row per entry
     * and a directory of twenty unparseable `*.md` files would evict the
     * capability warnings the transcript seam is bounded for. Wiring it wants a
     * SUMMARY row first, of the shape `SkillLoader` already built for skills.
     *
     * THIS TEST IS NOT A VETO. Draining it is the intended next step; the test
     * exists so the accessor cannot be read as an oversight and quietly deleted
     * (rule 6), and so the day someone does wire it, they are pointed at the
     * paragraph explaining what shape the drain has to have. Deleting this test
     * as part of wiring it is the correct move.
     */
    public function testNothingDrainsTheSkippedFilesCollectorYet(): void
    {
        $root = \dirname(__DIR__, 2);
        $callers = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (self::callsSkippedFiles((string) file_get_contents($file->getPathname()))) {
                $callers[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        // KNOWN-POSITIVE ON THE SAME SCANNER, in the same test: an assertion of
        // `[]` proves nothing unless something here proves the instrument still
        // matches. Round 44 shipped an empty census whose scanner was dead and
        // it was entirely green.
        //
        // ONE FIXTURE PER DISPATCH OPERATOR, and not just `->`. A fixture that
        // exercises only the shape the scanner already handles cannot reveal
        // that its alphabet is too narrow — which is what happened here: the
        // scanner accepted `->` and `::` only, while `?->` appears throughout
        // `src/` (28 files at the time this was widened, though the number is
        // deliberately not asserted — see rule 18 in the round briefs). A drain
        // written `$loader?->skippedFiles()` would have left this census green.
        self::assertTrue(
            self::callsSkippedFiles('<?php $loader->skippedFiles();'),
            'the scanner below no longer recognises a call to skippedFiles(); the [] is vacuous',
        );
        self::assertTrue(
            self::callsSkippedFiles('<?php $loader?->skippedFiles();'),
            'the scanner no longer recognises a NULLSAFE call to skippedFiles(); the [] is vacuous for '
                . 'every `?->` in src/',
        );
        self::assertTrue(
            self::callsSkippedFiles('<?php CommandLoader::skippedFiles();'),
            'the scanner no longer recognises a static call to skippedFiles(); the [] is vacuous',
        );
        self::assertFalse(
            self::callsSkippedFiles("<?php /** {@see skippedFiles()} */\n\$x = 1;"),
            'the scanner counts a doc-block reference as a call',
        );
        self::assertFalse(
            self::callsSkippedFiles('<?php function skippedFiles(): array {}'),
            'the scanner counts the declaration in CommandLoader itself as a call',
        );

        self::assertSame(
            [],
            $callers,
            'something in src/ now drains CommandLoader::skippedFiles(). That is the intended next step, '
                . 'not a mistake — read the property\'s doc-block for the summary-row shape the drain needs, '
                . 'then delete this test.',
        );

        // A DISPATCH THE SCANNER CANNOT READ MUST RED, NOT PASS. `$l->$m()` and
        // `call_user_func([$l, 'skippedFiles'])` are drains no token walk can
        // resolve — the method name is data by then. Rather than guess at the
        // call, this reds on the only thing that makes one possible: the name
        // appearing as a STRING LITERAL anywhere in `src/`. There are none, so
        // the assertion is an absence, and $dynamic below is its known-positive.
        self::assertTrue(
            self::mentionsSkippedFilesAsAString('<?php call_user_func([$l, \'skippedFiles\']);'),
            'the string scanner is dead, so the absence asserted below proves nothing',
        );
        self::assertFalse(
            self::mentionsSkippedFilesAsAString('<?php $l->skippedFiles();'),
            'the string scanner matches a plain call, which would make it red on every ordinary drain',
        );

        $dynamic = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (self::mentionsSkippedFilesAsAString((string) file_get_contents($file->getPathname()))) {
                $dynamic[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        self::assertSame(
            [],
            $dynamic,
            'the name `skippedFiles` appears as a string literal in src/. If that is a dynamic drain — '
                . '`$loader->$method()`, a `[$loader, \'skippedFiles\']` callable — then the census above is '
                . 'blind to it and this dormancy pin is green for the wrong reason. If it is something else '
                . 'entirely, widen this check rather than deleting it: a shape the scanner cannot read has '
                . 'to be a failure, not a silent zero.',
        );
    }

    /**
     * Whether `$source` carries the literal name `skippedFiles` inside a string.
     *
     * BOTH STRING TOKEN KINDS. `T_CONSTANT_ENCAPSED_STRING` is `'skippedFiles'`
     * and `T_ENCAPSED_AND_WHITESPACE` is the literal run inside an interpolated
     * one — `"{$prefix}skippedFiles"` is a method name a dynamic call can be
     * built from just as well as the quoted form. Checked on PHP 8.3.6, the
     * only version on this box.
     */
    private static function mentionsSkippedFilesAsAString(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (!\in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (str_contains($token[1], 'skippedFiles')) {
                return true;
            }
        }

        return false;
    }

    private static function callsSkippedFiles(string $source): bool
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        foreach ($significant as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_STRING || $token[1] !== 'skippedFiles') {
                continue;
            }
            if (($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $significant[$i - 1] ?? null;
            if (!\is_array($previous)) {
                continue;
            }
            // A scope operator is what separates a call from `function
            // skippedFiles(`, which is the declaration in CommandLoader itself.
            // T_NULLSAFE_OBJECT_OPERATOR is `?->`, and it is here because the
            // list without it was an alphabet written to match the one call
            // shape the fixture happened to use.
            if (
                \in_array(
                    $previous[0],
                    [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR],
                    true,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function writeUnparseableCommand(): void
    {
        // An empty file: `CommandSpec::fromFile()` throws on one, which is the
        // parse-failure arm. Asserted below by the collector being non-empty
        // rather than assumed, so a change in what counts as unparseable reds
        // here instead of silently emptying the test.
        file_put_contents($this->root . '/.sugar-crush/commands/broken.md', '');
    }

    private function captured(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }
}
