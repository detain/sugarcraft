<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A child this suite spawns must not block on the RUNNER's descriptor 0.
 *
 * E212 closed this for the runner itself, with
 * {@see \SugarCraft\Crush\Cli\NonInteractive::pinStdinDefault()} in
 * `tests/bootstrap.php`, and that pin is deliberately in-process only: `src/`
 * and `bin/` read the real `\STDIN`, which is the production contract. But
 * `exec()` and `proc_open()` hand a child THIS process's descriptor 0, and a
 * dozen-odd test files spawn a real `bin/sugarcrush` — deliberately not a
 * count, because a cardinality over `tests/` is stale the next time one is
 * added and three plausible generators disagree about this one anyway. So the
 * same hazard was
 * still live one process down, and it is the WORSE version: the runner's
 * `-p` tests are in-process and pinned, while a spawned `-p` child reaches
 * `readStdinIfPiped()` with no pin at all and `stream_get_contents()` on an
 * open, never-written pipe returns when the writer closes and not before.
 *
 * WHAT IT LOOKS LIKE WHEN IT HAPPENS, which is why it went unattributed for a
 * round: `phpunit.xml` sets `defaultTimeLimit="60"`, so the test is reported
 * RISKY — "aborted after 60 seconds", "did not perform any assertions" — with
 * no mention of stdin, and the run merely gets slower. E242 recorded exactly
 * that shape ("crawling", "not CPU-bound", "wall-clock waits") and attributed
 * it to two suites sharing a temp sandbox. It reproduces with one process on
 * an idle box; what varies is the fd 0 the runner was started with, which is a
 * property of the harness rather than of the tree.
 *
 * The fix is in `tests/bootstrap.php`, which carries the measurements.
 */
final class SuiteChildStdinIsolationTest extends TestCase
{
    /** Seconds the CONTROL is allowed to hang before it is killed. */
    private const CONTROL_BOUND = 6;

    /** Seconds the bootstrapped run gets. Generous: it should take ~0.1s. */
    private const TREATMENT_BOUND = 30;

    /** @var list<string> */
    private array $paths = [];

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/sc_stdin_home_' . getmypid() . '_' . uniqid((string) getmypid(), true);
        mkdir($this->home, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
        // Recursive, because the spawned binary is handed this directory as
        // HOME and writes its own state into it: a bare rmdir() fails on a
        // non-empty directory and leaks one per test run into /tmp.
        self::removeTree($this->home);

        parent::tearDown();
    }

    /**
     * THE CONTROL FIRST, and it is not optional here (rule 15/E228). "The
     * child finished quickly" is also what a harness reports when it never
     * started the child, mis-spelled the binary, or handed it a stdin that was
     * already at EOF. So the same harness runs the same spawn from a script
     * that has NOT loaded `tests/bootstrap.php`, and that one must hang.
     *
     * Then the treatment: the identical script with the bootstrap required
     * first. Same runner stdin — an open pipe this process holds the write end
     * of and never writes to — and the spawned binary must answer anyway.
     */
    public function testTheBootstrapKeepsASpawnedBinaryOffTheRunnersHeldOpenStdin(): void
    {
        $control = $this->spawnWithHeldOpenStdin($this->script(false, self::CONTROL_BOUND));
        $this->assertSame(
            137,
            $control['rc'],
            "the control did not hang, so this harness cannot detect one.\nchild said: " . $control['raw'],
        );
        $this->assertGreaterThan(self::CONTROL_BOUND - 1.0, $control['elapsed']);

        $treatment = $this->spawnWithHeldOpenStdin($this->script(true, self::TREATMENT_BOUND));
        $this->assertSame(
            0,
            $treatment['rc'],
            "the spawned binary blocked on the runner's stdin.\nchild said: " . $treatment['raw'],
        );
        $this->assertLessThan(
            self::CONTROL_BOUND,
            $treatment['elapsed'],
            'the spawned binary answered, but only after a wait the control shows is the stdin block',
        );
    }

