<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Async\ChildRunLaunch;
use SugarCraft\Async\WindowsPipes;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * launch() on a private StreamSelectLoop, never the shared Loop::get(), so no
 * timer from these tests can leak into a suite that shares the loop.
 */
final class ChildRunLaunchTest extends TestCase
{
    private function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    private function reflect(string $class, string $name): \ReflectionProperty|\ReflectionMethod
    {
        $ref = method_exists($class, $name)
            ? new \ReflectionMethod($class, $name)
            : new \ReflectionProperty($class, $name);
        $ref->setAccessible(true);

        return $ref;
    }

    private function requirePosixPipes(): void
    {
        if (WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('launch() reads through stream callbacks, which Windows pipes do not offer.');
        }
    }

    /**
     * Run the loop until $promise settles or $budget seconds pass, then return
     * the result. A rejection is rethrown; a budget overrun fails the test.
     */
    private function settle(LoopInterface $loop, PromiseInterface $promise, float $budget = 10.0): ChildResult
    {
        $result = null;
        $error = null;
        $promise->then(
            static function (ChildResult $r) use (&$result, $loop): void {
                $result = $r;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$error, $loop): void {
                $error = $e;
                $loop->stop();
            },
        );
        $guard = $loop->addTimer($budget, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($guard);

        if ($error !== null) {
            throw $error;
        }
        $this->assertInstanceOf(ChildResult::class, $result, 'launch did not settle within the budget');

        return $result;
    }

    public function testLaunchResolvesWithTheOutputAndExitStatus(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $r = $this->settle($loop, ChildRun::new($this->php('fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(4);'))->launch($loop));

        $this->assertSame('out', $r->stdout);
        $this->assertSame('err', $r->stderr);
        $this->assertSame(4, $r->exitCode);
        $this->assertSame(ChildResult::EXITED, $r->status);
    }

    /**
     * Regression: launch() reported a signal-killed child's exit as null while
     * run() reported -1, so the dashboard's async path showed no failure for a
     * crashed command. The two paths must agree.
     */
    public function testLaunchReportsASignalKilledChildAsRunDoesNotNull(): void
    {
        $this->requirePosixPipes();
        $sync = ChildRun::new(['sh', '-c', 'kill -9 $$'])->run();

        $loop = new StreamSelectLoop();
        $async = $this->settle($loop, ChildRun::new(['sh', '-c', 'kill -9 $$'])->launch($loop));

        $this->assertSame(-1, $sync->exitCode);
        $this->assertSame(-1, $async->exitCode, 'a signal death must not read as "could not be reaped"');
        $this->assertSame($sync->status, $async->status);
    }

    public function testLaunchDoesNotBlockTheLoop(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $ticks = 0;
        $ticker = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void {
            $ticks++;
        });

        // The child is silent for 0.4 s; the loop must keep ticking through that wait.
        $r = $this->settle($loop, ChildRun::new($this->php('usleep(400000); fwrite(STDOUT, "late");'))->launch($loop));
        $loop->cancelTimer($ticker);

        $this->assertSame('late', $r->stdout);
        $this->assertGreaterThanOrEqual(5, $ticks, 'the loop ran while the child was silent');
    }

    public function testLaunchKillsAChildPastItsDeadline(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $t0 = microtime(true);
        $r = $this->settle($loop, ChildRun::new($this->php('sleep(30);'))->withTimeout(0.3)->launch($loop));

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertLessThan(5.0, microtime(true) - $t0, 'the kill ladder runs on the loop and is bounded');
    }

    /**
     * Regression for the loop path of the run() case: closing both pipes is not
     * an exit. A child that closes its output and keeps running must still be
     * ended at the deadline, not settled as soon as the pipes reach EOF.
     */
    public function testLaunchKillsAChildThatClosesItsOutputButLingersPastItsDeadline(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $t0 = microtime(true);
        $r = $this->settle($loop, ChildRun::new($this->php('fclose(STDOUT); fclose(STDERR); sleep(30);'))->withTimeout(0.5)->launch($loop));

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertLessThan(5.0, microtime(true) - $t0, 'the lingering child is killed at its deadline, not reaped at EOF');
    }

