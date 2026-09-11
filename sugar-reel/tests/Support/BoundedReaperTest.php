<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Support\BoundedReaper;

/**
 * Drives every rung of {@see BoundedReaper} against REAL children, because
 * each assertion is a statement about what the kernel reports afterwards —
 * the same instrument E366 used to measure that bare `proc_close()` waits
 * and that a dropped handle abandons.
 *
 * The stubborn fixture is a child that installs a no-op handler for SIGTERM
 * (via pcntl in the child — same binary, extension present or the test
 * skips honestly) and then loops forever: polite exit and TERM are both
 * unavailable to it, so only escalation can end it, and only a bounded
 * ladder can end it in bounded time. Each stubborn arm additionally arms a
 * `sleep 25; pkill -9 -f MARKER` watchdog: a regression that removes the
 * KILL rung turns `proc_close()` into an infinite wait, and the watchdog is
 * what makes that failure a red test rather than a wedged suite.
 */
final class BoundedReaperTest extends TestCase
{
    private const MARKER = 'sugar-reel-bounded-reaper-child';

    /** @var resource|null */
    private $watchdog = null;

    protected function tearDown(): void
    {
        foreach ($this->readyFiles as $ready) {
            if (\is_file($ready)) {
                \unlink($ready);
            }
        }
        $this->readyFiles = [];
        if ($this->watchdog !== null && is_resource($this->watchdog)) {
            \proc_terminate($this->watchdog);
            \proc_close($this->watchdog);
        }
        $this->watchdog = null;
    }

    public function testTerminateNowEscalatesPastASigtermIgnoringChildAndConfirmsTheExit(): void
    {
        [$child] = $this->spawnStubborn();
        $this->armWatchdog();
        $pid = (int) \proc_get_status($child)['pid'];
        $this->assertTrue(\posix_kill($pid, 0), 'the fixture child must be alive');

        $start = \microtime(true);
        BoundedReaper::terminateNow($child);
        $elapsed = \microtime(true) - $start;

        // TERM window (1s) + KILL-confirm window, with slack. Without the
        // KILL rung this is the shape the watchdog exists to unblock at 25s.
        $this->assertLessThan(
            5.0,
            $elapsed,
            'terminateNow must bound the wait even against a SIGTERM-ignoring child',
        );
        $this->assertFalse(
            (bool) (\proc_get_status($child)['running'] ?? false),
            'after terminateNow the child must be exited (and waitpid-reaped by proc_get_status)',
        );

        $this->assertIsInt(\proc_close($child), 'the following proc_close must reap, not wait');
        $this->assertFalse(\posix_kill($pid, 0), 'no child may survive the escalation');
    }

    public function testTerminateAfterGraceReleasesAChildThatExitsOnItsOwnWithoutWaitingTheGraceOut(): void
    {
        $child = $this->spawn('usleep(120000); exit(0);');

        $start = \microtime(true);
        BoundedReaper::terminateAfterGrace($child);
        $elapsed = \microtime(true) - $start;

        $this->assertTrue(
            BoundedReaper::hasExited($child, 0.0),
            'a cooperative child must be reported exited by the grace rung',
        );
        $this->assertLessThan(
            BoundedReaper::GRACE_SECONDS,
            $elapsed,
            'the grace window is a ceiling, not a sleep — an early exit must return early',
        );
        \proc_close($child);
    }

    public function testTerminateAfterGraceStillEscalatesWhenTheGraceBuysNothing(): void
    {
        [$child] = $this->spawnStubborn();
        $this->armWatchdog();

        $start = \microtime(true);
        BoundedReaper::terminateAfterGrace($child);
        $elapsed = \microtime(true) - $start;

        // grace (1.5s) + TERM (1s) + KILL-confirm, with slack; the whole
        // point is that this stays finite no matter what the child thinks.
        $this->assertLessThan(
            6.0,
            $elapsed,
            'grace, then TERM, then KILL must each be bounded and each be allowed to fail forward',
        );
        $this->assertFalse((bool) (\proc_get_status($child)['running'] ?? false));
        \proc_close($child);
    }

    public function testTerminateOnAnAlreadyExitedChildAnswersImmediately(): void
    {
        $child = $this->spawn('exit(0);');
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline && (bool) (\proc_get_status($child)['running'] ?? false)) {
            \usleep(20_000);
        }

        $start = \microtime(true);
        BoundedReaper::terminateNow($child);
        $elapsed = \microtime(true) - $start;

        $this->assertLessThan(
            0.5,
            $elapsed,
            'a dead child must be recognised on the first tick — no signal ladder for a corpse',
        );
        \proc_close($child);
    }

    // ─── harness ────────────────────────────────────────────────────────────

    /** @var list<string> ready files to unlink in tearDown */
    private array $readyFiles = [];

    /**
     * Spawn the TERM-ignoring forever-child and return it ONLY once it has
     * declared its handler installed.
     *
     * The child runs `pcntl_signal(SIGTERM, SIG_IGN)` as its first statement
     * and touches a ready file immediately after. Without that handshake
     * there is a real window — proc_open() returns as soon as the kernel has
     * the child, microseconds before PHP even begins to bootstrap it — and a
     * TERM landing in that window kills a child whose stubbornness was never
     * installed: the escalation would then pass for a reason it did not
     * earn. MEASURED on this runner: the un-gated fixture raced exactly that
     * way under phpunit.
     *
     * @return array{0:resource,1:string} [child, readyPath]
     */
    private function spawnStubborn(): array
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('the stubborn-child fixture needs ext-pcntl in the child');
        }

        $ready = \sys_get_temp_dir() . '/df-br-' . \getmypid() . '-' . \count($this->readyFiles) . '.ready';
        $this->readyFiles[] = $ready;
        $child = $this->spawn(
            'if (function_exists("pcntl_signal")) { pcntl_signal(SIGTERM, SIG_IGN); }'
            . " \$rd = '" . $ready . "'; touch(\$rd); // " . self::MARKER . "\n"
            . 'while (true) { usleep(50000); }',
        );

        $deadline = \microtime(true) + 5.0;
        while (!\file_exists($ready) && \microtime(true) < $deadline) {
            \usleep(10_000);
        }
        $this->assertFileExists($ready, 'the stubborn fixture never reached its signal-handler line');

        return [$child, $ready];
    }

    /** @return resource the php child running $code with stdout/stderr as pipes */
    private function spawn(string $code)
    {
        return \proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        // pipes deliberately unclosed: the short-lived fixtures hold two
        // pipes each and proc_close reaps them with the child.
    }

    private function armWatchdog(): void
    {
        $this->watchdog = \proc_open(
            ['sh', '-c', 'sleep 25; pkill -9 -f ' . self::MARKER],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($this->watchdog);
        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
    }
}
