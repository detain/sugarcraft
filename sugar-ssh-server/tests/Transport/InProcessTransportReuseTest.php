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
 * test here can hang.
 */
final class InProcessTransportReuseTest extends TestCase
{
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
