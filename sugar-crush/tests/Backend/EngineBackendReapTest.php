<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Support\ForkedChild;

/**
 * crush_code.md Phase 0 item 5: `completeAsync()`'s cancel teardown used to
 * call `pcntl_waitpid($pid, $status)` with no flags, immediately after a
 * `posix_kill()` that is `function_exists()`-guarded because ext-posix is not
 * guaranteed. In a build that has ext-pcntl but not ext-posix the child never
 * gets the SIGKILL, so that waitpid blocked forever inside a ReactPHP timer
 * callback - freezing the whole event loop in the Escape-Escape path whose
 * entire job is rescuing the user from a hung request.
 *
 * The posix-less build itself cannot be manufactured on a machine that has
 * ext-posix, so it is pinned the way the neighbouring guard already is: by its
 * shape in the source, plus a behavioural proof that the reap is bounded when
 * the child does NOT die - which is precisely the state a missing SIGKILL
 * leaves it in.
 */
final class EngineBackendReapTest extends TestCase
{
    /**
     * Wall-clock ceiling for a bounded reap. reapChild()'s own budget is
     * 20 x 5ms = 100ms; 1s leaves an order of magnitude of slack for a loaded
     * CI box while still being decisively less than the 2s the child below
     * stays alive for. An unbounded waitpid would blow straight past it.
     */
    private const BOUNDED_REAP_CEILING_SECONDS = 1.0;

    private const LIVE_CHILD_LIFETIME_MICROSECONDS = 2_000_000;

