<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Support\ProcessReaper;
use SugarCraft\Crush\Tests\Support\TokenFunctionRanges;

/**
 * The three invariants {@see BackgroundSupervisor::spawnSession()} now claims in
 * prose, each pinned by something that can go red.
 *
 * E366's finding for this file was that a supervisor which deliberately
 * double-forks never reaps anything on its happy path. The fix was NOT to
 * remove the double fork — a background session outliving the TUI that started
 * it is the entire feature — so what landed is three separate claims, and prose
 * is not a pin:
 *
 *   1. THE SPAWN IS AN ARGV, NOT A SHELL STRING. On this host `/bin/sh` is dash,
 *      which does not apply the `-c` exec optimisation, so the string form put
 *      an `sh` between the supervisor and the launcher and every
 *      `proc_get_status()` / `proc_terminate()` on the handle addressed the
 *      shell. Pinned twice: a LIVE control that re-measures the host behaviour
 *      ({@see testAShellStringInterposesAShellButAnArgvDoesNot()}) and a source
 *      pin on the production call site
 *      ({@see testTheSessionSpawnPassesAnArgvNotAShellString()}).
 *   2. THE DOUBLE FORK IS AN INTENTIONAL SEAM (rule 6 — dormant-looking
 *      machinery is documented and pinned, never deleted). Its PURPOSE is
 *      detachment, so the pin measures detachment:
 *      {@see testTheSpawnedDaemonIsDetachedIntoItsOwnSession()}.
 *   3. THE LAUNCHER IS REAPED WITHOUT EVER BEING SIGNALLED, because signalling
 *      it could land mid-fork and kill the process that is creating the
 *      session. That is {@see ProcessReaper::reapIfExited()}, and the tests
 *      below are the only thing separating it from `terminateAndClose()`.
 *
 * ⚠️ HONEST SCOPE OF CLAIM 3, because a reader will otherwise over-read it.
 * Removing the `ProcessReaper::reapIfExited($proc)` call from `spawnSession()`
 * does NOT leak a zombie, and this file does not pretend it does: `$proc` goes
 * out of scope at the end of the method and PHP's `proc_open()` resource
 * destructor reaps an already-exited child instantly — MEASURED on this host,
 * PHP 8.3.6, state `Z` -> `GONE` with no observable window. The explicit call
 * buys legibility and a defined budget, not a fixed leak. So the mutation that
 * deletes it SURVIVES, and it is recorded as surviving. What these tests do pin
 * is that the reaper used there is the non-signalling one: swap it for
 * `terminateAndClose()` and
 * {@see testReapIfExitedNeverSignalsAChildThatIsStillRunning()} goes red.
 *
 * NOTE ON OWNERSHIP: round 53's file split gives `src/Sessions/BackgroundSupervisor.php`
 * to lane b and `tests/Sessions/` to lane e. This file is new rather than an
 * edit to an existing one precisely so the two can merge without a conflict.
 */
final class BackgroundSupervisorReapTest extends TestCase
{
    /**
     * The reap budget plus generous slack. `reapIfExited()` defaults to
     * {@see ProcessReaper::TERMINATE_GRACE_SECONDS}, so a bound of one budget
     * plus a second is loose enough not to flake on a loaded box and tight
     * enough that an unbounded `proc_close()` on a child sleeping for
     * {@see self::FIXTURE_LIFETIME_SECONDS} cannot satisfy it.
     */
    private const REAP_BOUND_SECONDS = ProcessReaper::TERMINATE_GRACE_SECONDS + 1.0;

    /**
     * How long a fixture child stays alive. It must exceed
     * {@see self::REAP_BOUND_SECONDS} by a wide margin or a bounded reap and an
     * unbounded one are indistinguishable — the whole point of the bound.
     */
    private const FIXTURE_LIFETIME_SECONDS = 8;

    private string $tempDir = '';

    /** @var list<int> pids a fixture reported for itself, killed on the way out */
    private array $reportedPids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_bgsup_reap_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // ONLY pids this test's own fixtures wrote down — never a pattern sweep,
        // never a global pkill: five lanes share this box and a sibling suite's
        // children are not ours to signal.
        foreach ($this->reportedPids as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }
        $this->reportedPids = [];

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // Claim 3 — reapIfExited() reaps without signalling
    // =========================================================================

    /**
     * THE ONE TEST THAT SEPARATES `reapIfExited()` FROM `terminateAndClose()`.
     *
     * The fixture traps SIGTERM and records the fact in a file, and — copying
     * the readiness handshake that round 53's item-1 fixture had to learn the
     * hard way — writes its pid only AFTER the handler is installed, so a reap
     * that races the handler cannot be mistaken for a reap that respected it.
     */
    public function testReapIfExitedNeverSignalsAChildThatIsStillRunning(): void
    {
        $proc = $this->spawnFixture(self::SIGTERM_RECORDING_CHILD);
        $pid = $this->selfReportedPid();

        $status = ProcessReaper::reapIfExited($proc, 0.2);

        $this->assertNull(
            $status,
            'a child that has not exited must not be closed — proc_close() would block on it'
        );
        $this->assertTrue(
            $this->isAlive($pid),
            'reapIfExited() must leave a running child alone; it is a launcher that may be mid-fork'
        );

        // Give any signal that WAS sent time to be delivered and recorded
        // before concluding none was. Asserting immediately would pass against
        // a SIGTERM still in flight.
        usleep(300_000);
        $this->assertFileDoesNotExist(
            $this->tempDir . '/sigterm.seen',
            'reapIfExited() signalled the child; that is terminateAndClose()\'s job, not this one\'s'
        );

        @posix_kill($pid, 9);
        proc_close($proc);
    }

