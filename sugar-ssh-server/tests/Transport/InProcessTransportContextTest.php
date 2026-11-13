<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Transport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\SshServer\CancellationException;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\DeadlineExceededException;
use SugarCraft\SshServer\Middleware\Spawn;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\ChildSpawner;
use SugarCraft\SshServer\Transport\InProcessTransport;

/**
 * Context blindness at the pump (audit sugar-ssh-server-017).
 *
 * A context handed to `runChild()` is a bound: the chain deadline is
 * armed as the pump's own absolute bound, a pure cancel closes the
 * master at the next idle tick, and either way the run reaps the child,
 * records its kill status, and then raises the context's own error
 * rather than a status that would masquerade as an exit the user's
 * program chose. Without a context the run stays unbounded — the
 * sshd-role contract, where SIGHUP at session end owns the child.
 *
 * Everything here runs against injected fakes (the
 * InProcessTransportStalePidTest recipe): a never-exiting child kept
 * alive on an open socket peer would be a hang if the bound machinery
 * regressed, but every bounded path in these tests is decided by the
 * pump's own deadline or an idle-tick close, so the worst case is the
 * assertion timeout, never an orphan.
 */
final class InProcessTransportContextTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    /**
     * A slave whose open() itself is forbidden — the entry gate must
     * refuse a dead context before any PTY is allocated.
     */
    private function systemThatMustNotOpen(): PtySystem
    {
        return new class implements PtySystem {
            public function open(int $cols = 80, int $rows = 24): PtyPair
            {
                throw new \LogicException('a done context must not reach the PTY allocation');
            }

            public function capabilities(): array
            {
                return ['pty' => false, 'termios' => false, 'signal' => false];
            }
        };
    }

    public function testAnExpiredContextRefusesBeforeThePtyIsOpened(): void
    {
        $transport = new InProcessTransport($this->systemThatMustNotOpen());
        $ctx = Context::background()->withDeadline(new \DateTimeImmutable('-1 second'));

        $this->expectException(DeadlineExceededException::class);
        $transport->runChild($this->session(), ['/bin/true'], null, ctx: $ctx);
    }

    public function testThePumpBoundDerivesRemainingMicrosecondsFromTheChain(): void
    {
        $bound = new \ReflectionMethod(InProcessTransport::class, 'pumpBoundUs');
        $bound->setAccessible(true);

        // No context, or a live one without a deadline: unbounded stays
        // the contract — no timer is armed and no option object churns.
        $this->assertNull($bound->invoke(null, null, 1000.0));
        $this->assertNull($bound->invoke(null, Context::background(), 1000.0));

        $inTwoAndAHalf = Context::background()->withDeadline(new \DateTimeImmutable('@1002.5'));
        $this->assertSame(2_500_000, $bound->invoke(null, $inTwoAndAHalf, 1000.0));

        // An ancestor's earlier deadline binds first (done() expires on
        // it, so the pump must not outlive it).
        $ancestorHolds = $inTwoAndAHalf
            ->withValue('a', '1')
            ->withDeadline(new \DateTimeImmutable('@1009.0'));
        $this->assertSame(2_500_000, $bound->invoke(null, $ancestorHolds, 1000.0));

        // Expired derives to 1 microsecond, never 0 or null: PumpOptions
        // rejects a non-positive bound, and "fire at the next loop head"
        // is the honest reading of an already-passed deadline.
        $expired = Context::background()->withDeadline(new \DateTimeImmutable('@999.0'));
        $this->assertSame(1, $bound->invoke(null, $expired, 1000.0));
    }

    public function testTheIdleTickHangsUpTheChildOnceWhenTheContextDies(): void
    {
        $transport = new InProcessTransport($this->systemThatMustNotOpen());
        $ctx = Context::background()->withCancelable();
        $beats = 0;
        $hangups = 0;

        $guard = new \ReflectionMethod(InProcessTransport::class, 'idleTickGuard');
        $guard->setAccessible(true);
        $tick = $guard->invoke($transport, $ctx, static function () use (&$beats): void {
            $beats++;
        }, static function () use (&$hangups): void {
            $hangups++;
        });
        $this->assertNotNull($tick);

        // Live context: the beat runs and nobody is hung up.
        $tick();
        $this->assertSame(1, $beats);
        $this->assertSame(0, $hangups);

        // Dead context: one SIGHUP-equivalent — the same courtesy sshd
        // extends at session end — and the beat is short-circuited on a
        // dying session.
        $this->assertTrue($ctx->cancel());
        $tick();
        $this->assertSame(1, $beats, 'a dying session must not get another keepalive NUL');
        $this->assertSame(1, $hangups);

        // One-shot: a child ignoring the first hangup must not be
        // shouted at every tick; the deadline bound or the teardown
        // ladder owns escalation.
        $tick();
        $tick();
        $this->assertSame(1, $hangups);
        $this->assertSame(1, $beats);
    }

    public function testTheGuardIsNothingAtAllWhenThereIsNothingToPoll(): void
    {
        $transport = new InProcessTransport($this->systemThatMustNotOpen());
        $guard = new \ReflectionMethod(InProcessTransport::class, 'idleTickGuard');
        $guard->setAccessible(true);

        $this->assertNull($guard->invoke($transport, null, null, static function (): void {}));
    }

    public function testADeadlineMidRunEndsThePumpReapsTheChildAndRaisesTheDeadlineError(): void
    {
        $kills = new \stdClass();
        $kills->list = [];
        $child = $this->neverExitingChild($kills);
        $master = $this->liveMaster();
        $transport = new InProcessTransport($this->systemFor($master, $child));
        $session = $this->session();

        $stdin = \fopen('php://memory', 'r+');
        $this->assertIsResource($stdin);
        $stdout = \fopen('php://memory', 'w+');
        $this->assertIsResource($stdout);

        $started = \microtime(true);
        try {
            $transport->runChild(
                $session,
                ['/bin/sleep', '30'],
                null,
                $stdin,
                $stdout,
                Context::background()->withDeadline(new \DateTimeImmutable('+400 milliseconds')),
            );
            $this->fail('the pump bound must end this run');
        } catch (DeadlineExceededException) {
            $elapsed = \microtime(true) - $started;
            $this->assertLessThan(3.0, $elapsed, 'the bound must fire, not a hang');
            // The child never exits on its own, so it must have been
            // hung up (the guard's tick or the ladder) and escalated at
            // least once. The exact sequence is a race between the
            // loop-head deadline check and the ~50 ms idle tick: either
            // may deliver the first SIGHUP, and SIGKILL follows when the
            // fake ignores them all. What must never happen is an empty
            // kill log.
            $this->assertContains(
                \defined('SIGHUP') ? \SIGHUP : 1,
                $kills->list,
                'the bound must reach the child through guard or ladder',
            );
            $this->assertNotEmpty($kills->list);
            // The session learns the kill status BEFORE the abort is
            // raised — observability first, control flow second.
            $this->assertSame(143, $session->exitStatus());
        } finally {
            \fclose($stdin);
            \fclose($stdout);
        }
    }

    public function testACancelMidRunHangsUpTheChildAndRaisesTheCancellation(): void
    {
        $kills = new \stdClass();
        $kills->list = [];
        // The child notices its session die from the inside: every loop
        // head polls exited(), so the third poll stands in for a cancel
        // arriving while the pump is parked in select.
        $polls = new \stdClass();
        $polls->n = 0;
        $dead = new \stdClass();
        $dead->is = false;
        $ctx = Context::background()->withCancelable();
        $child = new class ($ctx, $kills, $polls, $dead) implements Child {
            public function __construct(
                private Context $ctx,
                private \stdClass $kills,
                private \stdClass $polls,
                private \stdClass $dead,
            ) {}

            public function pid(): int { return 4242; }

            public function exited(): bool
            {
                if ($this->dead->is) {
                    return true;
                }
                $this->polls->n++;
                if ($this->polls->n === 3) {
                    $this->ctx->cancel();
                }

                return false;
            }

            public function wait(): int { return 0; }
            public function exitCode(): ?int { return 0; }

            public function kill(int $signal): void
            {
                $this->kills->list[] = $signal;
                // A shell-shaped child: SIGHUP is exactly what ends it.
                if ($signal === (\defined('SIGHUP') ? \SIGHUP : 1)) {
                    $this->dead->is = true;
                }
            }
        };
        $master = $this->liveMaster();
        $transport = new InProcessTransport($this->systemFor($master, $child));
        $session = $this->session();

        $stdin = \fopen('php://memory', 'r+');
        $this->assertIsResource($stdin);
        $stdout = \fopen('php://memory', 'w+');
        $this->assertIsResource($stdout);

        $started = \microtime(true);
        try {
            $transport->runChild($session, ['/bin/sleep', '30'], null, $stdin, $stdout, $ctx);
            $this->fail('the idle-tick guard must end this run');
        } catch (CancellationException) {
            $this->assertLessThan(3.0, \microtime(true) - $started, 'the guard must fire, not a hang');
            $this->assertSame(
                [\defined('SIGHUP') ? \SIGHUP : 1],
                $kills->list,
                'one hangup — the child died to it, so the ladder must stay sheathed',
            );
            // The pump unwound through the loop-head exited() check, the
            // ordinary stop — the session records the child's own code,
            // and the CancellationException the test caught proves the
            // abort still outranks it.
            $this->assertSame(0, $session->exitStatus());
        } finally {
            \fclose($stdin);
            \fclose($stdout);
        }
    }

    public function testSpawnThreadsTheContextThroughTheConcreteSpawnerDoor(): void
    {
        // Production path, end to end (audit 017): Spawn holds the
        // ChildSpawner contract, narrows with the instanceof its own
        // interface file prescribes, and the real transport's bound
        // machinery fires — a DeadlineExceededException surfacing
        // through handle() is the evidence the context arrived.
        $kills = new \stdClass();
        $kills->list = [];
        $child = $this->neverExitingChild($kills);
        $transport = new InProcessTransport($this->systemFor($this->liveMaster(), $child));
        $session = $this->session();

        $spawn = new Spawn(static fn (Session $s): array => ['cmd' => ['/bin/sleep', '30']]);
        $spawn->setTransport($transport);

        $started = \microtime(true);
        try {
            $spawn->handle(
                Context::background()->withDeadline(new \DateTimeImmutable('+400 milliseconds')),
                $session,
                function (): void {
                    $this->fail('Spawn is terminal; $next must not run');
                },
            );
            $this->fail('the threaded context bound must end the run');
        } catch (DeadlineExceededException) {
            $this->assertLessThan(3.0, \microtime(true) - $started);
            $this->assertSame(143, $session->exitStatus());
            $this->assertNotEmpty($kills->list);
        }
    }

    public function testSpawnStillHonoursAPlainThreeParameterSpawnerWithoutTheContext(): void
    {
        $calls = new \stdClass();
        $calls->n = 0;
        $spawner = new class ($calls) implements ChildSpawner {
            public function __construct(private \stdClass $calls) {}

            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->calls->n++;

                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $spawn = new Spawn(static fn (Session $s): array => ['cmd' => ['/bin/true']]);
        $spawn->setTransport($spawner);
        $spawn->handle(
            Context::background()->withDeadline(new \DateTimeImmutable('+30 seconds')),
            $this->session(),
            function (): void {},
        );

        $this->assertSame(1, $calls->n);
    }

    public function testThePositionalStdinArgumentStillBindsUnderTheWidenedTail(): void
    {
        // $ctx was APPENDED after $stdin/$stdout: four- and five-argument
        // positional callers are untouched, which is the whole reason the
        // new parameter could not move up the list.
        $child = new class implements Child {
            public function pid(): int { return 4242; }
            public function exited(): bool { return true; }
            public function wait(): int { return 7; }
            public function exitCode(): ?int { return 7; }
            public function kill(int $signal): void { throw new \LogicException('exited child'); }
        };
        $transport = new InProcessTransport($this->systemFor($this->liveMaster(), $child));
        $stdin = \fopen('php://memory', 'r+');
        $this->assertIsResource($stdin);
        $stdout = \fopen('php://memory', 'w+');
        $this->assertIsResource($stdout);

        try {
            $this->assertSame(7, $transport->runChild($this->session(), ['/bin/true'], null, $stdin, $stdout));
        } finally {
            \fclose($stdin);
            \fclose($stdout);
        }
    }

    public function testTheSpawnerContractPositionsAreWriteDownTheLoadBearingOrder(): void
    {
        $interfaceNames = \array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(ChildSpawner::class, 'runChild'))->getParameters(),
        );
        $concreteNames = \array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(InProcessTransport::class, 'runChild'))->getParameters(),
        );

        $this->assertSame(['session', 'cmd', 'env'], $interfaceNames);
        $this->assertSame(['session', 'cmd', 'env', 'stdin', 'stdout', 'ctx'], $concreteNames);
    }

    /**
     * By-ref promoted constructor properties are illegal in PHP (a -12
     * lesson kept), so the kill log is a boxed list.
     */
    private function neverExitingChild(\stdClass $kills): Child
    {
        return new class ($kills) implements Child {
            public function __construct(private \stdClass $kills) {}

            public function pid(): int { return 4242; }
            public function exited(): bool { return false; }
            public function wait(): int { return 143; }
            public function exitCode(): ?int { return null; }

            public function kill(int $signal): void
            {
                $this->kills->list[] = $signal;
            }
        };
    }

    /**
     * A master on a live socket pair: reads idle on the 50 ms select
     * tick (so onIdle fires) and never EOF while the peer is held — the
     * run can only end through the bound machinery under test.
     */
    private function liveMaster(): MasterPty
    {
        [$mine, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsResource($mine);
        $this->assertIsResource($peer);

        return new class ($mine, $peer) implements MasterPty {
            private bool $closed = false;

            /** @param resource $stream @param resource $peer */
            public function __construct(private $stream, private $peer) {}

            public function fd(): int { return -1; }

            public function read(int $len = 8192, ?float $timeout = null): ?string
            {
                if ($this->closed) {
                    return null;
                }
                $bytes = @\fread($this->stream, $len);

                // '' is "open, nothing yet" (non-blocking EAGAIN); null is
                // the EOF the pump reads as its exit signal.
                return $bytes === false ? null : $bytes;
            }

            public function write(string $bytes): int { return \strlen($bytes); }
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
        };
    }

    private function systemFor(MasterPty $master, Child $child): PtySystem
    {
        $slave = new class ($child) implements SlavePty {
            public function __construct(private Child $child) {}

            public function path(): string { return '/dev/null'; }

            public function spawn(
                array $cmd,
                ?array $env = null,
                ?int $cols = null,
                ?int $rows = null,
                bool $controllingTerminal = false,
            ): Child {
                return $this->child;
            }
        };

        return new class ($master, $slave) implements PtySystem {
            public function __construct(private MasterPty $master, private SlavePty $slave) {}

            public function open(int $cols = 80, int $rows = 24): PtyPair
            {
                return new class ($this->master, $this->slave) implements PtyPair {
                    public function __construct(private MasterPty $master, private SlavePty $slave) {}

                    public function master(): MasterPty { return $this->master; }
                    public function slave(): SlavePty { return $this->slave; }
                };
            }

            public function capabilities(): array
            {
                return ['pty' => false, 'termios' => false, 'signal' => false];
            }
        };
    }
}
