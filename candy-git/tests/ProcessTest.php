<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\Platform;
use SugarCraft\Git\Process;
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

    /**
     * A27: a runaway child writing more than the capture cap must not be
     * accumulated whole in memory — the head is kept and a visible marker
     * rides at the end so Git-level failures explain the truncation.
     */
    public function testStdoutOverTheCapIsHeadKeptWithOneVisibleNotice(): void
    {
        $r = Process::run(
            // Size spelled out: the bare -r child cannot see the class constant.
            ['php', '-r', 'echo str_repeat("x", 4 * 1024 * 1024 + 65536);'],
            null,
            15.0,
        );

        $this->assertFalse($r['timedOut']);
        $this->assertLessThanOrEqual(
            Process::MAX_CAPTURE + strlen("\n[candy-git: output truncated at 4 MiB]"),
            strlen($r['stdout']),
        );
        $this->assertStringContainsString('[candy-git: output truncated at 4 MiB]', $r['stdout']);
        $this->assertStringStartsWith('xxxx', $r['stdout']);
    }

    public function testStderrOverTheCapCarriesTheSameNotice(): void
    {
        $r = Process::run(
            ['php', '-r', 'fwrite(STDERR, str_repeat("e", 4 * 1024 * 1024 + 65536));'],
            null,
            15.0,
        );

        $this->assertStringContainsString('[candy-git: output truncated at 4 MiB]', $r['stderr']);
        $this->assertLessThanOrEqual(
            Process::MAX_CAPTURE + strlen("\n[candy-git: output truncated at 4 MiB]"),
            strlen($r['stderr']),
        );
    }

    public function testOutputUnderTheCapCarriesNoTruncationNotice(): void
    {
        $r = Process::run(
            ['php', '-r', 'echo str_repeat("s", 100000);'],
            null,
            10.0,
        );

        $this->assertSame(100_000, strlen($r['stdout']));
        $this->assertStringNotContainsString('truncated', $r['stdout']);
    }

    /** The Windows ladder, exercised on any host by injecting the null device. */
    private static function windowsLadder(): Platform
    {
        return Platform::windows()->withNullDevice('/dev/null');
    }

    public function testPlatformSeamAnswersBothBranches(): void
    {
        $this->assertFalse(Platform::posix()->isWindows());
        $this->assertSame('/dev/null', Platform::posix()->nullDevice());
        $this->assertTrue(Platform::windows()->isWindows());
        $this->assertSame('NUL', Platform::windows()->nullDevice());
        $this->assertSame(PHP_OS_FAMILY === 'Windows', Platform::new()->isWindows());
        $this->assertSame('x', Platform::posix()->withNullDevice('x')->nullDevice());
    }

    public function testFindExecutableResolvesOnPathAndAnswersNullWhenAbsent(): void
    {
        $this->assertNotNull(Platform::posix()->findExecutable('sh', '/bin:/usr/bin'));
        $this->assertNull(Platform::posix()->findExecutable('sh', '/nonexistent-dir'));
        $this->assertNull(Platform::posix()->findExecutable('definitely-not-a-binary-xyz', '/bin:/usr/bin'));
    }

    public function testPolledLadderCapturesStdoutStderrExitAndStdin(): void
    {
        $r = Process::run(['sh', '-c', 'cat; echo err >&2; exit 3'], "hello\n", 5.0, self::windowsLadder());

        $this->assertSame("hello\n", $r['stdout']);
        $this->assertSame("err\n", $r['stderr']);
        $this->assertSame(3, $r['exit']);
        $this->assertFalse($r['timedOut']);
    }

    public function testPolledLadderGivesNullStdinAnImmediateEof(): void
    {
        $r = Process::run(['sh', '-c', 'cat; echo done'], null, 5.0, self::windowsLadder());

        $this->assertSame("done\n", $r['stdout']);
        $this->assertSame(0, $r['exit']);
    }

    public function testPolledLadderTimesOutThroughTheInjectedTreeKill(): void
    {
        $killed = [];
        $platform = self::windowsLadder()->withKillTree(static function (int $pid) use (&$killed): void {
            $killed[] = $pid;
            exec('kill -9 ' . $pid);
        });

        $start = microtime(true);
        $r = Process::run(['sleep', '3'], null, 0.3, $platform);

        $this->assertTrue($r['timedOut']);
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertCount(1, $killed);
        $this->assertGreaterThan(0, $killed[0]);
    }

    public function testPolledLadderCapsOutputWithOneNotice(): void
    {
        $r = Process::run(['sh', '-c', 'head -c 5000000 /dev/zero | tr "\\0" a'], null, 20.0, self::windowsLadder());

        $this->assertStringEndsWith('[candy-git: output truncated at 4 MiB]', $r['stdout']);
        $this->assertSame(1, substr_count($r['stdout'], 'truncated at 4 MiB'));
    }

    public function testSpawnFailureCarriesTheReasonNotTheMissingGitHint(): void
    {
        try {
            Process::run(['git'], null, 1.0, Platform::posix()->withNullDevice('/nonexistent/dir/null'));
            $this->fail('expected a spawn failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed to spawn', $e->getMessage());
            $this->assertStringNotContainsString('{reason}', $e->getMessage());
            $this->assertStringNotContainsString('install git', $e->getMessage());
        }
    }
}