    private function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required to fork a real child.');
        }
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('posix is required to probe/clean up the child.');
        }
    }

    // -------------------------------------------------------------------------
    // Shape: the fix is where it has to be
    // -------------------------------------------------------------------------

    public function testCompleteAsyncNeverCallsWaitpidDirectly(): void
    {
        $source = self::methodSource(new \ReflectionMethod(EngineBackend::class, 'completeAsync'));

        $this->assertStringNotContainsString(
            'pcntl_waitpid(',
            $source,
            'a bare waitpid in completeAsync() blocks the event loop; reap via self::reapChild()',
        );
    }

    /**
     * The property, not the head-count: *every* way a turn can settle reaps
     * the child before it settles. Asserting a literal number of
     * `reapChild()` calls instead would fail the day a legitimate third
     * settle path is added - which is a change that should add a row here,
     * not break the suite.
     *
     * @dataProvider settlePaths
     */
    public function testEverySettlePathReapsBeforeItSettles(string $closure, string $settleCall): void
    {
        $source = self::methodSource(new \ReflectionMethod(EngineBackend::class, 'completeAsync'));

        $closureStart = strpos($source, $closure . ' = function');
        $this->assertIsInt($closureStart, $closure . ' is no longer built in completeAsync()');

        $settleAt = strpos($source, $settleCall, $closureStart);
        $this->assertIsInt($settleAt, $closure . ' no longer settles via ' . $settleCall);

        $this->assertStringContainsString(
            'self::reapChild(',
            substr($source, $closureStart, $settleAt - $closureStart),
            $closure . '() settles the promise without reaping the child first',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function settlePaths(): array
    {
        return [
            'cancel/timeout' => ['$teardown', '$deferred->reject('],
            'success' => ['$finalize', '$this->settleFromResultFrame('],
        ];
    }

    /**
     * The posix-less path, asserted the only way it can be on a posix-having
     * host: by the guard's presence, matching the shape of the `posix_kill()`
     * guard sitting three lines above the original bug.
     */
    public function testReapChildIsGuardedAndNonBlockingByConstruction(): void
    {
        $source = self::methodSource(new \ReflectionMethod(EngineBackend::class, 'reapChild'));

        $this->assertStringContainsString(
            "function_exists('pcntl_waitpid')",
            $source,
            'guard the reap the same way the neighbouring posix_kill() call is guarded',
        );
        $this->assertStringContainsString(
            'WNOHANG',
            $source,
            'without WNOHANG the reap blocks whenever the SIGKILL did not land',
        );
        $this->assertMatchesRegularExpression(
            '/for \(|while \(/',
            $source,
            'WNOHANG alone reaps nothing; it needs a bounded retry loop',
        );
    }

    public function testTheCancelTeardownStillKillsTheChildUnderAPosixGuard(): void
    {
        $source = self::methodSource(new \ReflectionMethod(EngineBackend::class, 'completeAsync'));

        // Regression guard for the fix itself: switching to a non-blocking
        // reap is only safe while the kill attempt survives, otherwise a
        // cancelled turn leaks a running child on every posix-having host too.
        $this->assertStringContainsString("function_exists('posix_kill')", $source);
        $this->assertStringContainsString('posix_kill($pid, SIGKILL)', $source);
    }

    // -------------------------------------------------------------------------
    // Behaviour: bounded, and still a real reap
    // -------------------------------------------------------------------------

    /**
     * The posix-less scenario reproduced without a posix-less machine: a child
     * that was never killed and is still running. The old unflagged waitpid
     * would sit here for the child's full lifetime (in production: forever,
     * since the child is wedged in a provider read with no timeout).
     */
    public function testReapChildGivesUpQuicklyOnAChildThatWasNotKilled(): void
    {
        $this->requireFork();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('fork() failed on this host.');
        }
        if ($pid === 0) {
            usleep(self::LIVE_CHILD_LIFETIME_MICROSECONDS);
            ForkedChild::exitNow(0);
        }

        try {
            $started = microtime(true);
            self::reapChild($pid);
            $elapsed = microtime(true) - $started;

            $this->assertLessThan(
                self::BOUNDED_REAP_CEILING_SECONDS,
                $elapsed,
                'reapChild() blocked on a live child - that is the event-loop freeze',
            );
            $this->assertTrue(
                posix_kill($pid, 0),
                'setup check: the child must still have been alive, or this proved nothing',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            self::reapChild($pid);
        }
    }

    public function testReapChildActuallyReapsAnExitedChild(): void
    {
        $this->requireFork();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('fork() failed on this host.');
        }
        if ($pid === 0) {
            ForkedChild::exitNow(0);
        }

        self::reapChild($pid);

        // -1 == ECHILD: nothing left to wait on, i.e. no zombie was leaked.
        $status = 0;
        $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
    }

    public function testReapChildReturnsImmediatelyForAPidThatIsNotOurChild(): void
    {
        $this->requireFork();

        // PID 1 is never a child of this process, so waitpid reports ECHILD on
        // the first poll - the terminal case the loop must not spin through.
        $started = microtime(true);
        self::reapChild(1);

        $this->assertLessThan(self::BOUNDED_REAP_CEILING_SECONDS, microtime(true) - $started);
    }

    // -------------------------------------------------------------------------
    // The straggler sweep: reapChild()'s budget is finite, so something has to
    // collect what it gave up on
    // -------------------------------------------------------------------------

    public function testAChildReapChildGaveUpOnStaysTrackedAndIsSweptLater(): void
    {
        $this->requireFork();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('fork() failed on this host.');
        }
        if ($pid === 0) {
            usleep(self::LIVE_CHILD_LIFETIME_MICROSECONDS);
            ForkedChild::exitNow(0);
        }

        $tracked = new \ReflectionProperty(EngineBackend::class, 'unreapedChildren');
        $tracked->setAccessible(true);
        $previous = $tracked->getValue();

        try {
            // Stand in for completeAsync()'s own registration right after the fork.
            $tracked->setValue(null, [$pid => true]);

            // Outlives the 100ms budget, so this reap must give up...
            self::reapChild($pid);
            $this->assertArrayHasKey($pid, $tracked->getValue(), 'a child reapChild() gave up on must stay tracked, or nothing will ever collect it');

            posix_kill($pid, SIGKILL);
            // ...and the next turn's sweep must collect it.
            self::waitUntilExited($pid);
            self::sweepUnreapedChildren();

            $this->assertArrayNotHasKey($pid, $tracked->getValue());
            $status = 0;
            $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG), 'the sweep left a zombie behind');
        } finally {
            posix_kill($pid, SIGKILL);
            $tracked->setValue(null, $previous);
        }
    }

    /**
     * A blanket `pcntl_waitpid(-1, ..., WNOHANG)` would have been cheaper to
     * write and actively harmful: Chat::executeToolsParallel() and
     * BackgroundSessionRunner both wait on their OWN pids in this same
     * process and branch on the returned pid, so a blind sweep would steal
     * their exit statuses.
     */
    public function testTheSweepOnlyTouchesChildrenThisBackendForked(): void
    {
        $this->requireFork();

        $source = self::methodSource(new \ReflectionMethod(EngineBackend::class, 'sweepUnreapedChildren'));
        $this->assertStringNotContainsString('pcntl_waitpid(-1', $source);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('fork() failed on this host.');
        }
        if ($pid === 0) {
            ForkedChild::exitNow(0);
        }

        $tracked = new \ReflectionProperty(EngineBackend::class, 'unreapedChildren');
        $tracked->setAccessible(true);
        $previous = $tracked->getValue();

        try {
            // This pid is somebody else's business: it is NOT registered.
            $tracked->setValue(null, []);
            self::waitUntilExited($pid);
            self::sweepUnreapedChildren();

            $status = 0;
            $this->assertSame(
                $pid,
                pcntl_waitpid($pid, $status, WNOHANG),
                'the sweep reaped an untracked child - its real owner would have seen ECHILD',
            );
        } finally {
            $tracked->setValue(null, $previous);
        }
    }

    public function testCompleteAsyncSweepsBeforeItForks(): void
    {
        $source = self::methodSource(new \ReflectionMethod(EngineBackend::class, 'completeAsync'));

        $sweepAt = strpos($source, 'self::sweepUnreapedChildren(');
        $forkAt = strpos($source, 'pcntl_fork()');

        $this->assertIsInt($sweepAt, 'completeAsync() must sweep stragglers from earlier turns');
        $this->assertIsInt($forkAt);
        $this->assertLessThan($forkAt, $sweepAt);
        $this->assertStringContainsString(
            'self::$unreapedChildren[$pid] = true;',
            $source,
            'a forked child that is never registered can never be swept',
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Polls (never blocks) until $pid is a zombie, so the sweep assertions
     * above test the sweep rather than scheduling luck.
     *
     * `posix_kill($pid, 0)` cannot answer this: an exited-but-unreaped child
     * is still a live process entry and answers that probe. procfs can, and
     * the pid is our own unreaped child so its /proc entry cannot vanish
     * underneath us. Off procfs, settle briefly and let the caller's own
     * assertion be the judge.
     */
    private static function waitUntilExited(int $pid): void
    {
        $stat = '/proc/' . $pid . '/stat';

        if (!is_file($stat)) {
            usleep(100_000);

            return;
        }

        for ($i = 0; $i < 400; $i++) {
            // "<pid> (<comm>) <state> ..." - state Z is exited-and-waitable.
            if (preg_match('/\)\s+(\S)/', (string) @file_get_contents($stat), $m) === 1 && $m[1] === 'Z') {
                return;
            }
            usleep(5_000);
        }
    }

    private static function sweepUnreapedChildren(): void
    {
        $method = new \ReflectionMethod(EngineBackend::class, 'sweepUnreapedChildren');
        $method->setAccessible(true);
        $method->invoke(null);
    }

    private static function reapChild(int $pid): void
    {
        $method = new \ReflectionMethod(EngineBackend::class, 'reapChild');
        $method->setAccessible(true);
        $method->invoke(null, $pid);
    }

    private static function methodSource(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName(), FILE_IGNORE_NEW_LINES);
        $start = (int) $method->getStartLine() - 1;

        return implode("\n", array_slice((array) $lines, $start, (int) $method->getEndLine() - $start));
    }
}
