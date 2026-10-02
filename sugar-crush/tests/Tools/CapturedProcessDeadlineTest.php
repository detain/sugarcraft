<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;

/**
 * runCaptured()'s optional wall-clock bound (audit 15d-14).
 *
 * The bound is opt-in: the prompt assembly's git reads pass one, Bash and
 * Grep do not, so the null default must keep the historical wait-to-the-end
 * behaviour while a bounded run must come back at the deadline with the
 * child's whole process group dead.
 */
final class CapturedProcessDeadlineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/captured_deadline_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private static function host(): object
    {
        return new class {
            use CapturesProcessOutput;

            /** @return array<string,mixed> */
            public function capture(string $command, ?float $timeout = null): array
            {
                return $this->runCaptured($command, null, null, $timeout);
            }
        };
    }

    public function testASilentChildIsCutOffAtTheDeadlineAndReportedAsTimedOut(): void
    {
        $start = microtime(true);
        $run = self::host()->capture('echo started; sleep 30', 0.5);
        $elapsed = microtime(true) - $start;

        self::assertTrue($run['timedOut'], var_export($run, true));
        self::assertSame(124, $run['exitCode']);
        // Output that arrived before the deadline is kept.
        self::assertSame('started', $run['stdout']);
        // Deadline plus the ladder's first rung; nowhere near the child's 30 s.
        self::assertLessThan(2.0, $elapsed, 'the bounded run waited for the child instead of the deadline');
        self::assertGreaterThanOrEqual(0.45, $elapsed, 'the run returned before its own deadline');
    }

    public function testTheWholeProcessGroupDiesNotJustTheWrapper(): void
    {
        $pidFile = $this->dir . '/grandchild.pid';
        $run = self::host()->capture(
            'sleep 30 & echo $! > ' . escapeshellarg($pidFile) . '; wait',
            0.5,
        );

        self::assertTrue($run['timedOut']);
        $pid = (int) trim((string) @file_get_contents($pidFile));
        self::assertGreaterThan(0, $pid, 'the fixture never started its grandchild');

        if (ProcessContainment::detachedSpawnBinary() === '') {
            // No setsid: the child shares this process's group and only it
            // can be signalled; the grandchild is out of reach by design.
            @posix_kill($pid, 9);

            return;
        }

        self::assertTrue(self::gone($pid), "grandchild {$pid} survived the deadline kill — it was orphaned, not killed");
    }

    public function testAChildThatClosedItsPipesIsStillBoundedByTheDeadline(): void
    {
        // EOF arrives at once, so only the post-drain exit wait can bound this.
        $start = microtime(true);
        $run = self::host()->capture('exec >/dev/null 2>&1; sleep 30', 0.5);

        self::assertTrue($run['timedOut']);
        self::assertLessThan(2.0, microtime(true) - $start, 'proc_close() was left to wait for the child');
    }

    public function testABoundedRunThatFinishesInTimeReportsTheRealExitCode(): void
    {
        $run = self::host()->capture('echo out; echo err >&2; exit 3', 5.0);

        self::assertFalse($run['timedOut']);
        self::assertSame(3, $run['exitCode']);
        self::assertSame('out', $run['stdout']);
        self::assertSame('err', $run['stderr']);
    }

    public function testTheDefaultStillWaitsForTheCommandToFinish(): void
    {
        $start = microtime(true);
        $run = self::host()->capture('sleep 1; echo done');
        $elapsed = microtime(true) - $start;

        self::assertFalse($run['timedOut']);
        self::assertSame(0, $run['exitCode']);
        self::assertSame('done', $run['stdout']);
        self::assertGreaterThanOrEqual(0.95, $elapsed);
    }

    /**
     * Gone, or a zombie awaiting a reaper this test does not own — either way
     * no longer running. Polled because the group kill is asynchronous.
     */
    private static function gone(int $pid): bool
    {
        $deadline = microtime(true) + 3.0;
        do {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if ($stat === false ? !posix_kill($pid, 0) : preg_match('/\) Z /', $stat) === 1) {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        @posix_kill($pid, 9);

        return false;
    }
}
