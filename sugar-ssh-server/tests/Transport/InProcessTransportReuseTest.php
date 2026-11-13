<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\InProcessTransport;

/**
 * Transport-reuse hygiene pins (audits sugar-ssh-server-016 and -051;
 * the stray-docblock half, -034, is a comment deletion no test can
 * bite, and -051's fix is the documented one-command-per-connection
 * wording beside the fallback).
 *
 * The keepalive beat is armed per serve() entry, so a transport
 * instance that outlives one run — the panel-host shape the
 * TransportAware contract explicitly anticipates — must neither keep
 * counting the pre-pump gap as idle time (016-clock) nor keep wiring
 * the previous run's callback into the next pump (016-clearing).
 *
 * Runs are driven through an injected fake PtySystem (the
 * InProcessTransportStalePidTest recipe) so no real PTY is required;
 * every fake child settles within a few hundred milliseconds, so no
 * fake-driven test here can hang. The one exception is the
 * PTY-gated end-to-end pin at the bottom, which skips on hosts
 * without ext-ffi / ext-pcntl / `/dev/ptmx` exactly like the lib's
 * other real-PTY tests.
 */
final class InProcessTransportReuseTest extends TestCase
{
    private const RUNCHILD_FIXTURE = __DIR__ . '/_fixtures/runchild.php';

