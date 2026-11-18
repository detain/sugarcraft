<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Module;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Module\BoundedCommand;

/**
 * BoundedCommand is the dashboard's synchronous child runner. Its output and
 * failure contract is what the panels render, so each branch is pinned here
 * directly rather than only through GenericModule.
 */
final class BoundedCommandTest extends TestCase
{
    /** @return list<string> */
    private static function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    public function testEmptyArgvNamesTheMissingCommand(): void
    {
        $this->assertSame(
            ['output' => '', 'failure' => 'No command configured'],
            BoundedCommand::run([]),
        );
    }

    public function testStdoutIsReturnedWithoutFailure(): void
    {
        $this->assertSame(
            ['output' => 'hi', 'failure' => null],
            BoundedCommand::run(self::php('echo "hi";')),
        );
    }

    public function testStderrFollowsStdoutInTheOutput(): void
    {
        $result = BoundedCommand::run(self::php('fwrite(STDOUT, "out"); fwrite(STDERR, "err");'));

        $this->assertSame(['output' => "out\nerr", 'failure' => null], $result);
    }

    public function testSilentZeroExitIsSuccessWithNothingToShow(): void
    {
        $this->assertSame(
            ['output' => '', 'failure' => null],
            BoundedCommand::run(self::php('exit(0);')),
        );
    }

    public function testSilentNonZeroExitNamesTheExitStatus(): void
    {
        $this->assertSame(
            ['output' => '', 'failure' => 'Command failed (exit 3)'],
            BoundedCommand::run(self::php('exit(3);')),
        );
    }

    public function testOutputWinsOverANonZeroExit(): void
    {
        // grep exits 1 while printing the answer; the answer is what the panel shows.
        $this->assertSame(
            ['output' => 'found', 'failure' => null],
            BoundedCommand::run(self::php('fwrite(STDOUT, "found"); exit(1);')),
        );
    }

    public function testMissingBinaryIsNamedAsNotStarted(): void
    {
        $this->assertSame(
            ['output' => '', 'failure' => 'Command could not be started'],
            BoundedCommand::run(['/nonexistent-dir-sugar-dashboard/no-such-binary']),
        );
    }

    public function testDeadlineStopsAChildAndNamesTheTimeout(): void
    {
        $start = hrtime(true);
        $result = BoundedCommand::run(self::php('sleep(30);'), 0.5);
        $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertSame(['output' => '', 'failure' => 'Command timed out'], $result);
        $this->assertLessThan(15.0, $elapsedSeconds, 'the child must be killed at the deadline, not waited out');
    }

    public function testOutputCapIsOneBudgetAcrossBothStreams(): void
    {
        // Guards the combined ceiling that a per-stream ChildRun cap alone would
        // break: stdout keeps its 600 bytes, stderr keeps the 400 left in the
        // 1000-byte budget, and the joining newline is not counted against it.
        $result = BoundedCommand::run(
            self::php('fwrite(STDOUT, str_repeat("a", 600)); fwrite(STDERR, str_repeat("b", 600));'),
            BoundedCommand::DEFAULT_TIMEOUT_SECONDS,
            1000,
        );

        $this->assertNull($result['failure']);
        $this->assertSame(str_repeat('a', 600) . "\n" . str_repeat('b', 400), $result['output']);
    }

    public function testStdoutAloneIsCutAtTheCap(): void
    {
        $result = BoundedCommand::run(
            self::php('fwrite(STDOUT, str_repeat("a", 1500));'),
            BoundedCommand::DEFAULT_TIMEOUT_SECONDS,
            1000,
        );

        $this->assertSame(str_repeat('a', 1000), $result['output']);
        $this->assertNull($result['failure']);
    }

    public function testNonPositiveTimeoutIsRejectedBeforeTheChildStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BoundedCommand::run(self::php('exit(0);'), 0.0);
    }

    public function testNegativeOutputCapIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BoundedCommand::run(self::php('exit(0);'), BoundedCommand::DEFAULT_TIMEOUT_SECONDS, -1);
    }
}
