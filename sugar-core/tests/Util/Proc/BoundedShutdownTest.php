<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Proc;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * The TERM→poll→KILL→reap ladder, pinned against REAL children.
 *
 * Every child here is a trivial `sleep`/`sh` fixture spawned by the test
 * itself and torn down in {@see tearDown()} through the ladder under test,
 * so no assertion depends on the developer's process table and none can
 * leave a child behind. Budgets are deliberately small (0.2–0.5s): the
 * property under proof is BOUNDEDNESS — the pre-ladder code blocked for the
 * child's whole 8s lifetime, so a TERM-ignoring child must die in well under
 * one second here while a bare `proc_terminate(); proc_close();` would still
 * be waiting.
 *
 * The fork-shaped pid tests additionally require ext-pcntl because their
 * liveness probe is `WNOHANG` — without it the probe degrades to signal-0,
 * which cannot tell a zombie from a live child, and pinning the strict
 * answer on such a host would be testing the fallback, not the law.
 */
final class BoundedShutdownTest extends TestCase
{
    /** @var list<resource> */
    private array $handles = [];

    protected function tearDown(): void
    {
        // Safety net: whatever a failed assertion left alive goes down the
        // ladder, so a red test never leaks a sleeper into the next one.
        foreach ($this->handles as $handle) {
            BoundedShutdown::terminateBounded($handle, 0.3, 0.3);
        }
        $this->handles = [];
    }

    public function testAlreadyExitedChildReportsItsOwnExitCodeAndSpendsNoSignal(): void
    {
        $process = $this->spawn(['/bin/sh', '-c', 'exit 7']);

        self::assertTrue(BoundedShutdown::hasExited($process, 2.0), 'the fixture itself must terminate');
        self::assertSame(7, BoundedShutdown::terminateBounded($process));
        self::assertFalse(\is_resource($process), 'terminateBounded() reaps: the handle is consumed');
    }

    public function testTermResponsiveChildDiesAndYieldsAnExitStatus(): void
    {
        $process = $this->spawn(['sleep', '30']);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);

