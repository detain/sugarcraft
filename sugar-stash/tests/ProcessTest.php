<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\Process;
use PHPUnit\Framework\TestCase;

final class ProcessTest extends TestCase
{
    /**
     * Regression: a wedged child must be killed at the deadline, not run to
     * completion. Reverting the stream_select/proc_terminate timeout makes this
     * block for the full `sleep 3` and blow the elapsed-time assertion.
     */
    public function testTimesOutAndKillsSlowCommand(): void
    {
        $start = microtime(true);
        $r = Process::run(['sleep', '3'], null, 0.2);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($r['timedOut'], 'slow command should report timedOut');
        $this->assertLessThan(
            2.0,
            $elapsed,
            'the child should be killed near the 0.2s timeout, not allowed to sleep for 3s',
        );
    }

    public function testRunsFastCommandToCompletion(): void
    {
        $r = Process::run(['git', '--version'], null, 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(0, $r['exit']);
        $this->assertStringContainsString('git', $r['stdout']);
    }

    public function testWritesStdinAndCapturesStdout(): void
    {
        $r = Process::run(['cat'], "payload\n", 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(0, $r['exit']);
        $this->assertSame("payload\n", $r['stdout']);
    }

    public function testReportsNonZeroExitWithoutTimingOut(): void
    {
        $r = Process::run(['sh', '-c', 'exit 7'], null, 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(7, $r['exit']);
    }

    /**
     * Regression (audit #6): a 200KB stdin against a child that exits without
     * ever reading it used to be one blocking fwrite past the 64KiB pipe
     * capacity — EPIPE warning at best, wedge-the-thread deadlock at worst.
     * The write side now rides the same select loop, so the refusal is silent
     * and the child's exit code still arrives.
     */
    public function testLargeStdinToChildThatExitsEarlyDoesNotDeadlock(): void
    {
        $start = microtime(true);
        $r     = Process::run(['sh', '-c', 'exit 3'], str_repeat('x', 200_000), 5.0);

        $this->assertFalse($r['timedOut'], 'early-exiting child must not consume the timeout budget');
        $this->assertSame(3, $r['exit']);
        $this->assertLessThan(4.0, microtime(true) - $start);
    }

    /** The incremental writer must deliver every byte in order before closing. */
    public function testLargeStdinRoundTripsByteIdentical(): void
    {
        $payload = str_repeat('y', 200_000);
        $r       = Process::run(['cat'], $payload, 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame(0, $r['exit']);
        $this->assertSame($payload, $r['stdout']);
    }

    /** `head` reads a slice then exits: the remaining writes must fail silently. */
    public function testLargeStdinToTruncatingChildReportsItsOutput(): void
    {
        $r = Process::run(['head', '-c', '5'], str_repeat('z', 200_000), 5.0);

        $this->assertFalse($r['timedOut']);
        $this->assertSame('zzzzz', $r['stdout']);
    }

    /**
     * A live child that never reads pins the DEADLINE over the write phase:
     * the old pre-loop blocking fwrite ignored $timeout entirely and hung the
     * suite; now the budget expiry must kill the child at ~0.5s.
     */
    public function testWritePhaseStaysWithinTimeoutBudget(): void
    {
        $start = microtime(true);
        $r     = Process::run(['sh', '-c', 'sleep 5; exit 0'], str_repeat('w', 200_000), 0.5);

        $this->assertTrue($r['timedOut'], 'a full pipe plus idle child must hit the deadline');
        $this->assertLessThan(2.5, microtime(true) - $start);
    }
}