    /**
     * THE BOOTSTRAP REPLACES DESCRIPTOR 0 RATHER THAN FLAGGING IT, and this
     * assertion is the INVERSE of the one that stood here for two rounds.
     *
     * WHAT THIS SAID:
     * `assertTrue(is_resource(\STDIN), 'tests/bootstrap.php closed the \STDIN
     * constant; SugarCraft\Mosaic\Detect reads it unguarded and 107 tests error
     * out when it is gone')` — a guard whose whole job was to forbid the change
     * that has now been made.
     *
     * WHAT IS TRUE NOW. The 107 was never 107 costs. It was ONE defect — a
     * candy-mosaic fallback naming a `Capability` case candy-palette spells
     * differently — multiplied by every test that reached it, and the unguarded
     * read that triggered it is guarded now (E302/E318:
     * `Detect::stdinFd()` answers null for a dead handle rather than passing it
     * to `stream_select()`). RE-MEASURED, PHP 8.3.6, full suite, the descriptor
     * replacement alone against a green `9661 tests / 142165 assertions / 1
     * skipped / rc 0` baseline at the same head: ONE error and TWO failures,
     * all three in tests whose entire subject is this repair, and not one
     * candy-mosaic error of any kind. So the assertion is inverted rather than
     * deleted, and it is inverted rather than relaxed: "fd 0 is not the
     * runner's any more" is a fact worth holding, and a test that merely
     * stopped caring would let the flag repair — or nothing at all — come back
     * silently.
     *
     * WHAT THE FLAG HALF WAS, kept because deleting the reasoning is how the
     * next reader deletes the guard. The shipped repair used to be
     * `stream_set_blocking(\STDIN, false)`, which SETS `O_NONBLOCK` on the open
     * file DESCRIPTION — the thing `fork(2)`/`exec(2)` share, so it reached
     * inherited children while the constant stayed alive. It was refuted twice:
     * once because a flag can be CLEARED by anything else holding that
     * description (`new Tty(null, $injectedTermios)` resolves the null stream to
     * `\STDIN`, and `PosixBackend::restore()`'s `stream_set_blocking(…, true)`
     * then landed on descriptor 0 — MEASURED, three takes, blocked again after
     * `restore()` 3/3), and finally because `O_NONBLOCK` changes when a read
     * RETURNS and not what it returns, so bytes already on the runner's pipe
     * were still read and still prepended to a spawned child's prompt. This
     * repair holds no flag, so neither refutation applies to it.
     *
     * SO THE ORDER-DEPENDENCE IS GONE TOO, and that is a real improvement
     * rather than a side effect. The old flag assertion was deliberately
     * order-DEPENDENT: it was the only thing that went red when someone wrote
     * the next `new Tty(null, $injectedTermios)` mid-run. A closed descriptor
     * cannot be re-opened by an unrelated seam, so this one says the same thing
     * whenever it runs. The `new Tty(null, …)` hazard keeps its own guard —
     * {@see \SugarCraft\Crush\Tests\TtyStreamArgumentCensusTest}, which walks
     * the token stream because a grep for `new Tty(` cannot express the
     * fully-qualified spelling `tests/ChatTest.php` uses, and that spelling is
     * what the old assertion caught on a full run with the other three already
     * repaired.
     */
    public function testTheBootstrapHasReplacedTheRunnersDescriptorZero(): void
    {
        // KNOWN-POSITIVE THROUGH THE SAME PROBES, FIRST (rule 15/E228). Every
        // assertion below this point is a NEGATIVE — "the constant is not a
        // live resource" — and `false` is also what `is_resource()` returns
        // when handed something that was never a stream at all. So the probe
        // is shown answering both ways on handles whose state is known.
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        self::assertTrue(is_resource($pair[0]), 'is_resource() cannot see a LIVE stream, so neither polarity below means anything');
        fclose($pair[0]);
        self::assertFalse(is_resource($pair[0]), 'is_resource() cannot see a CLOSED stream either');
        fclose($pair[1]);

        // THE LIVENESS TEST COMES FIRST, and the order is not cosmetic:
        // `stream_isatty()` THROWS a TypeError on a closed resource, so the tty
        // branch cannot be the outer one any more. Which is itself the shape
        // this whole change is about - a closed descriptor 0 is a state callers
        // have to test for rather than assume away.
        if (is_resource(\STDIN)) {
            // A developer running the suite from a shell keeps their terminal:
            // the bootstrap's guard skips the whole block for a tty. A live
            // descriptor 0 that is NOT a tty means the repair is gone.
            self::assertTrue(
                stream_isatty(\STDIN),
                'descriptor 0 is still live and is not a terminal, so the runner\'s own stdin survived the '
                    . 'bootstrap. tests/bootstrap.php must fclose(\STDIN) and reopen /dev/null onto the '
                    . 'freed descriptor, because that - and not the O_NONBLOCK flag that used to be here - '
                    . 'is what stops bytes already on the runner\'s stdin being read by a spawned '
                    . 'bin/sugarcrush and prepended to its prompt',
            );

            return;
        }

        // AND THE REPLACEMENT IS ACTUALLY THERE. `is_resource()` being false
        // only says the constant is dead; it does not say descriptor 0 was
        // re-occupied, and a bare local in the bootstrap would leave fd 0
        // closed outright (MEASURED, PHP 8.3.6 / PHPUnit 10.5.64, three takes
        // each, that file's include shape reproduced from inside a private
        // method: $GLOBALS => /dev/null 3/3, a bare local => fd 0 closed 3/3).
        self::assertIsResource(
            $GLOBALS['__sugarcrushSuiteStdin'] ?? null,
            'the bootstrap freed descriptor 0 but did not park the replacement handle where it survives '
                . 'the include - PHPUnit includes tests/bootstrap.php from inside a METHOD, so a bare local '
                . 'there is freed on return and closes fd 0 again',
        );

        // Linux-only, so it is a bonus assertion rather than the claim: this
        // box and CI are Linux, and a runner without /proc still gets
        // everything above.
        if (is_dir('/proc/self/fd')) {
            self::assertSame(
                '/dev/null',
                readlink('/proc/self/fd/0'),
                'descriptor 0 was freed but something other than /dev/null landed on it',
            );
        }
    }

