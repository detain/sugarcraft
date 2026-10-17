<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Proc;

use PHPUnit\Framework\TestCase;
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
}
