<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Prompt\Support\BoundedReaper;

/**
 * E711 (round 82): the bounded TERM→KILL ladder the spinner fork path runs.
 *
 * Every fixture here is a REAL forked child — the ladder's whole contract is
 * wait-status observation, so a double could not test any of it. Children
 * carry a self-cap (they exit on their own after a few seconds) so a
 * regression that removes a rung produces a late RED, never an orphan that
 * outlives the suite; the parent side only ever uses WNOHANG polls.
 */
final class BoundedReaperTest extends TestCase
{
    /** Seconds a fixture child outlives its own point — the orphan leash. */
    private const CHILD_SELF_CAP_SECONDS = 8.0;

    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        if (\function_exists('pcntl_fork') === FALSE || \function_exists('posix_kill') === FALSE) {
            self::markTestSkipped('pcntl and posix required to exercise the signal ladder');
        }
    }

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/q1-reaper-' . \uniqid('', TRUE);
        \mkdir($this->tempDir, 0700, TRUE);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tempDir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->tempDir);
    }

    public function testATermObeyingChildDiesOnTheFirstRungNotTheSecond(): void
    {
        // Default SIGTERM disposition terminates the child; the ladder must
        // land on rung one. If the TERM rung is neutered the child dies to 9
        // instead (wtermsig flips to 9 => red) and only after the full term
        // budget has been burned (elapsed pin => second red).
        $pid = $this->forkSleepingChild(termIgnoring: FALSE);

        $started = \microtime(true);
        $status = BoundedReaper::escalatePid($pid, 1.0, 1.0);
        $elapsed = \microtime(true) - $started;

        self::assertIsInt($status, 'a TERM-obeying child must be reaped within the term budget');
        self::assertTrue(\pcntl_wifsignaled($status), 'the child was signalled, not exited');
        self::assertSame(15, \pcntl_wtermsig($status), 'the TERM rung must be what lands');
        self::assertLessThan(0.9, $elapsed, 'an obedient child dies on rung one, not after burning the term budget');
    }

    public function testATermIgnoringChildIsEscalatedToSigkill(): void
    {
        // The wedge the whole class exists for: a child that shrugs off
        // SIGTERM. Removing the KILL rung here returns null => red.
        // The ready-file matters: without the handshake, escalatePid's
        // first TERM can land microseconds after fork — BEFORE the child
        // installs SIG_IGN — and the default disposition kills it (the
        // ladder then "wins" on the wrong rung and the measurement is a
        // coin flip). The test must wait until the wedge is actually up.
        $ready = $this->tempDir . '/ready';
        $pid = $this->forkSleepingChild(termIgnoring: TRUE, readyFile: $ready);

        $started = \microtime(true);
        $status = BoundedReaper::escalatePid($pid, 0.3, 1.0);
        $elapsed = \microtime(true) - $started;

        self::assertIsInt($status, 'a TERM-ignoring child must still be reaped via the KILL rung');
        self::assertTrue(\pcntl_wifsignaled($status));
        self::assertSame(9, \pcntl_wtermsig($status), 'escalation must finish with the uncatchable signal');
        self::assertGreaterThanOrEqual(0.25, $elapsed, 'the term budget is spent before escalation — the ladder does not skip its polite rung');
        self::assertLessThan(2.5, $elapsed);
    }

    public function testTheLadderReportsTheWaitStatusItReapsInsteadOfSwallowingIt(): void
    {
        // A child that ALREADY exited is reaped on the very first poll and
        // its raw status must ride back to the caller untouched (Spinner
        // reads exit codes through it). Returning 0 — or anything else —
        // instead of the real status reddens this immediately.
        $pid = \pcntl_fork();
        self::assertNotFalse($pid);
        if ($pid === 0) {
            exit(7);
        }

        \usleep(100_000); // let the child become a reappable zombie first

        $status = BoundedReaper::escalatePid($pid, 0.5, 0.5);

        self::assertIsInt($status);
        self::assertTrue(\pcntl_wifexited($status), 'the ladder reports what the kernel reported');
        self::assertSame(7, \pcntl_wexitstatus($status));
    }

    public function testZeroReapBudgetsReturnAtOnceAndStillDeliverTheKill(): void
    {
        // "NEVER AN UNSFLAGGED WAIT, ON ANY PATH": with no poll budget the
        // ladder must come back immediately (a blocking wait would hang
        // this test forever, which is exactly the defect class) while the
        // KILL rung has still been delivered. Whether the corpse is reaped
        // inside the ladder or right after it is a scheduling race, so the
        // return value is deliberately not pinned — the delivery is.
        $pid = $this->forkSleepingChild(termIgnoring: TRUE, readyFile: $this->tempDir . '/ready-4');

        $started = \microtime(true);
        $status = BoundedReaper::escalatePid($pid, 0.001, 0.001);
        self::assertLessThan(0.5, \microtime(true) - $started, 'the ladder may only ever bound-wait');

        if ($status === null) {
            $status = $this->reapWithin($pid, 3.0);
        }

        self::assertIsInt($status, 'the SIGKILL was delivered — the child cannot survive the ladder');
        self::assertTrue(\pcntl_wifsignaled($status));
        self::assertSame(9, \pcntl_wtermsig($status));
    }

    private function forkSleepingChild(bool $termIgnoring, ?string $readyFile = null): int
    {
        $selfCap = self::CHILD_SELF_CAP_SECONDS;
        $pid = \pcntl_fork();
        self::assertNotFalse($pid);
        if ($pid === 0) {
            if ($termIgnoring === TRUE) {
                \pcntl_signal(\SIGTERM, \SIG_IGN);
            }
            if ($readyFile !== null) {
                \file_put_contents($readyFile, '1');
            }
            // Self-cap: even a fully neutered ladder cannot orphan a
            // sleeper past the suite.
            \usleep((int) ($selfCap * 1_000_000));
            exit(0);
        }

        if ($readyFile !== null) {
            // Bounded wait for the wedge to be installed (see the race
            // note in testATermIgnoringChildIsEscalatedToSigkill).
            $deadline = \microtime(true) + 5.0;
            while (\file_exists($readyFile) === FALSE) {
                if (\microtime(true) >= $deadline) {
                    self::fail('fixture child never became ready');
                }
                \usleep(5_000);
            }
        }

        return $pid;
    }

    /**
     * Bounded WNOHANG drain for cleanup paths — fails (never hangs) if the
     * child is somehow still alive after the budget.
     */
    private function reapWithin(int $pid, float $budget): ?int
    {
        $deadline = \microtime(true) + $budget;
        do {
            $status = 0;
            $check = @\pcntl_waitpid($pid, $status, \WNOHANG);
            if ($check === $pid) {
                return $status;
            }
            if ($check === -1) {
                return null;
            }
            \usleep(10_000);
        } while (\microtime(true) < $deadline);

        self::fail('fixture child ' . $pid . ' survived its self-cap window');
    }
}