    /**
     * The positive half, without which the test above is satisfied by a method
     * that does nothing at all.
     */
    public function testReapIfExitedReapsAnExitedChildAndReturnsItsExitStatus(): void
    {
        $proc = $this->spawnFixture(self::PROMPT_EXIT_CHILD);
        $pid = $this->selfReportedPid();

        $status = ProcessReaper::reapIfExited($proc);

        $this->assertSame(
            17,
            $status,
            'reapIfExited() must return the exit status proc_close() reports for a child that has gone'
        );
        $this->assertFalse(
            $this->isAlive($pid),
            'the child must be fully reaped, not left as a zombie for the resource destructor'
        );
    }

    /**
     * BOUNDED. The distinguishing measurement: against a child that will live
     * for {@see self::FIXTURE_LIFETIME_SECONDS}, a `proc_close()` would return
     * only after the child does.
     */
    public function testReapIfExitedReturnsWithinItsBudgetForARunningChild(): void
    {
        $proc = $this->spawnFixture(self::SIGTERM_RECORDING_CHILD);
        $pid = $this->selfReportedPid();

        $start = microtime(true);
        ProcessReaper::reapIfExited($proc);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            self::REAP_BOUND_SECONDS,
            $elapsed,
            sprintf(
                'reapIfExited() took %.2fs against a child with ~%ds of life left; it must be bounded '
                . 'by its budget, not by the child',
                $elapsed,
                self::FIXTURE_LIFETIME_SECONDS
            )
        );