    private const GROUPSENTRY_FIXTURE = __DIR__ . '/_fixtures/groupsentry.php';

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx unreadable on this host.');
        }
        if (!\is_file(self::RUNCHILD_FIXTURE)) {
            $this->markTestSkipped('runchild fixture missing: ' . self::RUNCHILD_FIXTURE);
        }
    }

    /**
     * 020-end-to-end: the shipped pump-idle -> keepalive beat -> NUL
     * write path, observed through a REAL PTY for the first time.
     * A line discipline put into non-canonical raw echo mode
     * (`stty -icanon -echoctl echo`) reflects every byte written to
     * the master straight back to the master reader — canonical
     * ECHOCTL would render NUL as "^@" two bytes, so the flags are
     * load-bearing for the single-byte assertion below. With the
     * fixture armed for a 1 s beat, an NUL must resurface on the
     * forwarded output around one interval after the pump first
     * goes quiet, while the child never reads and writes nothing.
     */
    public function testTheArmedBeatEchoesANulThroughARealPty(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/stty') || !\is_executable('/bin/sleep') || !\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/stty, /bin/sleep and /bin/sh required.');
        }

        $previous = \getenv('WISH_FIXTURE_KEEPALIVE');
        \putenv('WISH_FIXTURE_KEEPALIVE=1');
        $pipes = [];
        try {
            $proc = \proc_open(
                [PHP_BINARY, self::RUNCHILD_FIXTURE, '80', '24',
                    '/bin/sh', '-c', 'stty -icanon -echoctl echo; sleep 3'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($proc, 'proc_open of runchild fixture failed');
            \stream_set_blocking($pipes[1], false);

            // The beat anchors on the first idle tick (~50 ms into
            // the pump) and sends one interval later; 2.5 s is a
            // generous window (a missed send lands on failure, and
            // nothing here waits past the deadline).
            $captured = '';
            $deadline = \microtime(true) + 2.5;
            while (\microtime(true) < $deadline) {
                $chunk = @\fread($pipes[1], 4096);
                if (\is_string($chunk) && $chunk !== '') {
                    $captured .= $chunk;
                    if (\str_contains($captured, "\x00")) {
                        break;
                    }
                }
                \usleep(20_000);
            }
        } finally {
            \putenv(false === $previous ? 'WISH_FIXTURE_KEEPALIVE=' : 'WISH_FIXTURE_KEEPALIVE=' . $previous);
            if (isset($proc) && \is_resource($proc)) {
                // Client disconnect first (the graceful path the
                // stdin-EOF tests already pin); terminate is only
                // the bounded fallback so this test can never hang.
                @\fclose($pipes[0]);
                $status = \proc_get_status($proc);
                if ($status['running']) {
                    \proc_terminate($proc, 9);
                }
                @\fclose($pipes[1]);
                $stderrBytes = @\stream_get_contents($pipes[2]);
                @\fclose($pipes[2]);
                \proc_close($proc);
            }
        }

        $this->assertStringContainsString(
            "\x00",
            $captured,
            'armed 1 s keepalive beat must push one NUL through the real PTY and back; captured='
            . \bin2hex($captured) . ' stderr=' . \var_export((string) ($stderrBytes ?? ''), true),
        );
    }

    /**
     * 016-clock: the elapsed gate anchors on the callback's FIRST
     * fire — the pump loop's first idle observation — not at arm
     * time. A 45 s auth between serve() entry (arm) and the first
     * tick must not burn the first interval: the old code armed
     * `$lastSent` at registration, so the first idle tick after a
     * slow auth fired a heartbeat instantly instead of one full
     * interval after the loop went quiet.
     */
    public function testTheBeatAnchorsItsClockOnTheFirstIdleTick(): void
    {
        $served = null;
        $sendTicks = [];
        $beat = $this->buildBeat(
            1,
            [1045.0, 1045.999, 1046.0, 1046.999, 1047.0],
            $served,
            $sendTicks,
        );

        for ($i = 0; $i < 5; $i++) {
            $beat();
        }

        // Fire 1 (t=1045, 45 s after the arm) only anchors: a stale
        // arm-time clock would have sent here. Fire 3 (a full second
        // after the anchor) starts the beat; fire 5 repeats it.
        $this->assertSame([1046.0, 1047.0], $sendTicks);
    }

    /**
     * 016-clock, threshold leg: two fires inside one interval write
     * zero bytes — the elapsed-gate arithmetic micro-pinned without a
     * PTY (the seam later threshold pins reuse).
     */
    public function testTwoFiresInsideOneIntervalWriteNothing(): void
    {
        $served = null;
        $sendTicks = [];
        $beat = $this->buildBeat(1, [100.0, 100.9], $served, $sendTicks);

        $beat();
        $beat();

        $this->assertSame([], $sendTicks);
    }

    /**
     * 016-clearing: every pumped run ends with the callback reset to
     * null, beside the child-pid reset in the teardown finally.
     */
    public function testTheCallbackIsClearedWhenAPumpedRunEnds(): void
    {
        $transport = new InProcessTransport($this->settledSystem());
        $transport->armKeepalive(60);
        $this->assertNotNull($this->callbackOf($transport), 'arming must store the beat');

        $status = $this->driveRun($transport);

        $this->assertSame(0, $status);
        $this->assertNull($this->callbackOf($transport), 'runChild() teardown must drop the armed beat');
    }

    /**
     * 016-clearing, end-to-end shape over ONE transport instance
     * across two runs: run A's registered beat fires (and writes a
     * `\0` through A's live master); run B, armed with nothing, must
     * neither fire A's closure again nor let it write into B's child.
     */
    public function testALaterRunDoesNotFireThePreviousRunsBeat(): void
    {
        $fires = 0;
        $doneA = new \stdClass();
        $doneA->done = false;
        $masterA = new RecordingMaster();
        $masterB = new RecordingMaster();

        $transport = new InProcessTransport($this->twoRunSystem($masterA, $masterB, $doneA));
        $spy = function () use (&$fires, $doneA, $transport): void {
            $fires++;
            $doneA->done = true; // A's fake child settles on the next lap
            $transport->pty()->write("\0");
        };
        $transport->setKeepaliveCallback($spy);

        $this->assertSame(0, $this->driveRun($transport));
        $this->assertSame(1, $fires, 'the registered beat must fire on the pump idle tick');
        $this->assertContains("\0", $masterA->writes, 'run A: the beat wrote through the live master');

        // Run B on the SAME instance, no arming whatsoever: the
        // teardown clear leaves the pump without any onIdle, and B's
        // child settles on its own wall-clock deadline.
        $this->assertSame(0, $this->driveRun($transport));
        $this->assertSame(1, $fires, "a later run must not fire the previous run's beat");
        $this->assertNotContains("\0", $masterB->writes, 'a stale closure must not reach a child that never armed one');
    }

    /**
     * 018 end-to-end: the final rung of the teardown ladder reaches
     * the child's PROCESS GROUP. The fixture child ignores SIGHUP
     * (the `trap`), backgrounds a `sleep 30` — non-interactive sh has
     * no job control, so the sleeper shares the leader's group — and
     * then blocks in a second foreground sleep. The client hangup
     * ends the pump, the ladder's SIGHUP bounces off the trap, and
     * the escalated SIGKILL must sweep leader AND background sleeper.
     * Before this step the direct pid-kill felled the leader and the
     * background sleep kept breathing its full 30 s behind a dead
     * session.
     */
    public function testTheFinalEscalationSweepsTheProcessGroup(): void
    {
        $this->requirePtySyscalls();
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The grandchild probe reads /proc.');
        }
        if (!\is_executable('/bin/sh') || !\is_executable('/bin/sleep')) {
            $this->markTestSkipped('/bin/sh and /bin/sleep required.');
        }

        $pidFile = \tempnam(\sys_get_temp_dir(), 'ssrv16');
        $this->assertIsString($pidFile);
        \unlink($pidFile); // the child's redirect creates it fresh

        $supervisor = null;
        $pipes = [];
        $grandchild = 0;
        $stderrTail = '';
        $settled = true;
        $contained = false;
        try {
            $supervisor = \proc_open(
                [PHP_BINARY, self::RUNCHILD_FIXTURE, '80', '24',
                    '/bin/sh', '-c',
                    'trap "" HUP; sleep 30 & printf "%s" $! > ' . \escapeshellarg($pidFile) . '; sleep 30'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($supervisor, 'proc_open of runchild fixture failed');

            $published = \microtime(true) + 5.0;
            while (\microtime(true) < $published) {
                $raw = @\file_get_contents($pidFile);
                if (\is_string($raw) && \trim($raw) !== '') {
                    $grandchild = (int) \trim($raw);
                    break;
                }
                \usleep(20_000);
            }
            $this->assertGreaterThan(0, $grandchild, 'fixture child never published its backgrounded sleep pid');

            // The client hangs up; the pump's stdin-EOF grace ends the
            // run and the ladder starts swinging.
            @\fclose($pipes[0]);
            $pipes[0] = null;

            $endDeadline = \microtime(true) + 6.0;
            while (\microtime(true) < $endDeadline) {
                $settled = !(\proc_get_status($supervisor)['running']);
                if ($settled) {
                    break;
                }
                \usleep(20_000);
            }

            $sweepDeadline = \microtime(true) + 2.5;
            while (\microtime(true) < $sweepDeadline) {
                $contained = $this->groupMemberIsDown($grandchild);
                if ($contained) {
                    break;
                }
                \usleep(20_000);
            }
        } finally {
            if ($grandchild > 0 && !$this->groupMemberIsDown($grandchild)) {
                // Belt: a FAILED test must not leak a 30 s sleeper.
                \SugarCraft\Pty\Libc::kill($grandchild, \defined('SIGKILL') ? \SIGKILL : 9);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
            if (\is_resource($supervisor)) {
                if (\proc_get_status($supervisor)['running']) {
                    \proc_terminate($supervisor, 9);
                }
                \proc_close($supervisor);
            }
            @\unlink($pidFile);
        }

        $this->assertTrue($settled, 'supervisor must exit once its session group has fallen');
        $this->assertTrue(
            $contained,
            'group-escalated SIGKILL must sweep the backgrounded descendant; the old pid-scoped'
            . ' kill felled the leader and left sleep ' . $grandchild . ' running',
        );
    }

    /**
     * Self-preservation guard, fallback leg: a "child" reporting THIS
     * process's pid — a test fake or corrupt state — must not turn the
     * sweep into `kill(-getmypid())`. From this non-leader runner a
     * missing guard would merely ESRCH, so the self-destruction itself
     * is forced by the setsid sentry fixture in the next test; here the
     * pin is that the declined sweep falls through to the direct
     * Child::kill() arm, in ladder order.
     */
    public function testTheSweepRefusesTheSupervisorsOwnPidAndFallsBackToTheDirectArm(): void
    {
        $kills = new \stdClass();
        $kills->signals = [];
        $child = new class ($kills) implements Child {
            public function __construct(private \stdClass $kills) {}
            public function pid(): int { return \getmypid(); }
            public function exited(): bool { return false; }
            public function wait(): int { return 9; }
            public function exitCode(): ?int { return 9; }
            public function kill(int $signal): void { $this->kills->signals[] = $signal; }
        };
        $transport = new InProcessTransport($this->singleRunSystem(new RecordingMaster(), $child));

        // stdin reads EOF as soon as the pump looks at it: its peer is
        // closed before the run starts, while the handle itself stays a
        // valid resource for stream_set_blocking().
        [$stdin, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fclose($peer);
        $stdout = \fopen('php://memory', 'w+b');
        try {
            $status = $transport->runChild($this->session(), ['/bin/true'], null, $stdin, $stdout);
        } finally {
            \fclose($stdin);
            \fclose($stdout);
        }

        $this->assertSame(9, $status, 'the fake wait() code must come back once the ladder has run');
        $this->assertSame(
            [\defined('SIGHUP') ? \SIGHUP : 1, \defined('SIGKILL') ? \SIGKILL : 9],
            $kills->signals,
            'the guarded sweep must fall through to the direct Child::kill() arm, in ladder order',
        );
    }

    /**
     * 018 guard FORCE: the sentry fixture makes its own process both
     * session and group leader (posix_setsid) and then drives the same
     * ladder with a fake child reporting its own pid — the corrupt
     * shape the guard exists for. With the guard in place it declines
     * the sweep, falls back to Child::kill(), and exits 0. With the
     * guard removed, `kill(-pid)` lands on the sentry's own group and
     * it dies of SIGKILL ('signaled'), which the assertion below
     * catches from the outside — the real test runner is never the
     * target of the experiment.
     */
    public function testTheGroupSweepNeverTargetsAGroupLeaderRunningAsItsOwnProcess(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only.');
        }
        if (!\function_exists('posix_setsid')) {
            $this->markTestSkipped('ext-posix is required for the sentry.');
        }
        if (!\is_file(self::GROUPSENTRY_FIXTURE)) {
            $this->markTestSkipped('sentry fixture missing: ' . self::GROUPSENTRY_FIXTURE);
        }

        $pipes = [];
        $proc = \proc_open(
            [PHP_BINARY, self::GROUPSENTRY_FIXTURE],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc, 'proc_open of the sentry fixture failed');
        @\fclose($pipes[0]);

        try {
            $deadline = \microtime(true) + 20.0;
            $status = \proc_get_status($proc);
            while ($status['running'] && \microtime(true) < $deadline) {
                \usleep(25_000);
                $status = \proc_get_status($proc);
            }
            $elapsed = 20.0 - \max(0.0, $deadline - \microtime(true));
            $stderr = (string) @\stream_get_contents($pipes[2]);
        } finally {
            if (\proc_get_status($proc)['running']) {
                \proc_terminate($proc, 9);
            }
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            \proc_close($proc);
        }

        $this->assertFalse(
            $status['running'],
            'the sentry must settle its ladder (stdin grace + full escalation) in under 20 s; it ran '
            . \round($elapsed, 1) . ' s',
        );
        $this->assertFalse(
            (bool) $status['signaled'],
            'the sentry died of a signal: the group sweep targeted its own group — the self-'
            . 'preservation guard is gone (exitcode=' . $status['exitcode'] . ' stderr='
            . \trim($stderr) . ')',
        );
        if ((int) $status['exitcode'] === 3) {
            $this->markTestSkipped('sentry: posix_setsid failed at runtime.');
        }
        $this->assertSame(
            0,
            (int) $status['exitcode'],
            'sentry must survive-and-fall-back (0); 2=wrong kill record 4=ladder did not settle; stderr='
            . \trim($stderr),
        );
    }

    /**
     * A swept process is either gone (pid slot freed, reaped) or a
     * zombie (killed, reaper pending). Anything else — S/R/D — is
     * still running. comm is parenthesised; the state letter follows
     * the LAST ')'.
     */
    private function groupMemberIsDown(int $pid): bool
    {
        $stat = @\file_get_contents("/proc/{$pid}/stat");
        if (!\is_string($stat) || $stat === '') {
            return true;
        }
        $tail = \strrchr($stat, ')');

        return \is_string($tail) && \str_starts_with(\ltrim(\substr($tail, 1)), 'Z');
    }

    // ----------------------------------------------------------------
    // seams
    // ----------------------------------------------------------------

    /**
     * Build the private beat factory with a scripted clock and a
     * recording emitter — the `$clock`/`$send` seams audit -016 asked
     * for, which let the elapsed-gate arithmetic be pinned without a
     * real PTY or wall-clock waits.
     *
     * @param list<float>  $ticks     scripted "now" values, one per fire
     * @param float|null   $served    last tick the clock handed out
     * @param list<float>  $sendTicks receives the tick of every send
     */
    private function buildBeat(int $interval, array $ticks, ?float &$served, array &$sendTicks): \Closure
    {
        $method = new \ReflectionMethod(InProcessTransport::class, 'keepaliveBeat');
        $method->setAccessible(true);

        $queue = $ticks;
        $clock = function () use (&$queue, &$served): float {
            $tick = \array_shift($queue);
            $this->assertIsFloat($tick, 'scripted clock exhausted');
            return $served = $tick;
        };
        $send = static function () use (&$served, &$sendTicks): void {
            $sendTicks[] = (float) $served;
        };

        return $method->invoke(new InProcessTransport(), $interval, $clock, $send);
    }

    private function callbackOf(InProcessTransport $transport): ?\Closure
    {
        $prop = new \ReflectionProperty($transport, 'keepaliveCallback');
        $prop->setAccessible(true);
        return $prop->getValue($transport);
    }

    /**
     * One runChild() with stdio that keeps the pump honest: stdin is
     * a socket pair whose peer stays open for the whole run (no
     * premature EOF), stdout is memory. The run ends when the fake
     * child says so.
     */
    private function driveRun(InProcessTransport $transport): int
    {
        [$stdin, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $stdout = \fopen('php://memory', 'w+b');
        try {
            return $transport->runChild($this->session(), ['/bin/true'], null, $stdin, $stdout);
        } finally {
            \fclose($stdin);
        }
    }

    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    // ----------------------------------------------------------------
    // fake PTY systems (InProcessTransportStalePidTest recipe,
    // extended with write-recording masters and bounded settles)
    // ----------------------------------------------------------------

    /** Child already exited + immediately-EOF master: instant settle. */
    private function settledSystem(): PtySystem
    {
        $child = new class implements Child {
            public function pid(): int { return \getmypid(); }
            public function exited(): bool { return true; }
            public function wait(): int { return 0; }
            public function exitCode(): ?int { return 0; }
            public function kill(int $signal): void {}
        };
        return $this->singleRunSystem(new RecordingMaster(closedPeer: true), $child);
    }

    /**
     * A system that serves run A's pair on the first open() and run
     * B's on every later call — so both runs share ONE transport
     * instance, which is the point of the reuse test. A's child is
     * alive until the registered beat fires ($doneA->done flipped by
     * the spy); B's child settles on a 0.25 s wall-clock deadline, no
     * callbacks involved.
     */
    private function twoRunSystem(RecordingMaster $masterA, RecordingMaster $masterB, \stdClass $doneA): PtySystem
    {
        $childA = new class ($doneA) implements Child {
            public function __construct(private \stdClass $done) {}
            public function pid(): int { return \getmypid(); }
            public function exited(): bool { return (bool) $this->done->done; }
            public function wait(): int { return 0; }
            public function exitCode(): ?int { return 0; }
            public function kill(int $signal): void {}
        };
        $childB = new class implements Child {
            private ?float $started = null;
            public function pid(): int { return \getmypid(); }
            public function exited(): bool
            {
                // Timed from the FIRST poll (run B's own start), not
                // from construction: even under load the pump sees a
                // handful of idle laps before this settles, so a
                // stale-closure regression cannot hide behind an
                // early settle.
                $this->started ??= \microtime(true);
                return \microtime(true) - $this->started >= 0.3;
            }
            public function wait(): int { return 0; }
            public function exitCode(): ?int { return 0; }
            public function kill(int $signal): void {}
        };

        return new FakePtySystem(
            $this->singleRunSystem($masterA, $childA),
            $this->singleRunSystem($masterB, $childB),
        );
    }

    /** One pair for a single run. */
    private function singleRunSystem(RecordingMaster $master, Child $child): PtySystem
    {
        $slave = new class ($child) implements SlavePty {
            public function __construct(private Child $child) {}
            public function path(): string { return '/dev/null'; }
            public function spawn(array $cmd, ?array $env = null, ?int $cols = null, ?int $rows = null, bool $controllingTerminal = false): Child
            {
                return $this->child;
            }
        };

        return new class ($master, $slave) implements PtySystem {
            public function __construct(private MasterPty $master, private SlavePty $slave) {}
            public function open(int $cols = 80, int $rows = 24): PtyPair
            {
                return new FakePtyPair($this->master, $this->slave);
            }
            public function capabilities(): array { return ['pty' => false, 'termios' => false, 'signal' => false]; }
        };
    }
}

/** Delegates open() to system #1 on the first call, #2 thereafter. */
final class FakePtySystem implements PtySystem
{
    private int $seq = 0;

    public function __construct(
        private PtySystem $first,
        private PtySystem $second,
    ) {}

    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        $this->seq++;
        return $this->seq === 1 ? $this->first->open($cols, $rows) : $this->second->open($cols, $rows);
    }

    public function capabilities(): array { return ['pty' => false, 'termios' => false, 'signal' => false]; }
}

/** Fixed (master, slave) pair for the fakes. */
final class FakePtyPair implements PtyPair
{
    public function __construct(private MasterPty $master, private SlavePty $slave) {}
    public function master(): MasterPty { return $this->master; }
    public function slave(): SlavePty { return $this->slave; }
}

/**
 * A master whose write() calls are recorded verbatim. Reads come from
 * a real socket pair so stream_select() behaves normally against the
 * pump: with the peer closed the read side is immediate EOF (instant
 * settle); with the peer held (default) the loop idles on the 50 ms
 * select timeout — PosixPump's default tick — and fires onIdle.
 */
final class RecordingMaster implements MasterPty
{
    /** @var list<string> */
    public array $writes = [];

    /** @var resource */
    public $stream;

    /**
     * The far end of the pair, held OPEN by default: with no writer
     * left, reads hit EOF instantly and the pump settles before its
     * first idle tick — the whole point of these runs is the tick.
     *
     * @var resource|null
     */
    public $peer;

    private bool $closed = false;

    public function __construct(bool $closedPeer = false)
    {
        [$mine, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($closedPeer) {
            \fclose($peer);
            $peer = null;
        }
        $this->stream = $mine;
        $this->peer = $peer;
    }

    public function fd(): int
    {
        return -1;
    }

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        $bytes = @\fread($this->stream, $len);
        return $bytes === false ? null : $bytes;
    }

    public function write(string $bytes): int
    {
        $this->writes[] = $bytes;
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}

    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }

    public function stream(): mixed { return $this->stream; }

    public function close(): void
    {
        $this->closed = true;
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
        if (\is_resource($this->peer)) {
            \fclose($this->peer);
        }
    }

    public function isClosed(): bool { return $this->closed; }
}
