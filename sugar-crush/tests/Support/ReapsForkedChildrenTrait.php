<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * A per-test ledger of the pids a test forked itself, and a reaper that runs
 * from `tearDown()` and SIGKILLs whichever of them are still alive.
 *
 * WHY THIS EXISTS. `phpunit.xml` sets `enforceTimeLimit="true"` with
 * `defaultTimeLimit="60"`, which PHPUnit implements through
 * `SebastianBergmann\Invoker\Invoker`: `pcntl_alarm()` plus a `SIGALRM`
 * handler that throws `TimeoutException`. `pcntl_alarm()` fires in the
 * process that armed it and NOWHERE ELSE - an alarm is not inherited across
 * `pcntl_fork()`. So when a test that has forked hits the limit, exactly one
 * process is aborted: the parent. Its children keep running, unbounded, with
 * no clock on them at all.
 *
 * That is not a theoretical leak, and the mechanism was verified against this
 * vendored copy rather than assumed. `TestCase::runBare()` catches
 * `TimeoutException` and SWALLOWS it (`} catch (TimeoutException $e) {}`),
 * then goes on to `invokeAfterTestHookMethods()` - so `tearDown()` runs
 * normally on the abort path. A `tearDown()` that removes the test's temp
 * tree therefore deletes the very directory the orphaned children are still
 * writing into, and the orphans go on producing partial files, marker
 * directories and IPC payloads underneath whichever test runs next. E80's
 * observed failure had exactly that shape and read as lock starvation for
 * four rounds.
 *
 * Because `tearDown()` does run on the abort path, a reaper called from
 * `tearDown()` closes the gap without touching `phpunit.xml` (which is not a
 * per-test knob anyway) - the cheap ninety percent. It does NOT bound a child
 * that outlives the whole PHPUnit process; nothing inside a test can.
 *
 * WHAT IT WILL NOT DO. It kills pids THIS ledger recorded in THIS process and
 * nothing else. Three lanes run suites against this tree concurrently, so a
 * blanket `pkill` - or any kill derived from a process listing rather than
 * from a `pcntl_fork()` return value - is an attack on somebody else's run.
 * The owner pid is captured with the first record and re-checked before every
 * signal, so an inherited copy of the ledger in a forked child (whose entries
 * are that child's SIBLINGS, not its children) can never fire. That is the
 * SECOND line of defence, and it is the one that matters: `forkTracked()`
 * empties the child's copy, so the first line holds for every child forked
 * through the trait and the owner check is never reached. It exists for the
 * child forked with a RAW `pcntl_fork()` from a class that uses this trait,
 * which inherits a POPULATED ledger - and until
 * {@see ReapsForkedChildrenTraitTest::testAChildForkedOutsideTheTraitCannotReapTheLedgerItInherited()}
 * that sentence was a claim no test made: deleting the owner check left the
 * whole suite green.
 */
trait ReapsForkedChildrenTrait
{
    /** @var array<int,true> pid => tracked */
    private array $trackedForkedChildren = [];

    /** The pid that owns the ledger; 0 until the first record. */
    private int $trackedForkedChildrenOwner = 0;

    /**
     * `pcntl_fork()` with the child's pid recorded in the parent.
     *
     * Returns exactly what `pcntl_fork()` returns - 0 in the child, the child
     * pid in the parent, -1 on failure - so a call site swaps one for the
     * other and keeps its own `=== 0` / `=== -1` handling.
     *
     * The CHILD clears the ledger it inherited. Those entries are its
     * siblings; reaping them would be a test killing processes it never
     * created, which is the one thing this trait exists to never do.
     */
    protected function forkTracked(): int
    {
        $pid = \pcntl_fork();

        if ($pid === 0) {
            $this->trackedForkedChildren = [];
            $this->trackedForkedChildrenOwner = 0;

            return 0;
        }

        if ($pid > 0) {
            $this->trackForkedChild($pid);
        }

        return $pid;
    }

    /**
     * Record a pid this test forked through some other route (a helper that
     * already owns the `pcntl_fork()` call, say). Returns $pid unchanged so it
     * can wrap an existing expression.
     */
    protected function trackForkedChild(int $pid): int
    {
        if ($pid <= 0) {
            return $pid;
        }

        if ($this->trackedForkedChildrenOwner === 0) {
            $this->trackedForkedChildrenOwner = \function_exists('posix_getpid') ? \posix_getpid() : (int) \getmypid();
        }

        $this->trackedForkedChildren[$pid] = true;

        return $pid;
    }

    /**
     * Drop a pid from the ledger - for a call site that has already waited on
     * it and wants the reaper to say nothing about it either way. Optional:
     * an already-reaped pid is a no-op for the reaper regardless, because
     * `pcntl_waitpid()` answers ECHILD for it.
     */
    protected function forgetForkedChild(int $pid): void
    {
        unset($this->trackedForkedChildren[$pid]);
    }

    /**
     * Reap every tracked pid, SIGKILLing the ones still alive after a short
     * grace period, and clear the ledger.
     *
     * Call it from `tearDown()`. Returns the pids that had to be KILLED (not
     * the ones that had already exited), so a caller that wants to assert
     * "nothing was left running" can - the reaper itself deliberately does
     * not assert, because it also runs on the abort path where a survivor is
     * the expected consequence of the abort rather than a defect in the test.
     *
     * @return list<int>
     */
    protected function reapTrackedForkedChildren(float $graceSeconds = 0.25): array
    {
        $pids = array_keys($this->trackedForkedChildren);
        $this->trackedForkedChildren = [];

        $self = \function_exists('posix_getpid') ? \posix_getpid() : (int) \getmypid();
        if ($pids === [] || $this->trackedForkedChildrenOwner !== $self) {
            return [];
        }

        if (!\function_exists('pcntl_waitpid')) {
            return [];
        }

        /** @var list<int> $killed */
        $killed = [];
        $deadline = microtime(true) + max(0.0, $graceSeconds);

        // One bounded polling pass over the whole set rather than a grace
        // period each: N children exiting concurrently should cost one grace
        // period, not N of them.
        $pending = $pids;
        while ($pending !== [] && microtime(true) < $deadline) {
            $pending = array_values(array_filter(
                $pending,
                static function (int $pid): bool {
                    $status = 0;

                    // 0 = alive, $pid = just reaped, -1 = ECHILD (someone
                    // else's waitpid got there first, or it was never ours).
                    return \pcntl_waitpid($pid, $status, WNOHANG) === 0;
                },
            ));

            if ($pending !== []) {
                usleep(5_000);
            }
        }

        foreach ($pending as $pid) {
            if ($pid <= 0 || $pid === $self) {
                continue;
            }

            if (\function_exists('posix_kill') && \defined('SIGKILL')) {
                @\posix_kill($pid, \SIGKILL);
                $killed[] = $pid;

                // Blocking, and only ever reached once the child has been
                // SIGKILLed: it collects the corpse rather than waiting for
                // the child to finish what it was doing.
                $status = 0;
                \pcntl_waitpid($pid, $status);

                continue;
            }

            // NO WAY TO SIGNAL, so no way to bound the wait. ext-pcntl
            // without ext-posix is an unusual build but not an impossible
            // one, and the blocking waitpid above was originally outside the
            // guard: on that build the reaper would sit in tearDown() for as
            // long as a live child chose to run, with the per-test alarm
            // already spent. WNOHANG still collects an already-exited child
            // so the ordinary case leaves no zombie; a live one is reported
            // by its absence from $killed rather than waited out.
            $status = 0;
            \pcntl_waitpid($pid, $status, \WNOHANG);
        }

        return $killed;
    }
}