    /**
     * THE TTY BRANCH, WHICH THIS BOX CANNOT OTHERWISE REACH — pinned with a
     * real pseudo-terminal rather than left as an untested arm.
     *
     * The repair is guarded by `!stream_isatty(\STDIN)`, and every runner in
     * this tree — CI, this lane, a `vendor/bin/phpunit` from a script — has a
     * non-tty descriptor 0, so the guard's TRUE arm never executes. MEASURED:
     * replacing that condition with `if (true)` and running the four stdin
     * guard classes SURVIVED. An arm no mutation can kill is an arm that is
     * not being tested, and "it leaves a developer's terminal alone" is a
     * promise a suite makes to the one environment it never runs in.
     *
     * So both arms are driven here, in children, through one harness that
     * differs in the descriptor spec alone. `proc_open()` accepts `['pty']` for
     * a single descriptor with ordinary pipes on 1 and 2 — verified on this box,
     * PHP 8.3.6 — which gives a child whose fd 0 is a genuine `/dev/pts/N`
     * slave without having to capture its output through the same terminal.
     *
     * PIPE ARM (the known-positive, and it is what makes the pty arm mean
     * anything): the same script, the same bootstrap, fd 0 a pipe. It must
     * report the descriptor REPLACED. Without it, "the terminal survived" is
     * also what a child that never loaded the bootstrap reports.
     */
    public function testTheBootstrapLeavesARealTerminalsDescriptorZeroAlone(): void
    {
        $pipe = $this->bootstrapUnder(usePty: false);
        self::assertSame('0', $pipe['TTY'] ?? null, "the known-positive arm's fd 0 was a terminal, so it is "
            . "not the non-tty case at all.\n" . $pipe['raw']);
        self::assertSame(
            '0',
            $pipe['LIVE'] ?? null,
            "the child did not replace descriptor 0 with fd 0 a PIPE, so it never ran the bootstrap and the "
                . "terminal arm below proves nothing.\n" . $pipe['raw'],
        );

        $pty = $this->bootstrapUnder(usePty: true);
        self::assertSame(
            '1',
            $pty['TTY'] ?? null,
            "proc_open()'s ['pty'] spec did not give the child a terminal on descriptor 0, so this arm is "
                . "the pipe case again under another name.\n" . $pty['raw'],
        );
        self::assertSame(
            '1',
            $pty['LIVE'] ?? null,
            "tests/bootstrap.php closed a real TERMINAL's descriptor 0. The repair must skip an interactive "
                . "run: fd 0, 1 and 2 are usually one open file description on a terminal, and a developer "
                . "running the suite from a shell would lose their own stdin.\n" . $pty['raw'],
        );
        self::assertStringStartsWith('/dev/pts/', (string) ($pty['FD0'] ?? ''), $pty['raw']);
    }