        $started = \hrtime(true);
        $status = BoundedShutdown::terminateBounded($process, 0.5, 0.5);
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertIsInt($status);
        self::assertLessThan(1.2, $elapsedSeconds, 'a SIGTERM-honouring child must not pay the KILL rung');
        self::assertProcessGone($pid);
    }

    public function testTermIgnoringChildIsEscalatedToNineWithinTheBudget(): void
    {
        $process = $this->spawn(['/bin/sh', '-c', "trap '' TERM; exec sleep 30"]);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);

        $started = \hrtime(true);
        $status = BoundedShutdown::terminateBounded($process, 0.3, 0.5);
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertIsInt($status);
        self::assertLessThan(
            1.5,
            $elapsedSeconds,
            'THE keystone: without the ladder this call blocked for the child\'s whole 30s sleep',
        );
        self::assertProcessGone($pid);
    }

    public function testNonResourceArgumentIsAnIdempotentNoOpOnEveryEntryPoint(): void
    {
        // Teardown paths run twice (explicit stop, then __destruct); the
        // second pass gets a consumed handle and must answer, not throw.
        self::assertNull(BoundedShutdown::terminateBounded(null));
        self::assertNull(BoundedShutdown::terminateBounded(false));
        self::assertNull(BoundedShutdown::reapIfExited(null));
        self::assertTrue(BoundedShutdown::terminateAndAwaitExit(null));
        self::assertTrue(BoundedShutdown::hasExited(null, 0.0));
    }

    public function testTerminateAndAwaitExitRunsTheLadderButLeavesTheReapToTheCaller(): void
    {
        $process = $this->spawn(['sleep', '30']);

        self::assertTrue(BoundedShutdown::terminateAndAwaitExit($process, 0.3, 0.3));
        self::assertTrue(\is_resource($process), 'the handle must survive for the caller-owned proc_close()');
        self::assertIsInt(\proc_close($process));
    }

    public function testEscalateSendsFifteenThenNineAndFiresTheHookExactlyOnce(): void
    {
        $sent = [];
        $hookCalls = 0;
        $goneAfterNine = static function () use (&$sent): bool {
            return \in_array(9, $sent, true);
        };

        $gone = BoundedShutdown::escalate(
            static function (int $signal) use (&$sent): void {
                $sent[] = $signal;
            },
            $goneAfterNine,
            0.05,
            0.05,
            static function () use (&$hookCalls): void {
                ++$hookCalls;
            },
        );

        self::assertTrue($gone);
        self::assertSame([15, 9], $sent, 'rung order and pcntl-free literal numbers are the contract');
        self::assertSame(1, $hookCalls, 'the between-rungs hook fires once, only when TERM missed');
    }

    public function testEscalateReportsFalseForAChildNoRungCanMove(): void
    {
        $sent = [];

        $gone = BoundedShutdown::escalate(
            static function (int $signal) use (&$sent): void {
                $sent[] = $signal;
            },
            static fn (): bool => false,
            0.05,
            0.05,
        );

        self::assertFalse($gone, 'the result is reported for caller-side judgement, never swallowed');
        self::assertSame([15, 9], $sent);
    }

    public function testWaitBoundedAsksAtLeastOnceAndNeverSleepsOnAGoneChild(): void
    {
        $calls = 0;
        self::assertTrue(BoundedShutdown::waitBounded(
            static function () use (&$calls): bool {
                ++$calls;

                return true;
            },
            0.0,
        ));
        self::assertSame(1, $calls, 'a prompt exit must not pay a single poll interval');

        $misses = 0;
        self::assertFalse(BoundedShutdown::waitBounded(
            static function () use (&$misses): bool {
                ++$misses;

                return false;
            },
            0.0,
        ));
        self::assertGreaterThanOrEqual(2, $misses, 'zero budget still asks before and after — one-shot polls race');
    }

    public function testHasExitedPollsUpToItsBudgetAgainstALiveChild(): void
    {
        $process = $this->spawn(['sleep', '30']);

        $started = \hrtime(true);
        self::assertFalse(BoundedShutdown::hasExited($process, 0.2));
        self::assertGreaterThanOrEqual(0.2, (\hrtime(true) - $started) / 1_000_000_000);
    }

    public function testReapIfExitedNeverSignalsALiveChild(): void
    {
        // The launcher shape: a spawner that must not SIGTERM a child busy
        // creating the very session it was asked to start.
        $process = $this->spawn(['sleep', '30']);

        self::assertNull(BoundedShutdown::reapIfExited($process, 0.2));
        self::assertTrue(\is_resource($process), 'a still-running handle is left ALONE, not consumed');
        self::assertTrue((bool) (\proc_get_status($process)['running'] ?? false), 'and never signalled');
    }

    public function testReapIfExitedReapsAChildThatAlreadyLeft(): void
    {
        $process = $this->spawn(['/bin/sh', '-c', 'exit 3']);
        self::assertTrue(BoundedShutdown::hasExited($process, 2.0));

        self::assertSame(3, BoundedShutdown::reapIfExited($process, 0.2));
        self::assertFalse(\is_resource($process), 'the reap consumed the handle');
    }

    public function testGroupIdDiscriminatesAKernelMeasuredGroupLeaderFromAPlainChild(): void
    {
        self::requirePosix();

        $plain = $this->spawn(['sleep', '30']);
        self::assertNull(
            BoundedShutdown::groupId($plain),
            'a direct child inherits OUR group; claiming it as a groupPid would arm a group-kill on this very process',
        );

        if (!\SugarCraft\Core\Util\Executable\Locator::exists('setsid')) {
            self::markTestSkipped('setsid(1) is not installed on this host');
        }

        $wrapped = $this->spawn(['/usr/bin/setsid', 'sleep', '30']);
        $wrappedPid = (int) (\proc_get_status($wrapped)['pid'] ?? 0);
        $deadline = \hrtime(true) / 1_000_000_000 + 2.0;
        while (\posix_getpgid($wrappedPid) !== $wrappedPid && \hrtime(true) / 1_000_000_000 < $deadline) {
            \usleep(10_000); // setsid becomes leader by syscall, not by bookkeeping — give it the moment
        }

        self::assertSame(
            $wrappedPid,
            BoundedShutdown::groupId($wrapped),
            'the kernel, not a flag, answers who leads a group',
        );

        self::assertIsInt(BoundedShutdown::terminateBounded($wrapped, 0.3, 0.3, $wrappedPid));
        self::assertProcessGone($wrappedPid);
    }

    public function testTerminatePidBoundedMovesAForkedChildOnTheFirstRung(): void
    {
        $this->requirePcntlAndPosix();
        $pid = $this->forkSleepingChild(signalsIgnored: false);

        self::assertFalse(BoundedShutdown::pidExited($pid));
        $started = \hrtime(true);
        self::assertTrue(BoundedShutdown::terminatePidBounded($pid, 0.5, 0.5));
        self::assertLessThan(1.2, (\hrtime(true) - $started) / 1_000_000_000);
    }

    public function testTerminatePidBoundedEscalatesPastAForkedChildThatIgnoresTerm(): void
    {
        $this->requirePcntlAndPosix();
        $pid = $this->forkSleepingChild(signalsIgnored: true);

        $started = \hrtime(true);
        self::assertTrue(BoundedShutdown::terminatePidBounded($pid, 0.3, 0.5));
        self::assertLessThan(
            1.5,
            (\hrtime(true) - $started) / 1_000_000_000,
            'the pid twin must be as bounded as the proc resource twin',
        );
        self::assertTrue(BoundedShutdown::pidExited($pid));
    }

    public function testPidHelpersRefuseNonPositivePidsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BoundedShutdown::pidExited(0);
    }

    public function testTerminatePidBoundedRefusesNonPositivePidsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BoundedShutdown::terminatePidBounded(-1);
    }

    public function testTerminatePidRefusesNonPositivePidsOnEveryHost(): void
    {
        // The pid gate runs BEFORE the extension gate, so this answer is the
        // same whether or not posix is loaded — no platform-conditional
        // assertion count.
        self::assertFalse(BoundedShutdown::terminatePid(0, 15));
        self::assertFalse(BoundedShutdown::terminatePid(-5, 15));
    }

    /**
     * @param list<string> $argv
     *
     * @return resource
     */
    private function spawn(array $argv)
    {
        $process = \proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process, 'test fixture spawn failed');
        $this->handles[] = $process;

        return $process;
    }

    private function forkSleepingChild(bool $signalsIgnored): int
    {
        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid, 'pcntl_fork() failed on a host that advertises the extension');

        if ($pid === 0) {
            // Child: never fall back into PHPUnit's shutdown.
            if ($signalsIgnored) {
                \pcntl_signal(SIGTERM, static function (): void {
                });
            }
            \sleep(20);
            exit(0);
        }

        return $pid;
    }

    private function assertProcessGone(int $pid): void
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return;
        }

        self::assertFalse(@\posix_kill($pid, 0), "pid {$pid} still answers signals after the ladder");
    }

    private function requirePosix(): void
    {
        if (!\function_exists('posix_getpgid') || !\function_exists('posix_kill') || PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('group accounting needs posix on a POSIX host');
        }
    }

    private function requirePcntlAndPosix(): void
    {
        if (
            !\function_exists('pcntl_fork')
            || !\function_exists('pcntl_waitpid')
            || !\function_exists('posix_kill')
            || PHP_OS_FAMILY === 'Windows'
        ) {
            self::markTestSkipped('the fork-shaped ladder needs ext-pcntl and ext-posix');
        }
    }

    /**
     * Run one async ladder on a private loop and return what it settled with.
     * The loop is bounded so a stuck ladder fails by name instead of hanging.
     *
     * @param callable(LoopInterface, callable(mixed=):void):void $start
     */
    private function settleAsync(callable $start): mixed
    {
        $loop = new StreamSelectLoop();
        $settled = false;
        $value = null;
        $start($loop, static function (mixed $v = null) use (&$settled, &$value, $loop): void {
            $settled = true;
            $value = $v;
            $loop->stop();
        });

        if (!$settled) {
            $bound = $loop->addTimer(5.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($bound);
        }

        self::assertTrue($settled, 'the async ladder must settle within the bound');

        return $value;
    }

    public function testEscalateAsyncSendsFifteenThenNineAndReportsGoneWhenNineLands(): void
    {
        $sent = [];
        $hookCalls = 0;

        $gone = $this->settleAsync(static function (LoopInterface $loop, callable $done) use (&$sent, &$hookCalls): void {
            BoundedShutdown::escalateAsync(
                $loop,
                static function (int $signal) use (&$sent): void {
                    $sent[] = $signal;
                },
                static function () use (&$sent): bool {
                    return \in_array(9, $sent, true);
                },
                static fn (bool $isGone) => $done($isGone),
                0.05,
                0.5,
                static function () use (&$hookCalls): void {
                    $hookCalls++;
                },
            );
        });

        self::assertTrue($gone);
        self::assertSame([15, 9], $sent, 'the rung order is TERM then the integer-literal 9');
        self::assertSame(1, $hookCalls, 'the escalation hook fires once between the rungs');
    }

    public function testEscalateAsyncReportsStillRunningWhenTheKillBudgetRunsOut(): void
    {
        $sent = [];
        $started = \hrtime(true);

        $gone = $this->settleAsync(static function (LoopInterface $loop, callable $done) use (&$sent): void {
            BoundedShutdown::escalateAsync(
                $loop,
                static function (int $signal) use (&$sent): void {
                    $sent[] = $signal;
                },
                static fn (): bool => false,
                static fn (bool $isGone) => $done($isGone),
                0.05,
                0.05,
            );
        });

        self::assertFalse($gone, 'a child that never dies is reported as still running');
        self::assertSame([15, 9], $sent);
        self::assertLessThan(2.0, (\hrtime(true) - $started) / 1_000_000_000, 'both budgets bound the wait');
    }

    public function testTerminateBoundedAsyncDeliversTheExitStatusOfAnAlreadyExitedChild(): void
    {
        $process = $this->spawn(['/bin/sh', '-c', 'exit 7']);
        self::assertTrue(BoundedShutdown::hasExited($process, 2.0), 'the fixture itself must terminate');

        $status = $this->settleAsync(static function (LoopInterface $loop, callable $done) use ($process): void {
            BoundedShutdown::terminateBoundedAsync($loop, $process, static fn (?int $s) => $done($s));
        });

        self::assertSame(7, $status);
        self::assertFalse(\is_resource($process), 'the async reap consumes the handle');
    }

    public function testTerminateBoundedAsyncEscalatesAndReapsATermIgnoringChild(): void
    {
        $process = $this->spawn(['/bin/sh', '-c', "trap '' TERM; exec sleep 30"]);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);

        $started = \hrtime(true);
        $status = $this->settleAsync(static function (LoopInterface $loop, callable $done) use ($process): void {
            BoundedShutdown::terminateBoundedAsync(
                $loop,
                $process,
                static fn (?int $s) => $done($s),
                0.3,
                0.5,
            );
        });
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertIsInt($status);
        self::assertLessThan(
            2.5,
            $elapsedSeconds,
            'the async ladder must not wait out the child\'s 30s sleep',
        );
        self::assertProcessGone($pid);
        self::assertFalse(\is_resource($process));
    }

    public function testTerminateBoundedAsyncEscalatesAndReapsTheWholeProcessGroup(): void
    {
        self::requirePosix();
        if (!\SugarCraft\Core\Util\Executable\Locator::exists('setsid')) {
            self::markTestSkipped('setsid(1) is not installed on this host');
        }

        // The wrapper runs a background grandchild and reports its pid through a
        // marker file. Only the group signal reaches the grandchild: a direct
        // proc_terminate() kills the wrapper alone and orphans the sleeper.
        $marker = \tempnam(\sys_get_temp_dir(), 'bounded-shutdown-');
        self::assertNotFalse($marker, 'could not create the grandchild marker file');
        $grandchild = 0;

        try {
            $wrapped = $this->spawn(['/usr/bin/setsid', 'sh', '-c', 'sleep 30 & echo $! > "$0"; wait', $marker]);
            $wrappedPid = (int) (\proc_get_status($wrapped)['pid'] ?? 0);

            $deadline = \hrtime(true) / 1_000_000_000 + 2.0;
            while (\posix_getpgid($wrappedPid) !== $wrappedPid && \hrtime(true) / 1_000_000_000 < $deadline) {
                \usleep(10_000);
            }
            // file_get_contents(), not filesize(): PHP's stat cache would pin the size at 0.
            while (\trim((string) \file_get_contents($marker)) === '' && \hrtime(true) / 1_000_000_000 < $deadline) {
                \usleep(10_000);
            }
            $grandchild = (int) \trim((string) \file_get_contents($marker));
            self::assertGreaterThan(0, $grandchild, 'the wrapper never reported its grandchild');
            self::assertTrue(self::stillRunning($grandchild), 'the grandchild must be alive before the teardown');

            $status = $this->settleAsync(static function (LoopInterface $loop, callable $done) use ($wrapped, $wrappedPid): void {
                BoundedShutdown::terminateBoundedAsync(
                    $loop,
                    $wrapped,
                    static fn (?int $s) => $done($s),
                    0.3,
                    0.3,
                    $wrappedPid,
                );
            });

            self::assertIsInt($status);
            self::assertFalse(\is_resource($wrapped), 'the async reap consumes the handle');
            self::assertFalse(
                self::stillRunning($grandchild),
                'the group-aware async ladder must take the descendant down with the wrapper',
            );
        } finally {
            @\unlink($marker);
            if ($grandchild > 0 && self::stillRunning($grandchild)) {
                @\posix_kill($grandchild, 9);
            }
        }
    }

    public function testEscalateAsyncNeverSendsNineToAChildThatTermEndsGone(): void
    {
        $sent = [];
        $hookCalls = 0;

        $gone = $this->settleAsync(static function (LoopInterface $loop, callable $done) use (&$sent, &$hookCalls): void {
            BoundedShutdown::escalateAsync(
                $loop,
                static function (int $signal) use (&$sent): void {
                    $sent[] = $signal;
                },
                static function () use (&$sent): bool {
                    return \in_array(15, $sent, true);
                },
                static fn (bool $isGone) => $done($isGone),
                0.5,
                0.5,
                static function () use (&$hookCalls): void {
                    ++$hookCalls;
                },
            );
        });

        self::assertTrue($gone);
        self::assertSame([15], $sent, 'a well-behaved child pays no KILL rung on the async path either');
        self::assertSame(0, $hookCalls, 'the between-rungs hook must not fire when TERM landed');
    }

    public function testTerminateBoundedAsyncEndsATermHonouringChildWithoutTheKillRung(): void
    {
        $process = $this->spawn(['sleep', '30']);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);

        $started = \hrtime(true);
        $status = $this->settleAsync(static function (LoopInterface $loop, callable $done) use ($process): void {
            BoundedShutdown::terminateBoundedAsync(
                $loop,
                $process,
                static fn (?int $s) => $done($s),
                0.5,
                2.0,
            );
        });
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertIsInt($status);
        self::assertLessThan(1.0, $elapsedSeconds, 'a SIGTERM-honouring child must not pay the kill budget');
        self::assertProcessGone($pid);
        self::assertFalse(\is_resource($process));
    }

    public function testReapWhenExitedNeverBlocksTheLoopWhileTheChildRuns(): void
    {
        self::requirePosix();

        // A blocking proc_close() would park the loop for the child's whole 30s
        // sleep. The tick below runs only if the loop stays free while the child
        // is alive, and the external kill then lets the deferred reap settle.
        $process = $this->spawn(['sleep', '30']);
        $pid = (int) (\proc_get_status($process)['pid'] ?? 0);

        $loop = new StreamSelectLoop();
        $settled = false;
        $status = null;
        $ticks = 0;
        $handleLiveAtKill = false;

        BoundedShutdown::reapWhenExited(
            $loop,
            $process,
            static function (?int $s) use (&$settled, &$status, $loop): void {
                $settled = true;
                $status = $s;
                $loop->stop();
            },
            0.01,
        );

        $loop->addTimer(0.1, static function () use (&$ticks, &$handleLiveAtKill, $process, $pid): void {
            ++$ticks;
            $handleLiveAtKill = \is_resource($process);
            @\posix_kill($pid, 9);
        });

        $started = \hrtime(true);
        $bound = $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($bound);
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertSame(1, $ticks, 'the loop must keep ticking while the child is still alive');
        self::assertTrue($handleLiveAtKill, 'the reap must not consume a running child');
        self::assertTrue($settled, 'the deferred reap must settle once the child is gone');
        self::assertIsInt($status);
        self::assertFalse(\is_resource($process));
        self::assertLessThan(2.0, $elapsedSeconds, 'the reap follows the death, it does not wait out the sleep');
    }

    public function testReapWhenExitedAnswersAtOnceForAnAlreadyExitedChild(): void
    {
        $process = $this->spawn(['/bin/sh', '-c', 'exit 5']);
        self::assertTrue(BoundedShutdown::hasExited($process, 2.0), 'the fixture itself must terminate');

        $status = $this->settleAsync(static function (LoopInterface $loop, callable $done) use ($process): void {
            BoundedShutdown::reapWhenExited($loop, $process, static fn (?int $s) => $done($s));
        });

        self::assertSame(5, $status);
        self::assertFalse(\is_resource($process));
    }

    public function testReapWhenExitedAnswersNullForANonResourceAtOnce(): void
    {
        $loop = new StreamSelectLoop();
        $answer = 'unset';

        BoundedShutdown::reapWhenExited($loop, null, static function (?int $s) use (&$answer): void {
            $answer = $s;
        });

        self::assertNull($answer);
    }

    /**
     * A zombie has already died even though signal 0 still reaches its pid,
     * so the probe reads the kernel's state letter as well.
     */
    private static function stillRunning(int $pid): bool
    {
        if (!@\posix_kill($pid, 0)) {
            return false;
        }

        $stat = @\file_get_contents("/proc/{$pid}/stat");

        return $stat === false || \preg_match('/\)\s+Z/', $stat) !== 1;
    }

    public function testTerminateBoundedAsyncAnswersNullForANonResourceAtOnce(): void
    {
        $loop = new StreamSelectLoop();
        $answer = 'unset';

        BoundedShutdown::terminateBoundedAsync($loop, null, static function (?int $s) use (&$answer): void {
            $answer = $s;
        });

        self::assertNull($answer, 'a consumed or absent handle answers null synchronously, like the sync contract');
    }
}
