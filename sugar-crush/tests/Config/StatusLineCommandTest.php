<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\StatusLineCommand;

/**
 * The `statusLine` settings key: its TIER, its BOUND, and its SANITISER.
 *
 * The three are one feature and are tested together because each one is what
 * makes the other two safe to have. The key names a shell command, so the tier
 * is the whole security argument; the command runs on the TUI's own thread, so
 * the bound is what stops it being a freeze; and its stdout is foreign bytes
 * painted inside trusted chrome, so the sanitiser is what stops it being a
 * repaint.
 *
 * Every probe path here is unique per test and deleted by exact path — never a
 * glob — because other processes own `/tmp` on this box.
 */
final class StatusLineCommandTest extends TestCase
{
    /** @var list<string> exact paths this test created, removed in tearDown */
    private array $probes = [];

    protected function tearDown(): void
    {
        StatusLineCommand::reset();

        foreach ($this->probes as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function probe(string $tag): string
    {
        $path = sys_get_temp_dir() . '/sugarcrush_statusline_' . $tag . '_' . bin2hex(random_bytes(8));
        $this->probes[] = $path;

        return $path;
    }

    /** @param array<string, mixed> $entry */
    private static function settings(array $entry): array
    {
        return [StatusLineCommand::KEY => $entry];
    }

    private static function command(string $command): StatusLineCommand
    {
        $parsed = StatusLineCommand::fromSettings(self::settings([
            'type' => StatusLineCommand::TYPE_COMMAND,
            'command' => $command,
        ]));

        self::assertInstanceOf(StatusLineCommand::class, $parsed);

        return $parsed;
    }

    // =====================================================================
    // THE TIER — the security argument, end to end
    // =====================================================================

    /**
     * A PROJECT MAY NOT SET IT, at any trust level. This is the property the
     * whole feature rests on: the value is a shell command, so a project-tier
     * `statusLine` would be arbitrary code execution on clone-and-launch.
     *
     * Asserted through {@see LayeredSettings::projectLayer()} against a
     * `$trusted = true` project — the strongest grant that exists — rather than
     * by re-reading the constant, because the constant is not the mechanism:
     * `only()`'s filter is, and a key could be absent from
     * `PROJECT_TIER_KEYS` and still arrive if that filter were bypassed.
     */
    public function testATrustedProjectStillCannotSetTheStatusLine(): void
    {
        $root = sys_get_temp_dir() . '/sugarcrush_sl_project_' . bin2hex(random_bytes(8));
        mkdir($root . '/' . LayeredSettings::dir(), 0o700, true);

        $shared = $root . '/' . LayeredSettings::SHARED_PATH;
        $local = $root . '/' . LayeredSettings::LOCAL_PATH;
        file_put_contents($shared, json_encode([
            'theme' => 'light',
            StatusLineCommand::KEY => ['type' => 'command', 'command' => 'touch /tmp/pwned'],
        ]));
        file_put_contents($local, json_encode([
            StatusLineCommand::KEY => ['type' => 'command', 'command' => 'touch /tmp/pwned'],
        ]));

        $layer = LayeredSettings::projectLayer($root, true);

        // The tier gate, and the control beside it: a key this tier MAY set
        // came through, so an empty result would not have proved anything.
        self::assertArrayNotHasKey(StatusLineCommand::KEY, $layer);
        self::assertSame('light', $layer['theme'] ?? null);

        // And the merge cannot re-admit what the filter dropped.
        self::assertNull(StatusLineCommand::fromSettings(
            LayeredSettings::merge([], [], $layer),
        ));

        // Nor can it be named as the source of a value it may not carry.
        self::assertNull(LayeredSettings::projectKeySource($root, true, StatusLineCommand::KEY));

        unlink($shared);
        unlink($local);
        rmdir($root . '/' . LayeredSettings::dir());
        rmdir($root);
    }

    /**
     * The user's own `settings.json` DOES carry it — the other half of the
     * tier claim, without which the test above would also pass on a key that
     * simply is not layered at all.
     */
    public function testTheUsersOwnSettingsFileDoesCarryIt(): void
    {
        $dir = sys_get_temp_dir() . '/sugarcrush_sl_user_' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);
        $file = $dir . '/' . LayeredSettings::USER_FILE;
        file_put_contents($file, json_encode(self::settings([
            'type' => 'command',
            'command' => 'echo hi',
        ])));

        $parsed = StatusLineCommand::fromSettings(LayeredSettings::userLayer($dir));

        self::assertInstanceOf(StatusLineCommand::class, $parsed);
        self::assertSame('echo hi', $parsed->command);

        unlink($file);
        rmdir($dir);
    }

    /** The key is layered under exactly the spelling this class reads. */
    public function testTheKeyConstantIsTheSpellingThatIsLayered(): void
    {
        self::assertContains(StatusLineCommand::KEY, LayeredSettings::LAYERED_KEYS);
        self::assertNotContains(StatusLineCommand::KEY, LayeredSettings::PROJECT_TIER_KEYS);
        self::assertContains(StatusLineCommand::KEY, LayeredSettings::userTierOnlyKeys());
    }

    // =====================================================================
    // PARSING — every malformed shape costs the status line and nothing else
    // =====================================================================

    /**
     * @dataProvider refusedShapes
     * @param array<string, mixed> $config
     */
    public function testARefusedShapeYieldsNoCommand(array $config, string $why): void
    {
        self::assertNull(StatusLineCommand::fromSettings($config), $why);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedShapes(): array
    {
        return [
            'absent' => [[], 'no key at all'],
            'null' => [[StatusLineCommand::KEY => null], 'an explicit null is "I do not want one"'],
            'scalar' => [[StatusLineCommand::KEY => 'git status'], 'the bare-string shorthand is not this schema'],
            'list' => [[StatusLineCommand::KEY => ['echo hi']], 'a JSON list is not an entry'],
            'no type' => [[StatusLineCommand::KEY => ['command' => 'echo hi']], 'type is required, not assumed'],
            'other type' => [
                [StatusLineCommand::KEY => ['type' => 'static', 'text' => 'hi', 'command' => 'echo hi']],
                'a future non-command type must not be executed by a build that predates it',
            ],
            'no command' => [[StatusLineCommand::KEY => ['type' => 'command']], 'nothing to run'],
            'blank command' => [
                [StatusLineCommand::KEY => ['type' => 'command', 'command' => "  \t "]],
                'whitespace names no command',
            ],
            'command not a string' => [
                [StatusLineCommand::KEY => ['type' => 'command', 'command' => ['echo', 'hi']]],
                'an argv array is not this schema either',
            ],
        ];
    }

    public function testTheAcceptedShapeKeepsTheCommandVerbatim(): void
    {
        // Not trimmed: the leading space is the user's, and `sh -c` is what
        // decides what a command means, not this parser.
        self::assertSame(' echo  hi ', self::command(' echo  hi ')->command);
    }

    // =====================================================================
    // THE BOUND
    // =====================================================================

    /**
     * The timeout is DERIVED from the refresh period, not chosen, and the
     * property that derivation exists for is asserted rather than the literal:
     * a run cannot still be in progress when the next tick arrives.
     */
    public function testTheTimeoutCannotOutlastTheRefreshPeriod(): void
    {
        self::assertSame(
            StatusLineCommand::REFRESH_SECONDS / 2.0,
            StatusLineCommand::TIMEOUT_SECONDS,
        );
        self::assertLessThan(StatusLineCommand::REFRESH_SECONDS, StatusLineCommand::TIMEOUT_SECONDS);
    }

    /**
     * A COMMAND THAT NEVER RETURNS IS KILLED, and the freeze it causes is
     * bounded by the budget plus the two grace periods
     * ({@see StatusLineCommand::TERMINATE_GRACE_SECONDS} /
     * `KILL_GRACE_SECONDS`, 0.5 each) and nothing else.
     *
     * The ceiling is DERIVED from those three so it cannot drift: hard-coding
     * "under 3 seconds" would keep passing if the budget were raised to two.
     */
    public function testAHangingCommandIsKilledWithinItsBudget(): void
    {
        $ceiling = StatusLineCommand::TIMEOUT_SECONDS + 1.0 + 1.0;

        $started = microtime(true);
        $line = self::command('sleep 30')->run();
        $elapsed = microtime(true) - $started;

        self::assertSame('', $line, 'a command that did not finish has not said anything');
        self::assertGreaterThanOrEqual(
            StatusLineCommand::TIMEOUT_SECONDS,
            $elapsed,
            'it must actually have waited its budget, not failed instantly for some other reason',
        );
        self::assertLessThan($ceiling, $elapsed);
    }

    /**
     * The OTHER half of the two-part wait, and the one a drain-only bound
     * misses: a command that closes both pipes and then sleeps makes the drain
     * return at once and spends the rest of its life inside `proc_close()`.
     */
    public function testACommandThatClosesItsPipesAndThenSleepsIsStillBounded(): void
    {
        $ceiling = StatusLineCommand::TIMEOUT_SECONDS + 1.0 + 1.0;

        $started = microtime(true);
        $line = self::command('printf hi; exec 1>&- 2>&-; sleep 30')->run();
        $elapsed = microtime(true) - $started;

        self::assertSame('', $line);
        self::assertLessThan($ceiling, $elapsed);
    }

    /**
     * A CHATTY STDERR CANNOT DEADLOCK THE READ. 200 KB is well past Linux's
     * 64 KiB pipe buffer, so a parent that read stdout to EOF first would park
     * forever against a child parked writing stderr. The command writes stderr
     * FIRST so the wedge, if any, happens before the stdout byte exists.
     */
    public function testAChattyStderrDoesNotWedgeTheStdoutRead(): void
    {
        $line = self::command("head -c 200000 /dev/zero | tr '\\0' e >&2; printf ok")->run();

        self::assertSame('ok', $line);
    }

    /**
     * A RUNAWAY STDOUT IS CAPPED AND THE CHILD KILLED, rather than read to EOF
     * on the TUI's thread. The command would otherwise emit ~2 MB.
     */
    public function testARunawayStdoutIsCappedRatherThanReadToEof(): void
    {
        $started = microtime(true);
        $line = self::command("head -c 2000000 /dev/zero | tr '\\0' a")->run();
        $elapsed = microtime(true) - $started;

        // Capping takes the kill path, so nothing is painted — the same answer
        // a timeout gives, for the same reason: the command did not finish.
        self::assertSame('', $line);
        self::assertLessThan(StatusLineCommand::TIMEOUT_SECONDS + 2.0, $elapsed);
    }

    // =====================================================================
    // WHAT A FAILING COMMAND PAINTS — nothing
    // =====================================================================

    public function testANonZeroExitPaintsNothingEvenWhenItWroteToStdout(): void
    {
        self::assertSame('', self::command('printf oops; exit 3')->run());
    }

    public function testStderrIsNeverPainted(): void
    {
        self::assertSame('', self::command('printf "fatal: not a git repository" >&2')->run());
    }

    public function testASilentSuccessPaintsNothing(): void
    {
        self::assertSame('', self::command('true')->run());
    }

    // =====================================================================
    // THE SANITISER — foreign bytes inside trusted chrome
    // =====================================================================

    /**
     * RAW SGR IS STRIPPED. The bar is the frame's last line, so an unclosed
     * colour is not a cosmetic problem — it bleeds into whatever the terminal
     * paints next.
     */
    public function testAnsiEscapesAreStripped(): void
    {
        $line = self::command('printf "\\033[31mred\\033[0m"')->run();

        self::assertSame('red', $line);
        self::assertStringNotContainsString("\033", $line);
    }

    /**
     * A NEWLINE MAY NOT SURVIVE. `Sanitize::untrusted()` deliberately PRESERVES
     * LF/CR/TAB, so this is the gap the collapse exists to close: one LF makes
     * the bar two physical rows, and the bar is the one row the renderer
     * documents as unable to wrap.
     *
     * Collapsed rather than cut at the first line, so line two is visible
     * rather than silently discarded.
     */
    public function testEveryNewlineFormIsCollapsedIntoOneRow(): void
    {
        $line = self::command('printf "one\\ntwo\\r\\nthree\\rfour\\tfive"')->run();

        self::assertSame('one two three four five', $line);
        self::assertSame(1, preg_match('/\A[^\r\n]*\z/', $line), 'the painted value must be one row');
    }

    /**
     * The C0 sweep and the collapse together: a NUL and a BEL are dropped
     * outright (they are not whitespace), while the surrounding text closes up.
     */
    public function testControlBytesAreRemovedRatherThanPainted(): void
    {
        $line = self::command('printf "a\\007b\\010c"')->run();

        self::assertSame('abc', $line);
    }

    /**
     * TRAILING WHITESPACE GOES, which matters because `printf` is the unusual
     * spelling and `echo` is the one every status script actually uses — and
     * `echo` appends a newline. Without the trim every single-line command
     * would contribute a trailing space to the bar.
     */
    public function testTheTrailingNewlineEchoAddsDoesNotBecomeATrailingSpace(): void
    {
        self::assertSame('main', self::command('echo main')->run());
    }

    /** A wide grapheme survives intact; the sanitiser is not a byte filter. */
    public function testMultiByteOutputSurvives(): void
    {
        self::assertSame('主 ✓ é', self::command('printf "主 ✓ é"')->run());
    }

    // =====================================================================
    // THE PROCESS-LEVEL SEAM
    // =====================================================================

    public function testNothingIsConfiguredUntilSomethingConfiguresIt(): void
    {
        StatusLineCommand::reset();

        self::assertNull(StatusLineCommand::active());
        self::assertSame('', StatusLineCommand::line());

        // refresh() with nothing configured is a no-op, not a crash.
        StatusLineCommand::refresh();
        self::assertSame('', StatusLineCommand::line());
    }

    public function testConfigureInstallsTheCommandAndRefreshRunsIt(): void
    {
        StatusLineCommand::configure(self::settings(['type' => 'command', 'command' => 'echo on-branch']));

        self::assertNotNull(StatusLineCommand::active());
        self::assertSame('', StatusLineCommand::line(), 'configure() must not run anything itself');

        StatusLineCommand::refresh();

        self::assertSame('on-branch', StatusLineCommand::line());
    }

    /**
     * THE TTL IS REAL. A second refresh inside {@see StatusLineCommand::REFRESH_SECONDS}
     * must NOT spawn a second process — the tick can fire early and a
     * `proc_open()` per tick regardless would be the throttle absent.
     *
     * Counted by the command itself, so the assertion is on the number of
     * EXECUTIONS rather than on the cached text (which a re-run of the same
     * command would leave looking identical).
     */
    public function testASecondRefreshInsideTheTtlDoesNotRunTheCommandAgain(): void
    {
        $probe = $this->probe('ttl');
        StatusLineCommand::configure(self::settings([
            'type' => 'command',
            'command' => sprintf('echo x >> %s; wc -l < %s | tr -d " "', escapeshellarg($probe), escapeshellarg($probe)),
        ]));

        StatusLineCommand::refresh();
        self::assertSame('1', StatusLineCommand::line());

        for ($i = 0; $i < 5; $i++) {
            StatusLineCommand::refresh();
        }

        self::assertSame('1', StatusLineCommand::line(), 'the TTL let a second run through');
        self::assertSame(1, substr_count((string) file_get_contents($probe), "\n"));
    }

    /**
     * A RUN THAT PRODUCES NOTHING BLANKS THE SEGMENT rather than leaving the
     * previous text up. A status line that keeps asserting a value the command
     * has stopped standing behind is worse than an empty one: "the branch name
     * froze twenty minutes ago" is indistinguishable from "the branch has not
     * changed".
     */
    public function testAFailingRunBlanksTheCachedLineRatherThanKeepingIt(): void
    {
        $probe = $this->probe('blank');
        file_put_contents($probe, "ok\n");

        // Succeeds while the probe exists, exits non-zero once it does not.
        StatusLineCommand::configure(self::settings([
            'type' => 'command',
            'command' => 'cat ' . escapeshellarg($probe),
        ]));

        StatusLineCommand::refresh();
        self::assertSame('ok', StatusLineCommand::line());

        unlink($probe);
        usleep((int) (StatusLineCommand::REFRESH_SECONDS * 1_000_000) + 50_000);
        StatusLineCommand::refresh();

        self::assertSame('', StatusLineCommand::line());
    }

    /**
     * Re-configuring DROPS the previous command's text, so a value whose domain
     * is a settings file no longer in force cannot survive into the next
     * session built in this process.
     */
    public function testReconfiguringForgetsThePreviousCommandsOutput(): void
    {
        StatusLineCommand::configure(self::settings(['type' => 'command', 'command' => 'echo first']));
        StatusLineCommand::refresh();
        self::assertSame('first', StatusLineCommand::line());

        StatusLineCommand::configure(self::settings(['type' => 'command', 'command' => 'echo second']));
        self::assertSame('', StatusLineCommand::line());

        StatusLineCommand::configure([]);
        self::assertNull(StatusLineCommand::active());
        self::assertSame('', StatusLineCommand::line());
    }

    /**
     * The command runs in the directory it was configured with — `--root <lib>`
     * in a monorepo is exactly the case where that differs from `getcwd()`, and
     * a `git`-shaped status command that ignored it would report the wrong
     * repository.
     */
    public function testTheConfiguredWorkingDirectoryIsWhereTheCommandRuns(): void
    {
        $dir = sys_get_temp_dir() . '/sugarcrush_sl_cwd_' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);

        StatusLineCommand::configure(
            self::settings(['type' => 'command', 'command' => 'pwd']),
            $dir,
        );
        StatusLineCommand::refresh();

        self::assertSame(realpath($dir), realpath(StatusLineCommand::line()));

        rmdir($dir);
    }
}