    /**
     * The kill budget is spent while the child is still running (a kernel wait
     * after SIGKILL, simulated here by a child that is simply still alive). The
     * result must settle without proc_close() blocking the loop, and the handle
     * must be reaped by the loop's poll once the child is gone.
     */
    public function testAChildStillRunningWhenTheKillBudgetIsSpentIsReapedOnTheLoop(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $proc = proc_open($this->php('sleep(30);'), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);

        $deferred = new \React\Promise\Deferred();
        $launch = new \SugarCraft\Async\ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            30.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result): void {
            $result = $r;
        });

        // Settling with the child still alive is the kill-budget-spent path: the
        // result must not wait for the child, and the handle is left to the loop.
        $t0 = microtime(true);
        $this->reflect(ChildRunLaunch::class, 'finish')->invoke($launch, ChildResult::TIMED_OUT);
        $this->assertLessThan(1.0, microtime(true) - $t0, 'settling a live child must not wait for it');
        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertNull($result->exitCode);
        $this->assertTrue(is_resource($proc), 'a live child is not proc_closed on the settle path');

        proc_terminate($proc, 9);
        $guard = $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->addPeriodicTimer(0.01, static function () use ($loop, $proc): void {
            if (!is_resource($proc)) {
                $loop->stop();
            }
        });
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertFalse(is_resource($proc), 'the loop reaps the child once the kernel releases it');
    }

    public function testAChildThatIgnoresSigtermIsKilledByTheLadderAndSettlesTimedOut(): void
    {
        $this->requirePosixPipes();
        // The TERM rung is ignored (inherited across exec), so only the KILL rung
        // can end the child: the run must take at least the TERM grace to settle.
        $loop = new StreamSelectLoop();
        $proc = proc_open(['sh', '-c', 'trap "" TERM; exec sleep 30'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);

        $deferred = new \React\Promise\Deferred();
        $launch = new \SugarCraft\Async\ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            0.2,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });
        $t0 = microtime(true);
        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);
        $elapsed = microtime(true) - $t0;

        $this->assertInstanceOf(ChildResult::class, $result, 'the ladder must settle the run');
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertGreaterThanOrEqual(BoundedShutdown::TERMINATE_GRACE_SECONDS, $elapsed, 'the TERM rung must be given its grace before KILL');
        $this->assertLessThan(5.0, $elapsed, 'the kill budget bounds the run');
    }

    /** Timers armed on a private StreamSelectLoop, read the way SharedLoopResidue reads the shared one. */
    private function armedTimers(StreamSelectLoop $loop): int
    {
        $queue = (new \ReflectionProperty(StreamSelectLoop::class, 'timers'))->getValue($loop);
        $store = (new \ReflectionProperty($queue, 'timers'))->getValue($queue);

        return \count($store);
    }

    /**
     * The ladder poll must not outlive a launch that settles first. The poll is
     * armed by the cancel while the child ignores TERM; the child then dies and the
     * exit is seen by checkExit() before the poll's next tick, so finish() settles
     * the launch with the poll still in the loop unless it is cancelled there.
     */
    public function testACancelledLaunchLeavesNoLadderTimerArmedWhenItSettlesOnTheExit(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $proc = proc_open(['sh', '-c', 'trap "" TERM; exec sleep 30'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);

        $source = CancellationSource::new();
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            30.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            null,
            null,
            null,
            $source->token(),
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result): void {
            $result = $r;
        });

        $launch->start();
        $source->cancel();
        $this->assertGreaterThan(0, $this->armedTimers($loop), 'the cancel arms the kill ladder');

        proc_terminate($proc, 9);
        $probeGone = $this->reflect(ChildRunLaunch::class, 'probeGone');
        $checkExit = $this->reflect(ChildRunLaunch::class, 'checkExit');
        $until = microtime(true) + 5.0;
        while (!$probeGone->invoke($launch) && microtime(true) < $until) {
            usleep(1000);
        }
        $checkExit->invoke($launch);

        $this->assertInstanceOf(ChildResult::class, $result, 'the exit settles the launch');
        $this->assertSame(ChildResult::CANCELLED, $result->status);
        $this->assertSame(0, $this->armedTimers($loop), 'no ladder poll stays armed on the loop once the launch has settled');
    }

    /**
     * A leader that dies on TERM settles the launch on its exit, but the group still
     * holds a grandchild that ignores TERM. The ladder must outlive that settle and
     * KILL the group, or the grandchild survives the launch.
     */
    public function testGroupPidLaunchKillsATermIgnoringGrandchildAfterTheLeaderSettlesTheRun(): void
    {
        $this->requirePosixPipes();
        if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('needs posix_kill and /usr/bin/setsid');
        }
        $loop = new StreamSelectLoop();
        // The subshell's ignored TERM survives exec, so only the group KILL reaches
        // the sleep; the leader sh dies on TERM from the first rung.
        $proc = proc_open(
            ['/usr/bin/setsid', 'sh', '-c', '(trap "" TERM; exec sleep 300) & echo $!; wait'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $groupPid = proc_get_status($proc)['pid'];

        $grandchild = null;
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            0.5,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            $groupPid,
            static function (int|string $slot, string $chunk) use (&$grandchild): void {
                if ($grandchild === null && (string) $slot === '1') {
                    $grandchild = (int) trim($chunk);
                }
            },
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertInstanceOf(ChildResult::class, $result, 'the leader exit must settle the run');
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertIsInt($grandchild, 'the grandchild pid must be read from stdout');

        try {
            // Settling must not stop the ladder: the loop keeps running until the
            // group KILL lands, bounded by the TERM and KILL graces plus slack.
            $gone = false;
            $poll = $loop->addPeriodicTimer(0.02, static function () use ($loop, $grandchild, &$gone): void {
                if (BoundedShutdown::pidExited($grandchild)) {
                    $gone = true;
                    $loop->stop();
                }
            });
            $bound = $loop->addTimer(
                BoundedShutdown::TERMINATE_GRACE_SECONDS + BoundedShutdown::KILL_GRACE_SECONDS + 2.0,
                static function () use ($loop): void {
                    $loop->stop();
                },
            );
            $loop->run();
            $loop->cancelTimer($poll);
            $loop->cancelTimer($bound);

            $this->assertTrue($gone, 'the kill ladder must reach the TERM-ignoring grandchild after the run settled');

            // The group-path exception is bounded: once the group is empty the ladder
            // cancels itself on its next 5 ms tick. Run until only this probe and the
            // bound remain on the loop, so a ladder that held on past the group's
            // emptying fails here rather than outliving the test.
            $drainBound = $loop->addTimer(
                BoundedShutdown::TERMINATE_GRACE_SECONDS + BoundedShutdown::KILL_GRACE_SECONDS + 2.0,
                static function () use ($loop): void {
                    $loop->stop();
                },
            );
            $drainProbe = $loop->addPeriodicTimer(0.01, function () use ($loop, &$drainProbe): void {
                if ($this->armedTimers($loop) <= 2) {
                    $loop->stop();
                }
            });
            $loop->run();
            $loop->cancelTimer($drainProbe);
            $loop->cancelTimer($drainBound);
            $this->assertSame(0, $this->armedTimers($loop), 'the ladder must leave the loop once the group is empty, not hold a timer past the settle');
        } finally {
            if (!BoundedShutdown::pidExited($grandchild)) {
                @posix_kill($grandchild, 9);
            }
        }
    }

    public function testLaunchFeedsStdinAndReadsItBackLineByLine(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $lines = '';
        for ($i = 0; $i < 200; $i++) {
            $lines .= "line $i\n";
        }
        $r = $this->settle(
            $loop,
            ChildRun::new($this->php('while (($l = fgets(STDIN)) !== false) { fwrite(STDOUT, $l); }'))
                ->withStdin($lines)
                ->launch($loop),
        );

        $this->assertSame($lines, $r->stdout);
        $this->assertTrue($r->succeeded());
    }

    /**
     * A child that exits without reading a payload past one pipe buffer breaks
     * the stdin pipe. The run must still settle as EXITED with the child's code.
     */
    public function testLaunchSettlesWhenTheChildExitsBeforeReadingItsStdin(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $r = $this->settle(
            $loop,
            ChildRun::new($this->php('exit(0);'))->withStdin(str_repeat('x', 200_000))->launch($loop),
        );

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame(0, $r->exitCode);
    }

    /**
     * Regression: once the child has closed its stdin, the next payload write
     * fails with a broken pipe, and onWritable() must close the stdin stream
     * then. The child lingers after closing its pipes, so the stream is still
     * open when the check runs if that branch is broken.
     */
    public function testBrokenStdinPipeIsClosedWhileTheChildLingers(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $proc = proc_open(
            $this->php('fclose(STDIN); fclose(STDOUT); fclose(STDERR); usleep(600000);'),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            str_repeat('x', 200_000),
            10.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
        );
        $stdinPipe = $this->reflect(ChildRunLaunch::class, 'stdinPipe');
        $stdinOpenAtCheck = null;
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $loop->addTimer(0.3, static function () use ($loop, $launch, $stdinPipe, &$stdinOpenAtCheck): void {
            $stdinOpenAtCheck = $stdinPipe->getValue($launch) !== null;
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertFalse($stdinOpenAtCheck, 'the broken stdin pipe must be closed while the child still lingers');
        $this->assertInstanceOf(ChildResult::class, $result, 'the run must settle');
        $this->assertSame(ChildResult::EXITED, $result->status);
    }

    public function testLaunchAppliesTheCapAndCountsTheRest(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $r = $this->settle(
            $loop,
            ChildRun::new($this->php('fwrite(STDOUT, str_repeat("z", 500));'))->withMaxBytes(50)->launch($loop),
        );

        $this->assertSame(50, strlen($r->stdout));
        $this->assertSame(450, $r->droppedBytes);
        $this->assertTrue($r->truncated());
    }

    public function testLaunchRejectsWhenTheProgramCannotStart(): void
    {
        $loop = new StreamSelectLoop();
        $rejected = null;
        ChildRun::new(['/nonexistent/sugar-async-launch-' . getmypid()])
            ->launch($loop)
            ->then(null, static function (\Throwable $e) use (&$rejected, $loop): void {
                $rejected = $e;
                $loop->stop();
            });
        $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
    }

    public function testOnChunkFeedsLaunchAsItArrives(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $proc = proc_open(
            $this->php('fwrite(STDOUT, "tick"); fwrite(STDERR, "tock");'),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        $seen = ['1' => '', '2' => ''];
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            10.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            null,
            static function (int|string $slot, string $chunk) use (&$seen): void {
                $seen[(string) $slot] .= $chunk;
            },
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertSame('tick', $result->stdout);
        $this->assertSame('tock', $result->stderr);
        $this->assertSame('tick', $seen['1']);
        $this->assertSame('tock', $seen['2']);
    }

    public function testGroupPidLaunchKillsGrandchildrenPastTheDeadline(): void
    {
        $this->requirePosixPipes();
        if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('needs posix_kill and /usr/bin/setsid');
        }
        $loop = new StreamSelectLoop();
        // setsid execs sh in place, so the status pid is the group leader and
        // the group id; the background sleep is the grandchild the ladder must reach.
        $proc = proc_open(
            ['/usr/bin/setsid', 'sh', '-c', 'sleep 30 & echo $!; wait'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $groupPid = proc_get_status($proc)['pid'];

        $grandchild = null;
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            0.5,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            $groupPid,
            static function (int|string $slot, string $chunk) use (&$grandchild): void {
                if ($grandchild === null && (string) $slot === '1') {
                    $grandchild = (int) trim($chunk);
                }
            },
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertIsInt($grandchild, 'the grandchild pid must be read from stdout');
        $this->assertTrue(
            BoundedShutdown::waitBounded(static fn (): bool => BoundedShutdown::pidExited($grandchild), 3.0),
            'the containment group kill must reach the grandchild',
        );
    }

    /**
     * The launch path names no group at spawn when the containment wrapper has not
     * yet become a group leader, so the rungs must read the group the child leads
     * while it is alive. Without that read a deadline kill signals only the shell,
     * and the background job outlives the run.
     */
    public function testLaunchWithoutASpawnTimeGroupKillsTheGroupTheWrapperLeads(): void
    {
        $this->requirePosixPipes();
        if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('needs posix_kill and /usr/bin/setsid');
        }
        $loop = new StreamSelectLoop();
        $proc = proc_open(
            ['/usr/bin/setsid', 'sh', '-c', 'sleep 30 & echo $!; wait'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        $grandchild = null;
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            0.5,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            null,
            static function (int|string $slot, string $chunk) use (&$grandchild): void {
                if ($grandchild === null && (string) $slot === '1') {
                    $grandchild = (int) trim($chunk);
                }
            },
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertIsInt($grandchild, 'the grandchild pid must be read from stdout');
        $this->assertTrue(
            BoundedShutdown::waitBounded(static fn (): bool => self::grandchildGone($grandchild), 3.0),
            'the kill must reach the grandchild through the group the wrapper leads, not only the shell',
        );
    }

    /**
     * Regression: a wrapped shell that exits before the deadline with no spawn-time
     * group left its background job alive. The group was read only while the shell
     * lived, and a shell that exited after several polls was never read, so the
     * deadline kill found no group and signalled nothing.
     */
    public function testLaunchWithoutASpawnTimeGroupKillsTheJobWhenTheShellExitsAfterAPoll(): void
    {
        $this->assertGrandchildKilledWhenShellExits('sleep 31 & echo $!; sleep 0.2; exit 0', 1.0);
    }

    /**
     * Regression: the shell exits before the first status poll, so no live read can
     * latch the group. The setsid wrapper leads a group whose id is its own pid, and
     * the handle still reports that pid, so the deadline kill reaches the job.
     */
    public function testLaunchWithoutASpawnTimeGroupKillsTheJobWhenTheShellExitsBeforeAnyPoll(): void
    {
        $this->assertGrandchildKilledWhenShellExits('sleep 31 & echo $!; exit 0', 0.5);
    }

    /**
     * Runs a setsid-wrapped shell with no spawn-time group, past its deadline, and
     * asserts the background job it printed is gone once the launch settles.
     */
    private function assertGrandchildKilledWhenShellExits(string $script, float $timeout): void
    {
        $this->requirePosixPipes();
        if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('needs posix_kill and /usr/bin/setsid');
        }
        $loop = new StreamSelectLoop();
        $proc = proc_open(
            ['/usr/bin/setsid', 'sh', '-c', $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        $grandchild = null;
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            $timeout,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            null,
            static function (int|string $slot, string $chunk) use (&$grandchild): void {
                if ($grandchild === null && (string) $slot === '1') {
                    $grandchild = (int) trim($chunk);
                }
            },
            setsidWrapped: true,
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertIsInt($grandchild, 'the grandchild pid must be read from stdout');
        $this->assertTrue(
            BoundedShutdown::waitBounded(static fn (): bool => self::grandchildGone($grandchild), 3.0),
            'the kill must reach the background job the exited shell left in its group',
        );
    }

    /**
     * Regression: an unwrapped child never leads a group, so its pid is not a group id.
     * Once it has exited with no live group read, the kill rungs must not fall back to
     * that pid: a pid reused by a stranger's group leader would be signalled as a group.
     * The job the shell left stays out of reach, as it does for any unwrapped child.
     */
    public function testUnwrappedChildThatExitedNamesNoGroupToSignal(): void
    {
        $this->requirePosixPipes();
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('needs posix_kill to reap the background job the shell left');
        }
        $loop = new StreamSelectLoop();
        $proc = proc_open(
            ['sh', '-c', 'sleep 31 & echo $!; exit 0'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        $grandchild = null;
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            1.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            null,
            static function (int|string $slot, string $chunk) use (&$grandchild): void {
                if ($grandchild === null && (string) $slot === '1') {
                    $grandchild = (int) trim($chunk);
                }
            },
        );

        // The shell's exit is seen while its background job still holds stdout open,
        // so the probe reads the group in the state the deadline kill would.
        $exited = new \ReflectionProperty(ChildRunLaunch::class, 'exited');
        $killGroup = new \ReflectionMethod(ChildRunLaunch::class, 'killGroup');
        $probed = false;
        $groupAtExit = null;
        $probe = $loop->addPeriodicTimer(0.02, static function () use (&$probed, &$groupAtExit, $exited, $killGroup, $launch): void {
            if (!$probed && $exited->getValue($launch) === true) {
                $probed = true;
                $groupAtExit = $killGroup->invoke($launch);
            }
        });
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        try {
            $launch->start();
            $loop->run();
        } finally {
            $loop->cancelTimer($probe);
            $loop->cancelTimer($guard);
            if (is_int($grandchild) && $grandchild > 1 && @posix_kill($grandchild, 0)) {
                @posix_kill($grandchild, 9);
            }
        }

        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertTrue($probed, 'the shell never reached exited, so no group was read');
        $this->assertNull($groupAtExit, 'an unwrapped child that exited must name no group for the kill rungs');
        $this->assertIsInt($grandchild, 'the background job pid must be read from stdout');
    }

    /**
     * Gone means signal-refused or a zombie. The grandchild is reparented to PID 1 on
     * kill, and a PID 1 that does not reap leaves it a zombie, which
     * BoundedShutdown::pidExited() would count as alive. Mirrors the candy-code
     * CommandBackendTest isLive() check.
     */
    private static function grandchildGone(int $pid): bool
    {
        if (!@posix_kill($pid, 0)) {
            return true;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat !== false) {
            $after = strrpos($stat, ')');
            $state = $after === false ? '' : substr(trim(substr($stat, $after + 1)), 0, 1);

            return $state === 'Z' || $state === 'X';
        }

        $state = trim((string) @shell_exec('ps -o stat= -p ' . $pid . ' 2>/dev/null'));

        return $state === '' || $state[0] === 'Z';
    }

    public function testOnSpawnReportsThePidOnTheLoopPath(): void
    {
        $this->requirePosixPipes();
        $loop = new \React\EventLoop\StreamSelectLoop();
        $reported = [];
        $r = $this->settle($loop, ChildRun::new($this->php('fwrite(STDOUT, (string) getmypid());'))
            ->withOnSpawn(static function (int $pid) use (&$reported): void {
                $reported[] = $pid;
            })
            ->launch($loop));

        $this->assertCount(1, $reported);
        $this->assertGreaterThan(0, $reported[0]);
        $this->assertSame((string) $reported[0], $r->stdout);
    }

    public function testAnOnSpawnThrowRejectsAndKillsTheStartedChild(): void
    {
        $this->requirePosixPipes();
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('The posix extension is needed to probe the child after the rejection.');
        }
        $loop = new \React\EventLoop\StreamSelectLoop();
        $pid = 0;

        try {
            $this->settle($loop, ChildRun::new($this->php('sleep(30);'))
                ->withOnSpawn(static function (int $spawned) use (&$pid): void {
                    $pid = $spawned;

                    throw new \RuntimeException('onSpawn refused');
                })
                ->launch($loop));
            self::fail('an onSpawn throw must reject launch()');
        } catch (\RuntimeException $e) {
            $this->assertSame('onSpawn refused', $e->getMessage());
        }

        $this->assertGreaterThan(0, $pid);
        $this->assertFalse(\posix_kill($pid, 0), 'the child must be killed and reaped, not left running');
    }

    public function testAnOnSpawnThrowOfAnyTypeRejectsLaunchWithoutThrowingSynchronously(): void
    {
        $this->requirePosixPipes();
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('The posix extension is needed to probe the child after the rejection.');
        }
        $loop = new StreamSelectLoop();
        $pid = 0;

        $promise = ChildRun::new($this->php('sleep(30);'))
            ->withOnSpawn(static function (int $spawned) use (&$pid): void {
                $pid = $spawned;

                throw new \LogicException('onSpawn misuse');
            })
            ->launch($loop);

        // A non-RuntimeException must become the rejection too, not escape launch() as a throw.
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        try {
            $this->settle($loop, $promise);
            self::fail('an onSpawn LogicException must reject launch()');
        } catch (\LogicException $e) {
            $this->assertSame('onSpawn misuse', $e->getMessage());
        }

        $this->assertGreaterThan(0, $pid);
        $this->runUntil($loop, static fn (): bool => !\posix_kill($pid, 0));
        $this->assertFalse(\posix_kill($pid, 0), 'the child must be killed and reaped, not left running');
    }

    /**
     * abandonSpawn() serves launch(), which must never block the loop: the SIGKILLed
     * child is closed by a loop timer, so the call itself returns with the handle
     * still open, and the close lands on a later tick.
     */
    public function testAbandonSpawnDefersTheCloseToALoopTimer(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $staged = \tempnam(\sys_get_temp_dir(), 'staged-deferred-');
        self::assertIsString($staged);
        \file_put_contents($staged, 'payload');
        $pipes = [];
        $proc = \proc_open($this->php('sleep(30);'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);

        try {
            $abandon = new \ReflectionMethod(ChildRun::class, 'abandonSpawn');
            $abandon->setAccessible(true);
            $abandon->invoke(null, $proc, $pipes, $staged, null, $loop);

            self::assertTrue(\is_resource($proc), 'abandonSpawn must not close the child on the call itself');
            self::assertFileExists($staged, 'the payload waits for the reap, not the call');

            $this->runUntil($loop, static fn (): bool => !\is_resource($proc));
            self::assertFalse(\is_resource($proc), 'the loop timer must close the killed child');
            self::assertFileDoesNotExist($staged);
        } finally {
            @\unlink($staged);
            if (\is_resource($proc)) {
                \proc_terminate($proc, 9);
                \proc_close($proc);
            }
        }
    }

    /**
     * Run the loop until $done() holds (checked every 10 ms) or $budget seconds pass.
     */
    private function runUntil(LoopInterface $loop, callable $done, float $budget = 5.0): void
    {
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($loop, $done): void {
            if ($done()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer($budget, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);
    }

    /**
     * The launch counterpart of the run() abandonment: the parent exits at once and
     * a backgrounded descendant keeps the pipes open and silent. Launch cuts them
     * after the silence budget and settles, EXITED with the pipe reported abandoned,
     * long before the 4 s deadline.
     */
    public function testLaunchAbandonsAPipeHeldByADescendantAfterTheExitGrace(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $started = \SugarCraft\Async\Clock::monotonicSeconds();

        $r = $this->settle($loop, ChildRun::new(['/bin/sh', '-c', 'sleep 6 & exit 0'])
            ->withTimeout(4.0)
            ->launch($loop));
        $elapsed = \SugarCraft\Async\Clock::monotonicSeconds() - $started;

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertTrue($r->succeeded());
        $this->assertTrue($r->abandonedPipes(), 'the held pipe is abandoned after the silence budget');
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'the pipe is read on for the silence budget before it is cut');
        $this->assertLessThan(3.0, $elapsed, 'launch must settle after the exit grace, not at the 4s deadline');
    }

    /**
     * Launch reads on while the pipes keep yielding after the exit: a descendant
     * that writes every half second for two seconds is read to EOF, so all five
     * lines are kept and nothing is abandoned.
     */
    public function testLaunchExitGraceCountsSilenceNotTimeFromTheExit(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();

        $r = $this->settle($loop, ChildRun::new(['/bin/sh', '-c', '(for i in 1 2 3 4 5; do echo $i; sleep 0.5; done) & exit 0'])
            ->withTimeout(10.0)
            ->launch($loop));

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame("1\n2\n3\n4\n5\n", $r->stdout);
        $this->assertFalse($r->abandonedPipes());
    }

    /**
     * The child writes 60 000 bytes and exits. The loop stalls for 1.5 s just after
     * the second 8 KiB chunk, which is past the 1 s exit grace, so the exit is seen
     * with bytes still unread in the pipe. Those bytes were written before the exit,
     * so launch must read them before it cuts a silent pipe: all 60 000 are kept and
     * nothing is abandoned.
     */
    public function testLaunchKeepsOutputBufferedBehindAStalledLoopAfterTheExit(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $chunks = 0;

        $r = $this->settle($loop, ChildRun::new($this->php('fwrite(STDOUT, str_repeat("x", 60000));'))
            ->withTimeout(10.0)
            ->withOnChunk(static function (int|string $slot, string $chunk) use (&$chunks, $loop): void {
                if ($slot === 1 && ++$chunks === 2) {
                    $loop->futureTick(static function (): void {
                        \usleep(1_500_000);
                    });
                }
            })
            ->launch($loop));

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame(60000, \strlen($r->stdout), 'output buffered behind the stalled loop is read, not cut');
        $this->assertFalse($r->abandonedPipes(), 'nothing was held by a descendant, so nothing is abandoned');
    }

    /**
     * An idle budget shorter than the exit grace must not cut a child that has
     * already exited: the grace governs its held pipe, so launch settles EXITED with
     * the pipe reported abandoned, not TIMED_OUT.
     */
    public function testLaunchExitGraceGovernsAHeldPipeWhenAnIdleBudgetIsSet(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $started = \SugarCraft\Async\Clock::monotonicSeconds();

        $r = $this->settle($loop, ChildRun::new(['/bin/sh', '-c', 'sleep 6 & exit 0'])
            ->withTimeout(4.0)
            ->withIdleTimeout(0.5)
            ->launch($loop));
        $elapsed = \SugarCraft\Async\Clock::monotonicSeconds() - $started;

        $this->assertSame(ChildResult::EXITED, $r->status, 'an exited child is not idle-killed');
        $this->assertSame(0, $r->exitCode);
        $this->assertTrue($r->abandonedPipes(), 'the held pipe is abandoned after the exit grace');
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'the pipe is read on for the exit grace before it is cut');
        $this->assertLessThan(3.0, $elapsed, 'launch settles after the exit grace, not at the idle budget or deadline');
    }

    /**
     * Regression: launch() counted a stdin write as output for the exit grace, so a
     * descendant that drained its stdin slowly and wrote nothing to stdout held the
     * pipes to the deadline (TIMED_OUT), while run() cut them one grace after the exit
     * and reported EXITED with the pipe abandoned. The grandchild reads 8 KiB every
     * 0.2 s, so each stdin write lands inside the 0.6 s grace; only stdout reads count.
     * Both entry points must give the same result.
     */
    public function testLaunchAndRunCutAHeldPipeAtTheSameGraceWhileADescendantDrainsStdin(): void
    {
        $this->requirePosixPipes();
        $reader = 'while (($c = fread(STDIN, 8192)) !== "" && $c !== false) { usleep(200000); }';
        // A background job in sh gets /dev/null as stdin unless it is redirected from
        // an fd that still holds the pipe, so the pipe is duplicated first.
        $argv = ['/bin/sh', '-c', 'exec 3<&0; "$0" -r "$1" <&3 & exit 0', PHP_BINARY, $reader];
        $configured = static fn (): ChildRun => ChildRun::new($argv)
            ->withTimeout(4.0)
            ->withExitGrace(0.6)
            ->withStdin(str_repeat('x', 262144));

        $sync = $configured()->run();

        $loop = new StreamSelectLoop();
        $started = \SugarCraft\Async\Clock::monotonicSeconds();
        $async = $this->settle($loop, $configured()->launch($loop));
        $elapsed = \SugarCraft\Async\Clock::monotonicSeconds() - $started;

        $this->assertSame(ChildResult::EXITED, $sync->status, 'run() cuts the held pipe after the grace');
        $this->assertTrue($sync->abandonedPipes());
        $this->assertSame(ChildResult::EXITED, $async->status, 'launch() must cut the held pipe at the same grace, not hold it to the deadline');
        $this->assertTrue($async->abandonedPipes(), 'the held pipe is reported abandoned on the loop path too');
        $this->assertSame($sync->exitCode, $async->exitCode);
        $this->assertLessThan(3.0, $elapsed, 'launch settles one grace after the exit, not at the 4 s deadline');
    }

    /** The control for the launch grace: with it removed, a held pipe holds launch to its deadline. */
    public function testLaunchWithoutAnExitGraceHoldsAHeldPipeToTheDeadline(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $started = \SugarCraft\Async\Clock::monotonicSeconds();

        $r = $this->settle($loop, ChildRun::new(['/bin/sh', '-c', 'sleep 6 & exit 0'])
            ->withTimeout(2.0)
            ->withExitGrace(null)
            ->launch($loop));
        $elapsed = \SugarCraft\Async\Clock::monotonicSeconds() - $started;

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertFalse($r->abandonedPipes(), 'a deadline kill is not an abandonment');
        $this->assertGreaterThanOrEqual(1.5, $elapsed, 'launch waits for the deadline while the pipe is held');
    }

    /**
     * The child exits with 60 000 bytes still unread, and the loop stalls past the
     * exit grace before the exit is checked again. The post-exit read takes a chunk,
     * and the chunk's onChunk cancels the launch. The ladder then closes the pipes,
     * and the read must stop there: reading a closed pipe is a TypeError out of the
     * check timer. The launch settles CANCELLED.
     */
    public function testACancelFromOnChunkDuringTheExitGraceSettlesCancelledWithoutATypeError(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $proc = proc_open(
            $this->php('fwrite(STDOUT, str_repeat("x", 60000));'),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        $source = CancellationSource::new();
        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            30.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
            null,
            static function (int|string $slot, string $chunk) use ($source): void {
                $source->cancel();
            },
            null,
            $source->token(),
            null,
            0.2,
        );
        $result = null;
        $error = null;
        $deferred->promise()->then(
            static function (ChildResult $r) use (&$result): void {
                $result = $r;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $launch->start();
        $probeGone = $this->reflect(ChildRunLaunch::class, 'probeGone');
        $checkExit = $this->reflect(ChildRunLaunch::class, 'checkExit');

        // The loop is not running, so nothing reads the pipe while the child exits.
        $until = microtime(true) + 5.0;
        while (!$probeGone->invoke($launch) && microtime(true) < $until) {
            usleep(1000);
        }
        $this->assertTrue($probeGone->invoke($launch), 'the child exits with its output unread');

        // Stall the loop past the 0.2 s grace, then the exit check reads the held bytes.
        usleep(300_000);
        $checkExit->invoke($launch);

        $this->assertNull($error, 'a cancel from a chunk callback must not reject the launch');
        $this->assertInstanceOf(ChildResult::class, $result, 'the cancel settles the launch');
        $this->assertSame(ChildResult::CANCELLED, $result->status);
        $this->assertSame(0, $this->armedTimers($loop), 'no timer stays armed once the launch has settled');
    }
}