        @posix_kill($pid, 9);
        proc_close($proc);
    }

    /**
     * Idempotent by contract, for the same reason `terminateAndClose()` is: a
     * teardown path may run twice, and `proc_get_status()` on a consumed
     * resource is a TypeError rather than a false.
     */
    public function testReapIfExitedIsANoOpOnANonResource(): void
    {
        $this->assertNull(ProcessReaper::reapIfExited(null));
        $this->assertNull(ProcessReaper::reapIfExited(false));
        $this->assertNull(ProcessReaper::reapIfExited('not a process'));

        $proc = $this->spawnFixture(self::PROMPT_EXIT_CHILD);
        $this->selfReportedPid();
        ProcessReaper::reapIfExited($proc);

        $this->assertNull(
            ProcessReaper::reapIfExited($proc),
            'a second reap of the same handle must be a no-op, not a fatal'
        );
    }

    // =========================================================================
    // Claim 1 — the spawn is an argv, not a shell string
    // =========================================================================

    /**
     * THE LIVE CONTROL FOR THE MEASUREMENT `spawnSession()` CITES IN PROSE.
     *
     * Re-measured here rather than asserted from the comment, with the same
     * descriptor spec the supervisor uses (all three fds files, no pipes),
     * because that spec is what a reader would suspect if the result ever
     * changed. On this host, PHP 8.3.6, `/bin/sh` is dash: the string form's
     * direct child reports `comm` `sh` and a DIFFERENT pid from the one the
     * program writes down for itself, while the argv form's direct child IS
     * the program.
     *
     * If a future host ships an exec-optimising `/bin/sh`, this test goes red
     * and the paragraph in `spawnSession()` needs rewriting — which is the
     * correct outcome, and is why the measurement lives in a test instead of
     * only in a sentence.
     */
    public function testAShellStringInterposesAShellButAnArgvDoesNot(): void
    {
        $this->requireProcTools();

        $script = $this->tempDir . '/sleeper.php';
        file_put_contents($script, self::SIGTERM_RECORDING_CHILD);
        $spec = $this->supervisorDescriptorSpec();

        $stringForm = proc_open(
            sprintf(
                '%s %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg($this->pidFile())
            ),
            $spec,
            $pipes
        );
        $this->assertIsResource($stringForm);
        $shellPid = proc_get_status($stringForm)['pid'];
        $stringSelfPid = $this->selfReportedPid();

        $this->assertNotSame(
            $stringSelfPid,
            $shellPid,
            'the string form did NOT interpose a shell on this host — spawnSession()\'s stated '
            . 'mechanism no longer holds and its comment must be rewritten'
        );
        $this->assertSame(
            'sh',
            $this->commOf($shellPid),
            'the direct child of a string-form proc_open() should be the shell itself'
        );

        proc_terminate($stringForm, 9);
        @posix_kill($stringSelfPid, 9);
        proc_close($stringForm);

        @unlink($this->pidFile());

        $argvForm = proc_open([PHP_BINARY, $script, $this->pidFile()], $spec, $pipes2);
        $this->assertIsResource($argvForm);
        $argvPid = proc_get_status($argvForm)['pid'];
        $argvSelfPid = $this->selfReportedPid();

        $this->assertSame(
            $argvSelfPid,
            $argvPid,
            'the argv form must make the program itself the direct child, so proc_get_status() and '
            . 'proc_terminate() address the process the caller means'
        );
        $this->assertNotSame('sh', $this->commOf($argvPid));

        proc_terminate($argvForm, 9);
        proc_close($argvForm);
    }

    /**
     * The production call site, pinned. The live control above proves the
     * mechanism matters; this proves `spawnSession()` is on the right side of
     * it, and goes red the moment someone reintroduces the `sprintf()`.
     */
    public function testTheSessionSpawnPassesAnArgvNotAShellString(): void
    {
        $file = (new \ReflectionClass(BackgroundSupervisor::class))->getFileName();
        $this->assertIsString($file);
        $source = file_get_contents($file);
        $this->assertIsString($source);

        $this->assertSame(
            'array',
            self::procOpenCommandShape($source, 'spawnSession'),
            'BackgroundSupervisor::spawnSession() must hand proc_open() an argv array. A string is '
            . 'handed to /bin/sh -c, and on this host that interposes a dash the supervisor then '
            . 'mistakes for its own launcher.'
        );
    }

    /**
     * THE KNOWN-POSITIVE FIXTURE FOR THE SCANNER ABOVE (rule 15).
     *
     * `assertSame('array', ...)` is also what a scanner that has been mutated
     * to return `'array'` unconditionally would produce, so the assertion on
     * production code proves nothing on its own. These three fixtures push
     * known answers through the SAME method: a shell-string site must come back
     * `string`, an argv site `array`, and — rule 14 — a site whose command the
     * scanner genuinely cannot resolve must come back `unknown` rather than
     * being silently dropped or optimistically called `array`.
     *
     * The fixtures are assembled by concatenation rather than written out, so a
     * future blanket rewrite of the shell-string pattern cannot eat this test's
     * own evidence the way the 2026-08-23 sweep ate one (rule 26).
     */
    public function testTheCommandShapeScannerAnswersCorrectlyOnKnownInputs(): void
    {
        $open = 'proc' . '_open';

        $shellString = "<?php\nfunction spawn() {\n"
            . '    $cmd = sprintf(' . "'%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg('x'));\n"
            . '    ' . $open . '($cmd, [], $pipes);' . "\n}\n";

        $argv = "<?php\nfunction spawn() {\n"
            . '    $cmd = [PHP_BINARY, ' . "'-r', 'x'];\n"
            . '    ' . $open . '($cmd, [], $pipes);' . "\n}\n";

        $inline = "<?php\nfunction spawn() {\n"
            . '    ' . $open . '([PHP_BINARY, ' . "'-r'], [], \$pipes);\n}\n";

        $opaque = "<?php\nfunction spawn(\$whatever) {\n"
            . '    ' . $open . '($whatever, [], $pipes);' . "\n}\n";

        // THE ALPHABET THIS FIXTURE COULD NOT EXPRESS UNTIL NOW. Every case
        // above spells the call bare and assigns the command exactly once,
        // which is the shape the production site happens to have — so the
        // fixture confirmed the scanner on the inputs it was already known to
        // handle and said nothing about the two it silently mis-answered.
        // Both were found by running the scanner directly, and both answered
        // in the PASSING direction, which is the only direction that matters.
        $fqAlone = "<?php\nfunction spawn() {\n"
            . '    \\' . $open . "('sh -c whatever', [], \$pipes);\n}\n";

        $fqBesideArgv = "<?php\nfunction spawn() {\n"
            . '    ' . $open . "([PHP_BINARY, '-r', 'x'], [], \$pipes);\n"
            . '    \\' . $open . "('sh -c whatever', [], \$other);\n}\n";

        $conditional = "<?php\nfunction spawn(\$useShell) {\n"
            . "    if (\$useShell) {\n        \$cmd = 'sh -c whatever';\n"
            . "    } else {\n        \$cmd = [PHP_BINARY, '-r', 'x'];\n    }\n"
            . '    ' . $open . '($cmd, [], $pipes);' . "\n}\n";

        $conditionalSwapped = "<?php\nfunction spawn(\$useShell) {\n"
            . "    if (\$useShell) {\n        \$cmd = [PHP_BINARY, '-r', 'x'];\n"
            . "    } else {\n        \$cmd = 'sh -c whatever';\n    }\n"
            . '    ' . $open . '($cmd, [], $pipes);' . "\n}\n";

        $this->assertSame('string', self::procOpenCommandShape($shellString, 'spawn'));
        $this->assertSame('array', self::procOpenCommandShape($argv, 'spawn'));
        $this->assertSame('array', self::procOpenCommandShape($inline, 'spawn'));
        $this->assertSame(
            'unknown',
            self::procOpenCommandShape($opaque, 'spawn'),
            'a command the scanner cannot resolve must be reported, not skipped — a guard that '
            . 'quietly ignores the unparseable has a hole shaped like the next defect'
        );
        $this->assertSame(
            'absent',
            self::procOpenCommandShape($argv, 'someOtherMethod'),
            'the scanner must be scoped to the named method, or an argv anywhere in the file would '
            . 'answer for a shell string in spawnSession()'
        );

        $this->assertSame(
            'string',
            self::procOpenCommandShape($fqAlone, 'spawn'),
            'a fully-qualified spawn is INVISIBLE to the scanner, not merely unresolved: it '
            . 'answered "absent" here, and "absent" is what the production pin accepts for a '
            . 'method it cannot find. See isCallTo() — the lexer emits one T_NAME_FULLY_QUALIFIED'
        );
        $this->assertSame(
            'string',
            self::procOpenCommandShape($fqBesideArgv, 'spawn'),
            'a fully-qualified shell-string spawn hid behind a bare argv one and the method '
            . 'reported "array" — the exact "cannot hide behind the fixed one" claim this '
            . 'scanner makes about itself'
        );
        $this->assertSame(
            'string',
            self::procOpenCommandShape($conditional, 'spawn'),
            'a command assigned a shell string in one branch and an argv in another must collapse '
            . 'to the worst shape; resolving only the nearest assignment answered "array" here'
        );
        $this->assertSame(
            'string',
            self::procOpenCommandShape($conditionalSwapped, 'spawn'),
            'the same two branches in the opposite order must give the same verdict — a scanner '
            . 'whose answer tracks source order is reporting layout, not risk'
        );
    }

    // =========================================================================
    // Claim 2 — the double fork is an intentional detachment seam
    // =========================================================================

    /**
     * THE DOUBLE FORK, PINNED BY ITS PURPOSE RATHER THAN BY ITS SHAPE.
     *
     * Rule 6 forbids removing dormant-looking machinery, and a `pcntl_fork()`
     * that is immediately followed by another one reads like a redundancy to
     * anyone who has not met `posix_setsid()`. Counting forks in the generated
     * source would pin the shape and say nothing about why; this drives a real
     * spawn and asserts the OUTCOME the second fork exists to produce — the
     * daemon is in a session of its own, with no controlling terminal, and is
     * not a child of the process that asked for it. Delete either fork or the
     * `setsid` between them and this goes red.
     *
     * The task is `cat >/dev/null; printf ...` rather than a real provider,
     * copying {@see BackgroundSupervisorTest::testSpawnedSessionActuallyRunsItsTaskAndBecomesRetrievable()}:
     * this test is about the process tree, not about the model.
     */
    public function testTheSpawnedDaemonIsDetachedIntoItsOwnSession(): void
    {
        $this->requireProcTools();
        foreach (['pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }

        $previousCmd = getenv('SUGARCRUSH_BACKEND_CMD');
        $previousProvider = getenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_PROVIDER=');
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf DETACHED');

        $ourStat = $this->statFieldsOf((int) getmypid());
        $this->assertNotNull($ourStat, 'this process must be readable in /proc to compare sessions');
        $ourSession = $ourStat['session'];

        $supervisor = new BackgroundSupervisor();
        $ipc = null;

        try {
            $session = $supervisor->spawnSession(
                name: 'detachment probe',
                agent: $this->bareAgent(),
                task: 'probe the process tree',
                workingDirectory: sys_get_temp_dir(),
                timeoutSeconds: 30,
            );

            $ipc = (new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc'))
                ->getValue($supervisor)[$session->id];
            $daemonPid = $ipc['pid'];
            $this->reportedPids[] = $daemonPid;

            $this->assertGreaterThan(
                0,
                $daemonPid,
                'the handshake must carry a real daemon pid; without one every later assertion here '
                . 'would be measuring pid 0'
            );

            $stat = $this->statFieldsOf($daemonPid);
            $this->assertNotNull($stat, 'the daemon was gone before its process tree could be read');

            // /proc/<pid>/stat, 1-indexed as procfs(5) numbers them: 4 ppid,
            // 6 session, 7 tty_nr. Fields are taken by index from the slice
            // after the comm field, which can itself contain spaces.
            //
            // THESE THREE ASSERTIONS SEPARATE THE TWO FORKS, and the middle one
            // is the reason this test exists rather than a fork count. It was
            // first written as `sid === daemonPid` — "the daemon leads its own
            // session" — and it FAILED, by one: the observed sid was the
            // daemon's pid minus one. That is not a defect, it is the second
            // fork working. `posix_setsid()` runs in the FIRST child, which
            // becomes the session leader; the daemon is the SECOND child, so it
            // is in the new session and is deliberately NOT its leader, which
            // is precisely how it becomes unable to ever reacquire a
            // controlling terminal. A pin written the other way would have gone
            // green on a supervisor with the second fork removed.
            $this->assertNotSame(
                getmypid(),
                $stat['ppid'],
                'the daemon is still a child of this process — it was never reparented, and a '
                . 'background session would die with the TUI that started it'
            );
            $this->assertNotSame(
                $ourSession,
                $stat['session'],
                'posix_setsid() did not take: the daemon shares a session with its spawner, so a '
                . 'SIGHUP to that session group would take the background work down with it'
            );
            $this->assertNotSame(
                $daemonPid,
                $stat['session'],
                'the daemon IS its own session leader, which means the SECOND fork did not happen: '
                . 'a session leader can reacquire a controlling terminal by opening one, and '
                . 'forking again after setsid() is the only thing that makes that impossible'
            );
            $this->assertSame(
                0,
                $stat['tty'],
                'the daemon still has a controlling terminal; detaching from it is what the setsid '
                . 'between the two forks is for'
            );
        } finally {
            putenv($previousCmd === false ? 'SUGARCRUSH_BACKEND_CMD' : 'SUGARCRUSH_BACKEND_CMD=' . $previousCmd);
            putenv($previousProvider === false ? 'SUGARCRUSH_PROVIDER' : 'SUGARCRUSH_PROVIDER=' . $previousProvider);
            if (is_array($ipc)) {
                @unlink($ipc['socketPath']);
                @unlink($ipc['bufferPath']);
                @unlink($ipc['bufferPath'] . '.log');
            }
        }
    }

    /**
     * The launcher does not survive the call that created it.
     *
     * ⚠️ READ THE CLASS DOCBLOCK BEFORE TREATING THIS AS THE PIN FOR
     * `reapIfExited($proc)`. It is not: the resource destructor would satisfy
     * it too, which is exactly why the mutation deleting that call is recorded
     * as SURVIVING. What this asserts is the weaker, still worth having claim
     * that `spawnSession()` leaves no zombie behind on its happy path — the
     * regression E365 describes, one level up.
     *
     * ⚠️ WHY THIS TEST OPENS BY MAKING A ZOMBIE ON PURPOSE. Its real assertion
     * is `assertSame([], $zombies)`, and an assertion that a set is EMPTY is
     * satisfied just as well by an instrument that cannot see anything at all.
     * That is not hypothetical here and it is not a hypothetical in general:
     * MEASURED, with `directChildPids()` mutated to `return []`, this test
     * passed — one assertion, green — and a probe on the UNMUTATED tree showed
     * `$new` is empty on every ordinary run, so the zombie-classifying
     * predicate below never executes on a single pid. Both halves of it could
     * be inverted and nothing here would notice.
     *
     * So {@see assertTheZombieScannerIsLive()} runs FIRST, IN THIS TEST — not
     * in a sibling a `--filter` or a careless deletion can separate from it —
     * and drives a real exited-but-unwaited child through the SAME
     * `directChildPids()` + `commOf()`/`isAlive()` pair, requiring it to be
     * FOUND, then reaped, then not found. Only after that does the empty result
     * below carry information.
     */
    public function testSpawnSessionLeavesNoZombieChildBehind(): void
    {
        $this->requireProcTools();
        $this->assertTheZombieScannerIsLive();
        foreach (['pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }

        $before = $this->directChildPids();

        $previousCmd = getenv('SUGARCRUSH_BACKEND_CMD');
        $previousProvider = getenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_PROVIDER=');
        putenv('SUGARCRUSH_BACKEND_CMD=cat >/dev/null; printf NOZOMBIE');

        $supervisor = new BackgroundSupervisor();
        $ipc = null;

        try {
            $session = $supervisor->spawnSession(
                name: 'zombie probe',
                agent: $this->bareAgent(),
                task: 'probe for zombies',
                workingDirectory: sys_get_temp_dir(),
                timeoutSeconds: 30,
            );

            $ipc = (new \ReflectionProperty(BackgroundSupervisor::class, 'sessionIpc'))
                ->getValue($supervisor)[$session->id];
            $this->reportedPids[] = $ipc['pid'];

            $new = array_values(array_diff($this->directChildPids(), $before));
            $zombies = array_values(array_filter($new, fn (int $pid): bool => $this->commOf($pid) !== null
                && !$this->isAlive($pid)));

            $this->assertSame(
                [],
                $zombies,
                'spawnSession() returned leaving an unwaited child: ' . implode(',', $zombies)
            );
        } finally {
            putenv($previousCmd === false ? 'SUGARCRUSH_BACKEND_CMD' : 'SUGARCRUSH_BACKEND_CMD=' . $previousCmd);
            putenv($previousProvider === false ? 'SUGARCRUSH_PROVIDER' : 'SUGARCRUSH_PROVIDER=' . $previousProvider);
            if (is_array($ipc)) {
                @unlink($ipc['socketPath']);
                @unlink($ipc['bufferPath']);
                @unlink($ipc['bufferPath'] . '.log');
            }
        }
    }

    // =========================================================================
    // The command-shape scanner
    // =========================================================================

    /**
     * The shape of the first argument every `proc_open()` inside $method is
     * given: `array`, `string`, `unknown`, or `absent` when the named method
     * has no `proc_open()` in it at all.
     *
     * RESOLVES ONE LEVEL OF INDIRECTION and no more, because that is what the
     * call site under test uses and a general dataflow analysis in a test is a
     * second program to get wrong: a `[` or `array(` at the call is `array`; a
     * variable is traced back to its nearest assignment WITHIN THE SAME METHOD
     * (that bound is {@see TokenFunctionRanges}' whole reason for being shared)
     * and the right-hand side classified the same way; a quoted string or a
     * `sprintf()`/`implode()`/concatenation is `string`.
     *
     * ANYTHING ELSE IS `unknown` AND THE CALLER MUST TREAT THAT AS A FAILURE.
     * The scanner never guesses in the safe direction — an unresolvable command
     * reported as `array` is a hole exactly the shape of the defect this pins.
     *
     * Multiple `proc_open()` calls in one method collapse to the WORST shape
     * present (`unknown` over `string` over `array`), and so do multiple
     * assignments to the variable a call is given.
     *
     * ⚠️ WHAT THIS PARAGRAPH USED TO CLAIM, AND WHY IT NOW READS DIFFERENTLY
     * (kept rather than quietly corrected, because the bound is the useful
     * part). IT SAID: "so adding a second, shell-string spawn beside a fixed
     * one cannot hide behind the fixed one" — flat, with no conditions. WHAT
     * IS TRUE NOW: that was false twice over when written, and both holes were
     * MEASURED by running this scanner directly rather than reading it. A
     * second spawn written `\proc_open(...)` was not seen at all, because the
     * lexer emits the fully-qualified name as one `T_NAME_FULLY_QUALIFIED`
     * token ({@see isCallTo()}); and a command variable assigned in two
     * branches was resolved to whichever branch came last in the source
     * ({@see assignmentsWithin()}). WHY THE CLAIM STILL EARNS ITS PLACE: the
     * collapse is what makes this a guard rather than a spot check, and with
     * both holes closed the sentence is true for a bare or fully-qualified
     * call and for a variable with several reaching assignments. It is still
     * not dataflow analysis, and {@see assignmentsWithin()} states that bound.
     */
    private static function procOpenCommandShape(string $source, string $method): string
    {
        $tokens = token_get_all($source);
        $range = null;
        foreach (TokenFunctionRanges::scan($tokens) as $candidate) {
            if ($candidate['name'] === $method) {
                $range = $candidate;
                break;
            }
        }
        if ($range === null) {
            return 'absent';
        }

        $shapes = [];
        for ($i = $range['from']; $i <= $range['to']; $i++) {
            if (!self::isCallTo($tokens[$i], 'proc_open')) {
                continue;
            }
            // A method call `$x->proc_open(` or a definition is not the
            // function; only a bare call counts.
            $prev = self::prevSignificant($tokens, $i);
            if ($prev !== null && in_array(self::text($tokens[$prev]), ['->', '::', 'function'], true)) {
                continue;
            }
            $paren = self::nextSignificant($tokens, $i);
            if ($paren === null || self::text($tokens[$paren]) !== '(') {
                continue;
            }
            $first = self::nextSignificant($tokens, $paren);
            if ($first === null) {
                $shapes[] = 'unknown';
                continue;
            }
            $shapes[] = self::classifyOperand($tokens, $first, $range);
        }

        if ($shapes === []) {
            return 'absent';
        }
        foreach (['unknown', 'string', 'array'] as $worst) {
            if (in_array($worst, $shapes, true)) {
                return $worst;
            }
        }

        return 'unknown';
    }

    /**
     * Classify the token at $at as the head of an array expression, a string
     * expression, or neither — following a variable back to its assignment
     * inside $range exactly once.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array{name:string,from:int,to:int}       $range
     */
    private static function classifyOperand(array $tokens, int $at, array $range, bool $mayFollow = true): string
    {
        $token = $tokens[$at];
        $text = self::text($token);

        if ($text === '[') {
            return 'array';
        }
        if (is_array($token) && $token[0] === T_ARRAY) {
            return 'array';
        }
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            return 'string';
        }
        if ($text === '"') {
            return 'string';
        }
        foreach (['sprintf', 'implode', 'vsprintf', 'escapeshellcmd'] as $stringy) {
            if (self::isCallTo($token, $stringy)) {
                return 'string';
            }
        }
        if (is_array($token) && $token[0] === T_VARIABLE && $mayFollow) {
            $assigned = self::assignmentsWithin($tokens, $at, $token[1], $range);
            if ($assigned === []) {
                return 'unknown';
            }

            // EVERY assignment, collapsed to the worst — see assignmentsWithin().
            $shapes = array_map(
                fn (int $rhs): string => self::classifyOperand($tokens, $rhs, $range, false),
                $assigned
            );
            foreach (['unknown', 'string', 'array'] as $worst) {
                if (in_array($worst, $shapes, true)) {
                    return $worst;
                }
            }

            return 'unknown';
        }

        return 'unknown';
    }

    /**
     * The right-hand side of EVERY `$name =` at or before $before, bounded
     * below by the enclosing function's opening brace so an assignment in an
     * EARLIER method can never answer for a call in a later one.
     *
     * ⚠️ EVERY ASSIGNMENT, NOT THE NEAREST, AND THAT IS THE WHOLE POINT. This
     * used to return the textually nearest one and stop, which reads a
     * two-branch conditional as whichever branch happens to be written LAST.
     * MEASURED by running the scanner directly: with `$c` assigned a shell
     * string in the `if` and an argv in the `else`, it answered `array` — the
     * PASSING answer for source where half the paths spawn through a shell;
     * with the branches swapped it answered `string`. The verdict tracked
     * source order, not risk. A conditional fallback is the most plausible way
     * a shell string comes back to a fixed call site, so the branch this
     * cannot see is the branch that matters.
     *
     * The caller collapses the results to the worst shape present, so a call
     * site is only `array` when EVERY reaching assignment is an array literal.
     *
     * SCOPE, stated because a reader will otherwise over-read it: this is
     * still not dataflow analysis. It does not know which branches are
     * mutually exclusive, does not follow a loop's back edge, and stops at
     * assignments lexically before the call. Its bias is toward `unknown` and
     * `string`, both of which are FAILING answers — it can cost a false red,
     * never a false green.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @param  array{name:string,from:int,to:int}       $range
     * @return list<int>
     */
    private static function assignmentsWithin(array $tokens, int $before, string $name, array $range): array
    {
        $found = [];
        for ($i = $before - 1; $i > $range['from']; $i--) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $name) {
                continue;
            }
            $eq = self::nextSignificant($tokens, $i);
            if ($eq === null || self::text($tokens[$eq]) !== '=') {
                continue;
            }
            $rhs = self::nextSignificant($tokens, $eq);
            if ($rhs !== null) {
                $found[] = $rhs;
            }
        }

        return $found;
    }

    /**
     * Is this token a call to the global function $name, written either bare or
     * fully qualified?
     *
     * ⚠️ THE FULLY-QUALIFIED FORM IS A DIFFERENT TOKEN, NOT TWO TOKENS. Since
     * PHP 8.0 the lexer emits `\proc_open` as ONE `T_NAME_FULLY_QUALIFIED`
     * whose text INCLUDES the backslash — not `T_NS_SEPARATOR` followed by
     * `T_STRING` — VERIFIED against `token_get_all()` on this host, PHP 8.3.6.
     * The scanner used to test `T_STRING && text === 'proc_open'`, so a leading
     * backslash did not make it answer `unknown`, it made the call INVISIBLE:
     * a method containing nothing but `\proc_open('shell string', ...)` was
     * reported `absent`, and a shell-string spawn added beside a good argv one
     * was reported `array`. Both MEASURED by running this scanner directly.
     *
     * That is not a theoretical spelling in this package:
     * {@see \SugarCraft\Crush\Support\ProcessReaper} writes every builtin fully
     * qualified (`\proc_close`, `\proc_terminate`, `\proc_get_status`), so a
     * future editor normalising a spawn site to the same house style is the
     * likely way this reappears.
     *
     * @param array{0:int,1:string,2:int}|string $token
     */
    private static function isCallTo(array|string $token, string $name): bool
    {
        if (!is_array($token)) {
            return false;
        }
        if ($token[0] === T_STRING) {
            return $token[1] === $name;
        }
        if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
            return $token[1] === '\\' . $name;
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            if (!self::insignificant($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (!self::insignificant($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function insignificant(array|string $token): bool
    {
        return is_array($token)
            && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    // =========================================================================
    // Fixtures and process observation
    // =========================================================================

    /**
     * Traps SIGTERM, records that it arrived, and only THEN reports its pid.
     *
     * The order of those two is the fixture, not incidental: round 53's item-1
     * test had it the other way round and a missing signal-9 escalation
     * survived, because the reap ran before the handler existed. Here the
     * inversion would be worse — a SIGTERM arriving before the handler kills
     * the child at its default disposition, and
     * {@see testReapIfExitedNeverSignalsAChildThatIsStillRunning()} would then
     * find no `sigterm.seen` and pass while the child lay dead. The liveness
     * assertion in that test is the second guard against exactly that.
     */
    private const SIGTERM_RECORDING_CHILD = <<<'PHP'
        <?php
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use (&$argv): void {
            file_put_contents(dirname($argv[1]) . '/sigterm.seen', '1');
        });
        file_put_contents($argv[1], (string) getmypid());
        $deadline = microtime(true) + 8.0;
        while (microtime(true) < $deadline) {
            usleep(20000);
        }
        PHP;

    /**
     * Reports its pid and exits 17 — a status no signal death produces, so
     * {@see testReapIfExitedReapsAnExitedChildAndReturnsItsExitStatus()} cannot
     * be satisfied by a child that was killed rather than waited for.
     */
    private const PROMPT_EXIT_CHILD = <<<'PHP'
        <?php
        file_put_contents($argv[1], (string) getmypid());
        exit(17);
        PHP;

    /**
     * The descriptor spec {@see BackgroundSupervisor::spawnSession()} uses:
     * every fd a file, no pipes. It matters for the shell measurement — the
     * claim in that method's comment is scoped to "with this exact descriptor
     * spec", so the control must use it rather than a convenient one.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function supervisorDescriptorSpec(): array
    {
        $log = $this->tempDir . '/child.log';

        return [
            ['file', '/dev/null', 'r'],
            ['file', $log, 'a'],
            ['file', $log, 'a'],
        ];
    }

    /** @return resource */
    private function spawnFixture(string $source)
    {
        $this->requireProcTools();

        $script = $this->tempDir . '/fixture.php';
        file_put_contents($script, $source);
        @unlink($this->pidFile());

        $proc = proc_open([PHP_BINARY, $script, $this->pidFile()], $this->supervisorDescriptorSpec(), $pipes);
        $this->assertIsResource($proc, 'the fixture child failed to spawn');

        return $proc;
    }

    private function pidFile(): string
    {
        return $this->tempDir . '/fixture.pid';
    }

    /**
     * The pid the fixture wrote down for ITSELF, WAITED FOR rather than
     * assumed. `proc_open()` returns before the child has run a line, so every
     * test here blocks on this before measuring anything — a fixture that has
     * not reached `pcntl_signal()` yet is a well-behaved fixture wearing a
     * stubborn one's name.
     */
    private function selfReportedPid(): int
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $raw = @file_get_contents($this->pidFile());
            if (is_string($raw) && ctype_digit(trim($raw))) {
                $pid = (int) trim($raw);
                if (!in_array($pid, $this->reportedPids, true)) {
                    $this->reportedPids[] = $pid;
                }

                return $pid;
            }
            usleep(20000);
        }

        $this->fail('the fixture child never reported its pid');
    }

    /**
     * Alive AND not a zombie. `posix_kill($pid, 0)` answers true for a child
     * that exited and has not been waited for, which is precisely the state
     * these tests are trying to tell apart from "reaped".
     */
    private function isAlive(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return false;
        }

        return self::stateOf($stat) !== 'Z';
    }

    private function commOf(int $pid): ?string
    {
        $comm = @file_get_contents("/proc/{$pid}/comm");

        return $comm === false ? null : trim($comm);
    }

    /**
     * ppid, session and tty from `/proc/<pid>/stat`.
     *
     * Parsed from AFTER the closing parenthesis of the comm field, never by
     * splitting the whole line: comm is the executable name in parentheses and
     * may itself contain spaces and parentheses, so field 4 is not
     * `explode(' ')[3]` in general.
     *
     * @return array{ppid:int,session:int,tty:int}|null
     */
    private function statFieldsOf(int $pid): ?array
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return null;
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }
        $rest = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];
        // $rest[0] is field 3 (state), so field N is $rest[N - 3].
        if (count($rest) < 5) {
            return null;
        }

        return [
            'ppid' => (int) $rest[1],
            'session' => (int) $rest[3],
            'tty' => (int) $rest[4],
        ];
    }

    private static function stateOf(string $stat): string
    {
        $close = strrpos($stat, ')');
        if ($close === false) {
            return 'Z';
        }
        $rest = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];

        return $rest[0] ?? 'Z';
    }

    /**
     * Direct children of this process, from `/proc/self/task/<tid>/children`.
     *
     * @return list<int>
     */
    private function directChildPids(): array
    {
        $pids = [];
        foreach (glob('/proc/self/task/*/children') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($raw)) ?: [] as $candidate) {
                if (ctype_digit($candidate)) {
                    $pids[] = (int) $candidate;
                }
            }
        }

        return array_values(array_unique($pids));
    }

    /**
     * KNOWN-POSITIVE CONTROL for the zombie scanner, run inside the test whose
     * only assertion is that a set is empty.
     *
     * Spawns a child that exits immediately and deliberately does NOT wait for
     * it, so it sits in state `Z` — the exact condition
     * {@see testSpawnSessionLeavesNoZombieChildBehind()} claims not to find
     * after `spawnSession()`. Every component of that claim is then required to
     * fire on it: `directChildPids()` must LIST it, `commOf()` must answer
     * non-null (a zombie keeps `/proc/<pid>/comm`), and `isAlive()` must answer
     * false. `proc_close()` reaps it and the pid must leave the list again.
     *
     * MEASURED on this host (PHP 8.3.6, Linux 6.8), three consecutive takes: a
     * child running `exit(0);` reaches state `Z` in ~0.044s, appears in
     * `/proc/self/task/<tid>/children` while there, and is gone from it
     * immediately after `proc_close()`. The 5s bound below is that figure with
     * two orders of magnitude of slack for a loaded box — five lanes share it.
     */
    private function assertTheZombieScannerIsLive(): void
    {
        $control = proc_open(
            [PHP_BINARY, '-r', 'exit(0);'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']],
            $controlPipes
        );
        $this->assertIsResource($control, 'could not spawn the control child');

        $status = proc_get_status($control);
        $controlPid = (int) $status['pid'];
        $this->reportedPids[] = $controlPid;

        try {
            // BOTH POLARITIES OF isAlive(), because only one of them is checked
            // by the zombie assertion downstream and a control that checks one
            // is half a control. MEASURED: with `isAlive()` stubbed to always
            // answer TRUE the test dies here (the filter would then miss every
            // real zombie); with it stubbed to always answer FALSE it SURVIVED
            // everything else in this method — that direction over-reports
            // rather than under-reports, so it is the benign one, but a scanner
            // that calls this process dead is still a broken scanner.
            $this->assertTrue(
                $this->isAlive(getmypid()),
                'isAlive() reports THIS RUNNING PROCESS as not alive, so it is answering a '
                . 'constant rather than reading /proc and the zombie filter below is noise'
            );

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && $this->isAlive($controlPid)) {
                usleep(2000);
            }

            $this->assertFalse(
                $this->isAlive($controlPid),
                'the control child never became a zombie, so this test cannot vouch for the '
                . 'scanner and the empty result it guards means nothing'
            );
            $this->assertContains(
                $controlPid,
                $this->directChildPids(),
                'directChildPids() cannot see a REAL unwaited child, so its empty answer in '
                . 'this test is the answer of a dead instrument, not evidence of a clean spawn'
            );
            $this->assertNotNull(
                $this->commOf($controlPid),
                'commOf() answered null for a real zombie, which would make the zombie filter '
                . 'reject every genuine offender it is meant to catch'
            );
        } finally {
            // Reap it here rather than leaving it to tearDown: the second half
            // of the control is that the scanner stops seeing it once waited for.
            proc_close($control);
        }

        $this->assertNotContains(
            $controlPid,
            $this->directChildPids(),
            'directChildPids() still lists a child that has been waited for, so it cannot tell '
            . 'a reaped child from a leaked one — which is the only distinction this test makes'
        );
    }

    private function requireProcTools(): void
    {
        foreach (['proc_open', 'posix_kill'] as $fn) {
            if (!function_exists($fn)) {
                $this->markTestSkipped("{$fn}() unavailable");
            }
        }
        if (!is_dir('/proc/self')) {
            $this->markTestSkipped('/proc is required to observe the process tree');
        }
    }

    private function bareAgent(): Agent
    {
        return new Agent(
            name: 'bg-agent',
            description: 'Background agent',
            prompt: '',
            model: '',
            provider: '',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }
}
