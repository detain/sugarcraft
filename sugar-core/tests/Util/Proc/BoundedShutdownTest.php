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

    public function testASignalDeathThroughTheLadderIsTheMinusOneSentinel(): void
    {
        // The ladder's poll consumes a signal death's status, so the documented
        // answer is the -1 sentinel (see terminateBounded()), not the signal
        // number. A bare `assertIsInt` let this value go unexamined before.
        $process = $this->spawn(['sleep', '30']);
        \proc_terminate($process, 9);

        self::assertSame(-1, BoundedShutdown::terminateBounded($process, 0.2, 0.5));
    }

    public function testAnUnpolledSignalDeathReapsAsTheSignalNumber(): void
    {
        // The other side of the documented divergence: a proc_close() that no
        // poll preceded reports the signal itself. proc_close() waits for the
        // SIGKILL to land, so no sleep is needed for this to be deterministic.
        $process = $this->spawn(['sleep', '30']);
        \proc_terminate($process, 9);

        self::assertSame(9, \proc_close($process));
    }

    public function testReapIfExitedReportsASignalDeathAsTheSentinel(): void
    {
        $process = $this->spawn(['sleep', '30']);
        \proc_terminate($process, 9);

        self::assertSame(-1, BoundedShutdown::reapIfExited($process, 2.0));
        self::assertFalse(\is_resource($process));
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

    public function testZeroBudgetProbeNeverSleepsBetweenItsTwoPolls(): void
    {
        $misses = 0;
        $started = \hrtime(true);
        self::assertFalse(BoundedShutdown::waitBounded(
            static function () use (&$misses): bool {
                ++$misses;

                return false;
            },
            0.0,
        ));
        $elapsedUs = (\hrtime(true) - $started) / 1000;

        self::assertSame(2, $misses, 'a zero budget asks before and after, no more');
        self::assertLessThan(
            BoundedShutdown::POLL_INTERVAL_US,
            $elapsedUs,
            'a zero budget must not pay a poll interval it never asked for',
        );
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

        $setsid = \SugarCraft\Core\Util\Executable\Locator::locate('setsid');
        if ($setsid === null) {
            self::markTestSkipped('setsid(1) is not installed on this host');
        }

        $wrapped = $this->spawn([$setsid, 'sleep', '30']);
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
        $setsid = \SugarCraft\Core\Util\Executable\Locator::locate('setsid');
        if ($setsid === null) {
            self::markTestSkipped('setsid(1) is not installed on this host');
        }

        // The wrapper runs a background grandchild and reports its pid through a
        // marker file. Only the group signal reaches the grandchild: a direct
        // proc_terminate() kills the wrapper alone and orphans the sleeper.
        $marker = \tempnam(\sys_get_temp_dir(), 'bounded-shutdown-');
        self::assertNotFalse($marker, 'could not create the grandchild marker file');
        $grandchild = 0;

        try {
            $wrapped = $this->spawn([$setsid, 'sh', '-c', 'sleep 30 & echo $! > "$0"; wait', $marker]);
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

    public function testReapWhenExitedSettlesOnceWhenAnotherTeardownClosesTheHandle(): void
    {
        self::requirePosix();

        // A second teardown closes the handle while the deferred reap is armed.
        // The reap's next tick must settle once and stay quiet: proc_close() on
        // the consumed resource would throw, and that throw escapes $loop->run().
        $process = $this->spawn(['sleep', '30']);

        $loop = new StreamSelectLoop();
        $settles = 0;
        $status = 'unset';

        BoundedShutdown::reapWhenExited(
            $loop,
            $process,
            static function (?int $s) use (&$settles, &$status): void {
                ++$settles;
                $status = $s;
            },
            0.01,
        );

        $loop->addTimer(0.05, static function () use ($process): void {
            BoundedShutdown::terminateBounded($process, 0.3, 0.3);
        });
        $loop->addTimer(0.6, static function () use ($loop): void {
            $loop->stop();
        });

        $escaped = null;
        try {
            $loop->run();
        } catch (\Throwable $e) {
            $escaped = $e;
        }

        self::assertNull($escaped, 'no throwable may escape the loop when the reap finds its handle already closed');
        self::assertSame(1, $settles, 'the deferred reap must settle exactly once');
        self::assertNull($status, 'a handle closed by another teardown settles the reap with no status');
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

    /**
     * Regression: the group-aware ladders declared the child gone the moment
     * the wrapper exited, so a descendant that ignored SIGTERM was never sent
     * signal 9 and survived the teardown. The wrapper dies on TERM here while
     * its background sleeper has TERM ignored, so only the 9 rung reaches it.
     */
    public function testTerminateBoundedReachesATermIgnoringDescendantAfterTheWrapperDiesOnTerm(): void
    {
        [$wrapped, $wrappedPid, $grandchild] = $this->spawnGroupWithTermIgnoringGrandchild($this->setsidOrSkip());

        try {
            BoundedShutdown::terminateBounded($wrapped, 0.3, 0.3, $wrappedPid);

            self::assertFalse(\is_resource($wrapped), 'the sync reap consumes the handle');
            self::assertFalse(
                self::stillRunning($grandchild),
                'the group ladder must not call the child gone while a TERM-ignoring descendant lives; signal 9 has to reach the group',
            );
        } finally {
            if ($grandchild > 0 && self::stillRunning($grandchild)) {
                @\posix_kill($grandchild, 9);
            }
        }
    }

    public function testTerminateBoundedAsyncReachesATermIgnoringDescendantAfterTheWrapperDiesOnTerm(): void
    {
        [$wrapped, $wrappedPid, $grandchild] = $this->spawnGroupWithTermIgnoringGrandchild($this->setsidOrSkip());

        try {
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
                'the async group ladder must not call the child gone while a TERM-ignoring descendant lives',
            );
        } finally {
            if ($grandchild > 0 && self::stillRunning($grandchild)) {
                @\posix_kill($grandchild, 9);
            }
        }
    }

    public function testTerminateAndAwaitExitReachesATermIgnoringDescendantAfterTheWrapperDiesOnTerm(): void
    {
        [$wrapped, $wrappedPid, $grandchild] = $this->spawnGroupWithTermIgnoringGrandchild($this->setsidOrSkip());

        try {
            self::assertTrue(BoundedShutdown::terminateAndAwaitExit($wrapped, 0.3, 0.3, $wrappedPid));
            self::assertFalse(
                self::stillRunning($grandchild),
                'terminateAndAwaitExit reports gone only once the group is empty, so the descendant must already be down',
            );
        } finally {
            if ($grandchild > 0 && self::stillRunning($grandchild)) {
                @\posix_kill($grandchild, 9);
            }
        }
    }

    /**
     * Regression: the group probe walked every /proc entry on each poll tick of
     * the async ladders, a ~40 ms loop stall per tick on a busy host. Inside one
     * REAP_POLL_SECONDS cadence a live group must cost one walk, not one per tick.
     */
    public function testGroupEmptyReusesALiveGroupScanInsideThePollCadence(): void
    {
        [$wrapped, $wrappedPid, $grandchild] = $this->spawnGroupWithTermIgnoringGrandchild($this->setsidOrSkip());

        try {
            $started = \hrtime(true);
            for ($i = 0; $i < 200; $i++) {
                self::assertFalse(BoundedShutdown::groupEmpty($wrappedPid), 'a live group is never reported empty');
            }
            $elapsed = (\hrtime(true) - $started) / 1_000_000_000;

            self::assertLessThan(2.0, $elapsed, '200 probes inside one cadence must cost one /proc walk, not 200');
        } finally {
            @\posix_kill(-$wrappedPid, 9);
            if (\is_resource($wrapped)) {
                \proc_close($wrapped);
            }
        }
    }

    /**
     * The cache must not pin a group as busy: once its last member is gone the
     * probe answers empty within a few cadences.
     */
    public function testGroupEmptyReportsADrainedGroupAfterTheCadence(): void
    {
        [$wrapped, $wrappedPid, $grandchild] = $this->spawnGroupWithTermIgnoringGrandchild($this->setsidOrSkip());

        try {
            self::assertFalse(BoundedShutdown::groupEmpty($wrappedPid));
            @\posix_kill($grandchild, 9);

            $empty = false;
            $deadline = \hrtime(true) / 1_000_000_000 + 3.0;
            while (!$empty && \hrtime(true) / 1_000_000_000 < $deadline) {
                $empty = BoundedShutdown::groupEmpty($wrappedPid);
                \usleep(50_000);
            }

            self::assertTrue($empty, 'a drained group must read as empty once the poll cadence has passed');
        } finally {
            @\posix_kill(-$wrappedPid, 9);
            if (\is_resource($wrapped)) {
                \proc_close($wrapped);
            }
        }
    }

    /**
     * Regression: the loop-driven ladders probe the group with `$walkProc` false,
     * which must never run or cache a /proc walk. A live group still answers
     * non-empty from the kernel's `kill(-pgid, 0)` alone, and a group with no
     * members answers empty at once.
     */
    public function testLoopSafeGroupProbeNeverWalksProc(): void
    {
        [$wrapped, $wrappedPid, $grandchild] = $this->spawnGroupWithTermIgnoringGrandchild($this->setsidOrSkip());
        $cache = new \ReflectionProperty(BoundedShutdown::class, 'groupScannedAt');

        try {
            $cache->setValue(null, []);
            for ($i = 0; $i < 200; $i++) {
                self::assertFalse(BoundedShutdown::groupEmpty($wrappedPid, false), 'a live group is never reported empty');
            }
            self::assertSame([], $cache->getValue(), 'the loop-safe probe must not run or cache a /proc scan');
            self::assertTrue(BoundedShutdown::groupEmpty(2147483000, false), 'a group with no members is empty');
        } finally {
            @\posix_kill(-$wrappedPid, 9);
            if (\is_resource($wrapped)) {
                \proc_close($wrapped);
            }
        }
    }

    private function setsidOrSkip(): string
    {
        self::requirePosix();
        $setsid = \SugarCraft\Core\Util\Executable\Locator::locate('setsid');
        if ($setsid === null) {
            self::markTestSkipped('setsid(1) is not installed on this host');
        }

        return $setsid;
    }

    /**
     * A setsid-wrapped shell whose background sleeper ignores SIGTERM. The
     * sleeper signals readiness only after its TERM disposition is set, so the
     * teardown can never race a sleeper that has not yet armed the trap.
     *
     * @return array{0: resource, 1: int, 2: int} the wrapper, its pid, and the grandchild pid
     */
    private function spawnGroupWithTermIgnoringGrandchild(string $setsid): array
    {
        $marker = \tempnam(\sys_get_temp_dir(), 'bounded-shutdown-');
        self::assertNotFalse($marker, 'could not create the grandchild marker file');
        $ready = $marker . '.ready';

        try {
            $wrapped = $this->spawn([
                $setsid,
                'sh',
                '-c',
                '(trap "" TERM; : > "$0.ready"; exec sleep 30) & echo $! > "$0"; wait',
                $marker,
            ]);
            $wrappedPid = (int) (\proc_get_status($wrapped)['pid'] ?? 0);

            $deadline = \hrtime(true) / 1_000_000_000 + 2.0;
            while (\posix_getpgid($wrappedPid) !== $wrappedPid && \hrtime(true) / 1_000_000_000 < $deadline) {
                \usleep(10_000);
            }
            while (!\is_file($ready) && \hrtime(true) / 1_000_000_000 < $deadline) {
                \usleep(10_000);
            }
            while (\trim((string) \file_get_contents($marker)) === '' && \hrtime(true) / 1_000_000_000 < $deadline) {
                \usleep(10_000);
            }
            self::assertFileExists($ready, 'the sleeper never armed its TERM trap');
            $grandchild = (int) \trim((string) \file_get_contents($marker));
            self::assertGreaterThan(0, $grandchild, 'the wrapper never reported its grandchild');
            self::assertTrue(self::stillRunning($grandchild), 'the grandchild must be alive before the teardown');
        } finally {
            @\unlink($marker);
            @\unlink($ready);
        }

        return [$wrapped, $wrappedPid, $grandchild];
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