    /**
     * Load `tests/bootstrap.php` in a child whose fd 0 is a pty or a pipe, and
     * report what it did to that descriptor.
     *
     * THE TWO DESCRIPTOR SPECS ARE SPELLED OUT AT THEIR `proc_open()` CALLS
     * rather than hoisted into one `$spec` variable, and that is a STYLE
     * rather than the requirement this paragraph used to claim it was.
     *
     * WHAT THIS SAID: that the literal spelling is "a requirement rather than
     * a style", because
     * {@see \SugarCraft\Crush\Tests\Support\ChildStderrCaptureTest} "reads the
     * spec as a literal at the call site … and it reports a variable as
     * `unclassified`", which that guard refuses.
     *
     * WHAT IS TRUE NOW, and it was never true of THIS file — the claim was
     * carried over from the general case without being driven against the site
     * it annotates. Two independent measurements, PHP 8.3.6, both by pushing
     * source through {@see \SugarCraft\Crush\Tests\Support\ChildStderrCaptureScanner}
     * itself rather than by reading its diff:
     *
     *  - THE SCANNER RESOLVES A VARIABLE ASSIGNED AN ARRAY LITERAL IN THE SAME
     *    FUNCTION. Hoisting both specs below into `$ptySpec`/`$pipeSpec` and
     *    re-scanning this file reports `captured` for both sites, exactly as
     *    the literal spelling does — not `unclassified`. The `unclassified`
     *    answer is for a spec the scanner cannot FOLLOW: a variable assigned
     *    in another function scope, a variable never assigned, or an opaque
     *    call. Known-answer controls through the same scanner in the same
     *    probe: same-function literal → `captured`, other-function literal →
     *    `unclassified`, same-function `/dev/null` → `discarded`, never
     *    assigned → `unclassified`.
     *  - AND THE OFFENCE-FINDING HALF OF THAT GUARD DOES READ THIS FILE, which
     *    is the half the old sentence should have named.
     *    {@see \SugarCraft\Crush\Tests\Support\ChildStderrCaptureTest::testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites()}
     *    walks only `ChildStderrCaptureTest::SCOPE`, a list of SUBDIRECTORIES
     *    of `tests/`, and this file is at the ROOT, so that one never sees it.
     *    But its sibling
     *    {@see \SugarCraft\Crush\Tests\Support\ChildStderrCaptureTest::testNoDirectoryWithAnUnguardedSpawnIsUnaccountedFor()}
     *    walks ALL of `tests/` and says so — it simply only flags OFFENDING
     *    sites, and both spellings here are `captured`. So the reason the
     *    hoisted form survives is not that nothing reads this file; it is that
     *    there is no offence in either form.
     *
     * WHY THE LITERAL SPELLING STILL EARNS ITS PLACE, now that it is not being
     * held up by a guard: the resolution above is bounded by the enclosing
     * FUNCTION, and that bound is invisible at the call site. A later reader
     * lifting these specs to a property or a class constant — the obvious next
     * refactor once they are variables at all — crosses that bound and turns
     * both sites `unclassified`, which the totality guard above DOES refuse.
     * Spelling them at the call site keeps the shape the scanner can always
     * read. That is a reason to prefer it, not a reason it cannot be changed,
     * and this paragraph is now the difference between the two.
     *
     * Both shapes below send fd 2 to a pipe this method reads back into `raw`.
     *
     * @return array{TTY?: string, LIVE?: string, FD0?: string, raw: string}
     */
    private function bootstrapUnder(bool $usePty): array
    {
        $root = \dirname(__DIR__);
        $script = "<?php\ndeclare(strict_types=1);\n"
            // The tty answer is read BEFORE the bootstrap, because afterwards
            // stream_isatty() throws on the closed constant in the pipe arm.
            . '$tty = stream_isatty(\STDIN) ? "1" : "0";' . "\n"
            . 'require ' . var_export($root . '/tests/bootstrap.php', true) . ";\n"
            . 'printf("TTY=%s LIVE=%s FD0=%s\n", $tty, is_resource(\STDIN) ? "1" : "0", '
                . '(string) (@readlink("/proc/self/fd/0") ?: "-"));' . "\n";

        $file = tempnam(sys_get_temp_dir(), 'sc_tty_probe_' . getmypid() . '_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, $script);

        $env = [
            'TMPDIR' => (string) (getenv('TMPDIR') ?: sys_get_temp_dir()),
            'HOME' => $this->home,
            'PATH' => (string) getenv('PATH'),
        ];

        $process = $usePty
            ? proc_open(
                [\PHP_BINARY, $file],
                [0 => ['pty'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->home,
                $env,
            )
            : proc_open(
                [\PHP_BINARY, $file],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->home,
                $env,
            );
        self::assertIsResource($process);

        $out = (string) @stream_get_contents($pipes[1]);
        $err = (string) @stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        proc_close($process);

        $found = ['raw' => trim($out . "\n" . $err)];
        if (preg_match('/TTY=(\d) LIVE=(\d) FD0=(\S+)/', $out, $m) === 1) {
            $found['TTY'] = $m[1];
            $found['LIVE'] = $m[2];
            $found['FD0'] = $m[3];
        }

        return $found;
    }

    /** Remove a directory tree this test created, contents and all. */
    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);

                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * A script that spawns `bin/sugarcrush -p` and reports how that went.
     *
     * `$withBootstrap` is the only difference between control and treatment.
     * TMPDIR is passed explicitly on BOTH so the control does not sweep the
     * machine's real temp directory — the bootstrap is what normally sets it,
     * and the control is the run that does not have one.
     */
    private function script(bool $withBootstrap, int $bound): string
    {
        $root = \dirname(__DIR__);
        $require = $withBootstrap
            ? 'require ' . var_export($root . '/tests/bootstrap.php', true) . ';'
            : 'require ' . var_export($root . '/vendor/autoload.php', true) . ';';

        $command = sprintf(
            'cd %s && TMPDIR=%s HOME=%s timeout -s KILL %d %s %s -p %s >/dev/null 2>&1',
            escapeshellarg($this->home),
            escapeshellarg((string) (getenv('TMPDIR') ?: sys_get_temp_dir())),
            escapeshellarg($this->home),
            $bound,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/bin/sugarcrush'),
            escapeshellarg('hi'),
        );

        return "<?php\ndeclare(strict_types=1);\n"
            . $require . "\n"
            . '$start = microtime(true);' . "\n"
            . '$out = [];' . "\n"
            . '$rc = 0;' . "\n"
            . 'exec(' . var_export($command, true) . ', $out, $rc);' . "\n"
            . 'printf("ELAPSED=%.3f RC=%d\n", microtime(true) - $start, $rc);' . "\n";
    }

    /**
     * Run one script with descriptor 0 wired to a pipe THIS process holds open
     * and never writes to — the shape a supervising harness produces.
     *
     * @return array{elapsed: float, rc: int, raw: string}
     */
    private function spawnWithHeldOpenStdin(string $script): array
    {
        $file = tempnam(sys_get_temp_dir(), 'sc_stdin_probe_' . getmypid() . '_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, $script);

        $process = proc_open(
            [PHP_BINARY, $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        // $pipes[0] is deliberately left open and unwritten for the whole run.
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame(1, preg_match('/^ELAPSED=([\d.]+) RC=(-?\d+)$/m', $out, $m), "no report from the probe:\n" . $out . $err);

        return ['elapsed' => (float) $m[1], 'rc' => (int) $m[2], 'raw' => trim($out)];
    }
}
