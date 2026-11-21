<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Pty\Posix\PosixChild;
use SugarCraft\Pty\TerminationPolicy;

/**
 * P1-8b: PosixChild::withKillOnDrop(). Destroying a kill-on-drop handle while
 * its child runs tears the child down through TerminationPolicy (and so
 * sugar-core's BoundedShutdown ladder) within the policy's budgets; the
 * default handle keeps today's drop, which leaves a running child alone.
 *
 * The test never keeps its own reference to a handle it means to drop, so
 * unset() really is the drop. The children announce themselves on stdout
 * after their trap is installed, so no signal lands before it.
 */
final class PosixChildKillOnDropTest extends TestCase
{
    private const GRACE = 0.2;
    private const KILL_GRACE = 1.0;
    /** Scheduling headroom on a loaded host; the bound is grace + kill grace + this. */
    private const SLACK = 1.5;

    /** @var list<int> pids to SIGKILL and reap on teardown */
    private array $pids = [];

    /** @var list<resource> */
    private array $pipes = [];

    protected function tearDown(): void
    {
        foreach ($this->pids as $pid) {
            // A child a default drop left running is still ours to reap.
            if (\posix_kill($pid, 0) && \posix_kill($pid, 9) && \function_exists('pcntl_waitpid')) {
                @\pcntl_waitpid($pid, $status);
            }
        }
        $this->pids = [];
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }
        $this->pipes = [];
    }

    public function testTheDefaultDropLeavesARunningChildRunning(): void
    {
        $child = $this->spawnTermTrapper();
        $pid = $child->pid();
        $this->assertFalse($child->killOnDrop());

        unset($child);

        $this->assertTrue(self::liveNonZombie($pid), 'the default drop must not signal a running child');
    }

    public function testKillOnDropTearsDownASigtermTrappingChildWithinTheGraceBound(): void
    {
        $child = $this->spawnTermTrapper()->withKillOnDrop($this->termPolicy());
        $pid = $child->pid();
        $this->assertTrue($child->killOnDrop());

        $started = \hrtime(true);
        unset($child);
        $elapsed = (\hrtime(true) - $started) / 1e9;

        $this->assertFalse(self::liveNonZombie($pid), 'the drop must end the child');
        $this->assertFalse(\posix_kill($pid, 0), 'the drop must also reap the child it ended');
        $this->assertGreaterThanOrEqual(self::GRACE, $elapsed, 'the trapped SIGTERM must not end the child; only SIGKILL after the grace may');
        $this->assertLessThan(self::GRACE + self::KILL_GRACE + self::SLACK, $elapsed);
    }

    public function testTheDefaultPolicyHangsUpOnDrop(): void
    {
        // The child traps SIGTERM but not SIGHUP, so the default policy's
        // first signal ends it well before its one-second grace.
        $child = $this->spawnTermTrapper()->withKillOnDrop();
        $pid = $child->pid();

        $started = \hrtime(true);
        unset($child);
        $elapsed = (\hrtime(true) - $started) / 1e9;

        $this->assertFalse(self::liveNonZombie($pid));
        $this->assertLessThan(TerminationPolicy::new()->graceSeconds, $elapsed, 'SIGHUP must end the child before the grace runs out');
    }

    public function testWithKillOnDropReturnsANewHandleAndLeavesTheReceiverAlone(): void
    {
        $receiver = $this->spawnTermTrapper();
        $view = $receiver->withKillOnDrop($this->termPolicy());

        $this->assertNotSame($receiver, $view);
        $this->assertSame($receiver->pid(), $view->pid());
        $this->assertFalse($receiver->killOnDrop(), 'the receiver must keep the default drop');
        $this->assertTrue($view->killOnDrop());

        // Both handles share the child: a reap through one is seen by the other.
        $view->kill(9);
        $this->assertSame(128 + 9, $view->wait());
        $this->assertTrue($receiver->exited());
        $this->assertSame(128 + 9, $receiver->exitCode());
    }

    public function testDroppingTheReceiverLeavesTheChildToTheKillOnDropHandle(): void
    {
        $receiver = $this->spawnTermTrapper();
        $pid = $receiver->pid();
        $view = $receiver->withKillOnDrop($this->termPolicy());

        unset($receiver);
        $this->assertTrue(self::liveNonZombie($pid), 'dropping the default handle must not end the child');

        unset($view);
        $this->assertFalse(self::liveNonZombie($pid), 'dropping the kill-on-drop handle must end it');
    }

    public function testOnlyTheNewestKillOnDropHandleCarriesTheDuty(): void
    {
        $first = $this->spawnTermTrapper()->withKillOnDrop($this->termPolicy());
        $pid = $first->pid();
        $second = $first->withKillOnDrop($this->termPolicy());

        $this->assertFalse($first->killOnDrop(), 'the duty must move to the newer handle');
        $this->assertTrue($second->killOnDrop());

        unset($first);
        $this->assertTrue(self::liveNonZombie($pid), 'a dropped intermediate handle must not end the child');

        unset($second);
        $this->assertFalse(self::liveNonZombie($pid));
    }

    public function testDiscardingTheResultEndsTheChildWhileTheReceiverLives(): void
    {
        // The documented sharp edge: the result is the kill-on-drop handle, not
        // a flag on the receiver, so a bare statement drops it at once.
        $receiver = $this->spawnTermTrapper();
        $pid = $receiver->pid();

        $receiver->withKillOnDrop($this->termPolicy());

        $this->assertFalse(self::liveNonZombie($pid), 'a discarded kill-on-drop handle must end the child');
        $this->assertFalse($receiver->killOnDrop(), 'the receiver must not have gained the duty');
    }

    public function testAPendingWaitAsyncDoesNotKeepTheKillOnDropHandleAlive(): void
    {
        // The review repro: the onExit() listener captured the handle, so
        // unset() never reached the destructor and the child kept running.
        $loop = new StreamSelectLoop();
        $child = $this->spawnTermTrapper()->withKillOnDrop($this->termPolicy());
        $pid = $child->pid();

        $exit = null;
        $child->waitAsync($loop, 0.01)->then(static function (int $code) use (&$exit): void {
            $exit = $code;
        });
        unset($child);

        $this->assertFalse(self::liveNonZombie($pid), 'a pending waitAsync() must not stop the drop from ending the child');

        $safety = $loop->addTimer(5.0, static fn () => $loop->stop());
        $check = $loop->addPeriodicTimer(0.01, static function () use (&$exit, $loop): void {
            if ($exit !== null) {
                $loop->stop();
            }
        });
        $loop->run();
        $loop->cancelTimer($safety);
        $loop->cancelTimer($check);

        $this->assertSame(128 + 9, $exit, 'the pending promise must settle with the exit status the drop left');
    }

    public function testDroppingAKillOnDropHandleAfterTheExitSendsNothing(): void
    {
        $child = $this->spawnTermTrapper()->withKillOnDrop($this->termPolicy());
        $child->kill(9);
        $this->assertSame(128 + 9, $child->wait());

        $started = \hrtime(true);
        unset($child);

        $this->assertLessThan(self::GRACE, (\hrtime(true) - $started) / 1e9, 'an exited child must not run the ladder');
    }

    private function termPolicy(): TerminationPolicy
    {
        return TerminationPolicy::new()
            ->withSignal(15)
            ->withGraceSeconds(self::GRACE)
            ->withKillGraceSeconds(self::KILL_GRACE);
    }

    /**
     * A child that ignores SIGTERM (the disposition survives exec) and says
     * so on stdout once the trap is in place.
     */
    private function spawnTermTrapper(): PosixChild
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\is_executable('/bin/sh') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('/bin/sh and ext-posix are required to observe the child.');
        }
        if (!\SugarCraft\Pty\Tests\Support\PtyCapability::ffiUsable()) {
            $this->markTestSkipped('the 128 + signo exit status is read through the FFI waitpid probe.');
        }

        $proc = \proc_open(
            ['/bin/sh', '-c', 'trap "" TERM; echo ready; exec sleep 30'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $this->pipes = [...$this->pipes, ...$pipes];
        $status = \proc_get_status($proc);
        $pid = (int) $status['pid'];
        $this->pids[] = $pid;

        $this->assertSame("ready\n", \fgets($pipes[1]), 'the child must announce its trap before it is signalled');

        return new PosixChild($pid, $proc);
    }

    /** Alive and not a zombie, read from /proc where it exists. */
    private static function liveNonZombie(int $pid): bool
    {
        if (!\posix_kill($pid, 0)) {
            return false;
        }
        $stat = @\file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return true;
        }

        return \preg_match('/^\d+ \(.*\) Z /s', $stat) !== 1;
    }
}
