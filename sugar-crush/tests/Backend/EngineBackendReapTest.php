<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;
use SugarCraft\Crush\Tests\Support\SlicesDeclaredMethodsTrait;

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
    use ReapsForkedChildrenTrait;
    use SlicesDeclaredMethodsTrait;

    /**
     * The four children below are each waited for by the test that forked
     * them, so on the PASSING path this reaper collects nothing.
     *
     * It is here for the path where an assertion between the fork and the
     * wait fails, and for the one that has no other net at all: `phpunit.xml`
     * sets `enforceTimeLimit` with `defaultTimeLimit="60"`, which PHPUnit
     * implements as `pcntl_alarm()` plus a `SIGALRM` handler - and an alarm
     * is not inherited across `pcntl_fork()`. So an abort here aborts exactly
     * one of the processes this file put on the machine, the parent, and two
     * of these children are alive for two full seconds with nothing left
     * holding a clock on them. {@see ReapsForkedChildrenTrait} has the whole
     * mechanism.
     *
     * FIRST STATEMENT, and that ordering is the point rather than a style
     * choice: anything above it that tore state down would run while the
     * orphans were still using it.
     */
    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();

        parent::tearDown();
    }

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

        $pid = $this->forkTracked();
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

        $pid = $this->forkTracked();
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

        $deathPipe = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($deathPipe === false) {
            $this->markTestSkipped('stream_socket_pair() failed on this host.');
        }

        $pid = $this->forkTracked();
        if ($pid === -1) {
            fclose($deathPipe[0]);
            fclose($deathPipe[1]);
            $this->markTestSkipped('fork() failed on this host.');
        }
        if ($pid === 0) {
            fclose($deathPipe[0]);
            usleep(self::LIVE_CHILD_LIFETIME_MICROSECONDS);
            ForkedChild::exitNow(0);
        }
        fclose($deathPipe[1]);

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
            self::waitUntilExited($pid, $deathPipe[0]);
            // The sweep reaps with ONE WNOHANG per pid, so drive it until the
            // tracked map actually drops the pid: the observed condition is
            // the sweep's own reap landing, and the bound fails red instead
            // of spinning if it never does. (Between the pipe's EOF and the
            // zombie becoming waitable the kernel is still inside do_exit;
            // WNOHANG answers 0 - "not yet" - for exactly that window.)
            $sweepDeadline = microtime(true) + 2.0;
            do {
                self::sweepUnreapedChildren();
            } while (\array_key_exists($pid, $tracked->getValue()) && microtime(true) < $sweepDeadline);

            $this->assertArrayNotHasKey($pid, $tracked->getValue());
            $status = 0;
            $this->assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG), 'the sweep left a zombie behind');
        } finally {
            posix_kill($pid, SIGKILL);
            fclose($deathPipe[0]);
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

        $deathPipe = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($deathPipe === false) {
            $this->markTestSkipped('stream_socket_pair() failed on this host.');
        }

        $pid = $this->forkTracked();
        if ($pid === -1) {
            fclose($deathPipe[0]);
            fclose($deathPipe[1]);
            $this->markTestSkipped('fork() failed on this host.');
        }
        if ($pid === 0) {
            fclose($deathPipe[0]);
            ForkedChild::exitNow(0);
        }
        fclose($deathPipe[1]);

        $tracked = new \ReflectionProperty(EngineBackend::class, 'unreapedChildren');
        $tracked->setAccessible(true);
        $previous = $tracked->getValue();

        try {
            // This pid is somebody else's business: it is NOT registered.
            $tracked->setValue(null, []);
            self::waitUntilExited($pid, $deathPipe[0]);
            self::sweepUnreapedChildren();

            // The pending reap is STILL MINE, so waitpid must answer with the
            // pid; anything else - especially -1 - is the sweep having stolen
            // it. Only the 0 of "kernel still inside do_exit between the
            // pipe's EOF and the zombie going waitable" is retried, bounded:
            // a pump on the observed condition, not a settle.
            $status = 0;
            $reapDeadline = microtime(true) + 2.0;
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);
            while ($reaped === 0 && microtime(true) < $reapDeadline) {
                usleep(5_000);
                $reaped = pcntl_waitpid($pid, $status, WNOHANG);
            }

            $this->assertSame(
                $pid,
                $reaped,
                'the sweep reaped an untracked child - its real owner would have seen ECHILD',
            );
        } finally {
            fclose($deathPipe[0]);
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
     * Polls (never blocks) until $pid is exited AND waitable, so the sweep
     * assertions above test the sweep rather than scheduling luck.
     *
     * `posix_kill($pid, 0)` cannot answer this: an exited-but-unreaped child
     * is still a live process entry and answers that probe. procfs can, and
     * the pid is our own unreaped child so its /proc entry cannot vanish
     * underneath us.
     *
     * Off procfs the observable is a pipe: the child inherits one end of a
     * `stream_socket_pair()` and any death - clean exit, self-SIGKILL through
     * {@see ForkedChild::exitNow()}, or an external SIGKILL - closes the fd
     * table and the parent's read hits EOF. That is an observed condition the
     * bounded pump waits ON (and fails red when the child never dies), not a
     * settle it trusts. The residual kernel transition from "fds released" to
     * "waitable" is absorbed by the CALLERS' own bounded pumps - the sweep
     * re-checks the tracked map, the raw waitpid re-checks WNOHANG - so no
     * blind nap is needed here either.
     */
    private static function waitUntilExited(int $pid, mixed $childPipe = null): void
    {
        $stat = '/proc/' . $pid . '/stat';

        if (is_file($stat)) {
            for ($i = 0; $i < 400; $i++) {
                // "<pid> (<comm>) <state> ..." - state Z is exited-and-waitable.
                if (preg_match('/\)\s+(\S)/', (string) @file_get_contents($stat), $m) === 1 && $m[1] === 'Z') {
                    return;
                }
                usleep(5_000);
            }

            self::fail(sprintf(
                'child %d never became a zombie within the bounded 2s poll - the exit/SIGKILL '
                . 'did not take effect, so every assertion after this one would measure a live child',
                $pid,
            ));
        }

        if (!\is_resource($childPipe)) {
            throw new \LogicException(
                'waitUntilExited() without procfs has no state to poll unless the caller '
                . 'hands it the child end of a socket pair to watch for EOF.'
            );
        }

        stream_set_blocking($childPipe, false);
        $deadline = microtime(true) + 2.0;
        while (true) {
            $chunk = \fread($childPipe, 1024);
            if ($chunk === '' && \feof($childPipe)) {
                return; // the write end is closed: the child is gone.
            }
            if (microtime(true) > $deadline) {
                self::fail(sprintf(
                    'child %d never closed its socket-pair end within the bounded 2s poll '
                    . '(last read: %s) - it is still running',
                    $pid,
                    $chunk === false ? 'read error' : var_export($chunk, true),
                ));
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
        // Routed through the shared guard (E325): the slice now keeps the
        // trailing newlines file() carries, which every consumer here is
        // insensitive to - they all match inside a single line.
        return self::declaredSlice(
            (array) file((string) $method->getFileName()),
            $method->getName(),
            (int) $method->getStartLine(),
            (int) $method->getEndLine(),
        );
    }
}
