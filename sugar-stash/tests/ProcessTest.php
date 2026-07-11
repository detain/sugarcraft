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
}
