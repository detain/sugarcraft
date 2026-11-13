<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\ChildPump;
use SugarCraft\Async\DrainResult;
use SugarCraft\Async\WindowsPipes;

final class ChildPumpTest extends TestCase
{
    /** @var list<resource> */
    private array $procs = [];

    /** Running probe over the last spawned child. */
    private ?\Closure $running = null;

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            @proc_terminate($p);
            @proc_close($p);
        }
        $this->procs = [];
    }

    /** @return array{0: array<int, resource>} */
    private function spawn(string $php): array
    {
        $proc = proc_open([PHP_BINARY, '-r', $php], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $this->procs[] = $proc;
        $this->running = WindowsPipes::runningProbe($proc);

        return [1 => $pipes[1], 2 => $pipes[2]];
    }

    private function now(): float
    {
        return microtime(true);
    }

    public function testReadsBothStreamsToEofAndClassifiesClosed(): void
    {
        $pipes = $this->spawn('fwrite(STDOUT, "out"); fwrite(STDERR, "err");');
        $r = ChildPump::drain($pipes, $this->now() + 10, 1024, $this->now(...), running: $this->running);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertTrue($r->closed());
        $this->assertSame('out', $r->buffer(1));
        $this->assertSame('err', $r->buffer(2));
        $this->assertFalse($r->truncated());
        $this->assertFalse($r->timedOut());
    }

    public function testChattyStderrDoesNotDeadlockStdout(): void
    {
        // 300 KiB to stderr FIRST then stdout: reading stdout-to-EOF first would wedge.
        $pipes = $this->spawn('fwrite(STDERR, str_repeat("e", 300000)); fwrite(STDOUT, "done");');
        $r = ChildPump::drain($pipes, $this->now() + 20, 1_000_000, $this->now(...), running: $this->running);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame('done', $r->buffer(1));
        $this->assertSame(300000, strlen($r->buffer(2)));
    }

    public function testCeilingKeepsHeadCountsDroppedAndKeepsReading(): void
    {
        $pipes = $this->spawn('fwrite(STDOUT, str_repeat("a", 50000)); fwrite(STDERR, "ok");');
        $r = ChildPump::drain($pipes, $this->now() + 10, 100, $this->now(...), running: $this->running);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame(str_repeat('a', 100), $r->buffer(1));
        $this->assertSame(49900, $r->droppedBytes(1));
        $this->assertSame(0, $r->droppedBytes(2));
        $this->assertTrue($r->truncated());
        $this->assertSame('ok', $r->buffer(2));
    }

    public function testStopAtCapEndsTheWalk(): void
    {
        $pipes = $this->spawn('while (true) { fwrite(STDOUT, str_repeat("x", 4096)); }');
        $r = ChildPump::drain($pipes, $this->now() + 10, 10_000, $this->now(...), stopAtCap: true, running: $this->running);
        $this->assertSame(DrainResult::STOPPED, $r->reason);
        $this->assertSame(10_000, strlen($r->buffer(1)));
    }

    public function testDiscardedSlotIsDrainedNotKept(): void
    {
        $pipes = $this->spawn('fwrite(STDERR, str_repeat("e", 200000)); fwrite(STDOUT, "row");');
        $r = ChildPump::drain($pipes, $this->now() + 20, 100, $this->now(...), discard: [2], running: $this->running);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame('row', $r->buffer(1));
        $this->assertSame('', $r->buffer(2));
        $this->assertSame(0, $r->droppedBytes(2));
        $this->assertFalse($r->truncated());
    }

    public function testDeadlineExpiresOnSilentChildAndSweepsWhatArrived(): void
    {
        $pipes = $this->spawn('fwrite(STDOUT, "early"); fflush(STDOUT); sleep(30);');
        $t0 = $this->now();
        $r = ChildPump::drain($pipes, $t0 + 0.4, 1024, $this->now(...), sliceSeconds: 0.05, running: $this->running);
        $this->assertSame(DrainResult::EXPIRED, $r->reason);
        $this->assertTrue($r->timedOut());
        $this->assertSame('early', $r->buffer(1));
        $this->assertLessThan(5.0, $this->now() - $t0);
    }

    public function testInjectedClockDrivesDeadlineWithoutWallTime(): void
    {
        $pipes = $this->spawn('sleep(30);');
        $t = 0.0;
        $clock = function () use (&$t): float {
            return $t += 0.5;
        };
        $r = ChildPump::drain($pipes, 2.0, 10, $clock, sliceSeconds: 0.01, running: $this->running);
        $this->assertSame(DrainResult::EXPIRED, $r->reason);
    }

    public function testFailedSelectIsRiddenOutLikeEintr(): void
    {
        $this->requirePosixPipes();
        $pipes = $this->spawn('usleep(50000); fwrite(STDOUT, "after-signal");');
        $fails = 0;
        $select = function (array &$read, &$write, &$except, int $s, int $us) use (&$fails) {
            if ($fails < 5) {
                $fails++;

                return false;
            }

            return @stream_select($read, $write, $except, $s, $us);
        };
        $r = ChildPump::drain($pipes, $this->now() + 10, 1024, $this->now(...), select: $select, pollPipes: false);
        $this->assertSame(5, $fails);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame('after-signal', $r->buffer(1));
    }

    public function testPersistentSelectFailureGivesUpWithRetryBudgetGone(): void
    {
        $this->requirePosixPipes();
        $pipes = $this->spawn('sleep(30);');
        $calls = 0;
        $select = function (array &$read, &$write, &$except, int $s, int $us) use (&$calls) {
            $calls++;

            return false;
        };
        $r = ChildPump::drain($pipes, $this->now() + 10, 1024, $this->now(...), selectRetries: 3, select: $select, pollPipes: false);
        $this->assertSame(DrainResult::RETRY_BUDGET_GONE, $r->reason);
        $this->assertSame(4, $calls, 'budget of 3 rides out 3 failures and gives up on the 4th');
    }

    public function testSuccessfulSelectResetsTheFailureBudget(): void
    {
        $this->requirePosixPipes();
        $pipes = $this->spawn('usleep(100000); fwrite(STDOUT, "x");');
        $n = 0;
        $select = function (array &$read, &$write, &$except, int $s, int $us) use (&$n) {
            // fail, fail, real, fail, fail, real... never 3 in a row.
            return (++$n % 3) !== 0 ? false : @stream_select($read, $write, $except, $s, $us);
        };
        $r = ChildPump::drain($pipes, $this->now() + 10, 16, $this->now(...), selectRetries: 2, select: $select, pollPipes: false);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame('x', $r->buffer(1));
    }

    public function testEmptyPipesCloseImmediately(): void
    {
        $r = ChildPump::drain([], 0.0, 1);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame([], $r->buffers);
    }

    public function testRejectsBadArguments(): void
    {
        try {
            ChildPump::drain([], 1.0, -1);
            $this->fail('negative maxBytes');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        ChildPump::drain([], 1.0, 1, sliceSeconds: 0.0);
    }

    public function testDrainResultAccessorsForUnknownSlot(): void
    {
        $r = new DrainResult([1 => 'a'], [1 => 2], DrainResult::STOPPED);
        $this->assertSame('', $r->buffer(9));
        $this->assertSame(0, $r->droppedBytes(9));
        $this->assertTrue($r->truncated());
        $this->assertFalse($r->closed());
    }

    private function requirePosixPipes(): void
    {
        if (WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('The select arm needs non-blocking pipes, which Windows proc_open pipes are not.');
        }
    }

    private function requireWindowsPipes(): void
    {
        if (!WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('The poll arm reads fstat() sizes, which only Windows reports for pipes.');
        }
    }

    public function testWindowsSilentChildHitsTheDeadlineOnTime(): void
    {
        $this->requireWindowsPipes();
        $pipes = $this->spawn('sleep(30);');
        $t0 = $this->now();
        $r = ChildPump::drain($pipes, $t0 + 1.0, 1024, $this->now(...), running: $this->running);
        $this->assertSame(DrainResult::EXPIRED, $r->reason);
        $this->assertLessThan(2.0, $this->now() - $t0, 'a blocking fread on the silent pipe would park until the child exits');
    }

    public function testWindowsLargeStdoutWithQuietStderrIsReturnedWhole(): void
    {
        $this->requireWindowsPipes();
        // stderr stays empty and open while stdout overflows the pipe buffer:
        // a read of the empty stderr would park while the child waits on stdout.
        $pipes = $this->spawn('fwrite(STDOUT, str_repeat("o", 200000)); usleep(200000); fwrite(STDERR, "end");');
        $t0 = $this->now();
        $r = ChildPump::drain($pipes, $t0 + 20, 1_000_000, $this->now(...), running: $this->running);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame(200000, strlen($r->buffer(1)));
        $this->assertSame('end', $r->buffer(2));
        $this->assertLessThan(5.0, $this->now() - $t0);
    }

    public function testWindowsArmWithoutARunningProbeIsBoundedByTheDeadline(): void
    {
        $this->requireWindowsPipes();
        $pipes = $this->spawn('fwrite(STDOUT, "quick");');
        $t0 = $this->now();
        $r = ChildPump::drain($pipes, $t0 + 0.5, 1024, $this->now(...));
        $this->assertSame(DrainResult::EXPIRED, $r->reason, 'no probe: a finished child and a silent one look the same');
        $this->assertSame('quick', $r->buffer(1));
        $this->assertLessThan(2.0, $this->now() - $t0);
    }

    public function testWindowsArmEndsPromptlyOnceTheChildExits(): void
    {
        $this->requireWindowsPipes();
        $pipes = $this->spawn('usleep(300000); fwrite(STDOUT, "bye");');
        $t0 = $this->now();
        $r = ChildPump::drain($pipes, $t0 + 20, 1024, $this->now(...), running: $this->running);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertSame('bye', $r->buffer(1));
        $this->assertLessThan(3.0, $this->now() - $t0);
    }

    public function testRunningProbeReportsExitAndNonResources(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', ''], [], $pipes);
        $this->assertIsResource($proc);
        $probe = WindowsPipes::runningProbe($proc);
        $deadline = $this->now() + 10;
        while ($probe() && $this->now() < $deadline) {
            usleep(10_000);
        }
        $this->assertFalse($probe());
        proc_close($proc);
        $this->assertFalse($probe(), 'a closed handle counts as exited');
    }

    public function testPollArmHonorsStopAtCapAndEmptyPipes(): void
    {
        $r = ChildPump::drain([], 0.0, 1, pollPipes: true);
        $this->assertSame(DrainResult::CLOSED, $r->reason);

        $r = ChildPump::drain([1 => fopen('php://memory', 'r')], 1.0, 0, stopAtCap: true, pollPipes: true);
        $this->assertSame(DrainResult::STOPPED, $r->reason);
    }

    public function testPollArmEndsClosedWhenTheProbeSaysExitedAndNothingIsReadable(): void
    {
        // On any OS: a memory stream reports no readable bytes to the poll arm,
        // so an exited probe ends the walk on the first empty pass.
        $t0 = $this->now();
        $r = ChildPump::drain([1 => fopen('php://memory', 'r')], $t0 + 10, 16, $this->now(...), running: static fn (): bool => false, pollPipes: true);
        $this->assertSame(DrainResult::CLOSED, $r->reason);
        $this->assertLessThan(1.0, $this->now() - $t0);
    }

    public function testPollArmExpiresWhileTheProbeSaysRunning(): void
    {
        $t = 0.0;
        $r = ChildPump::drain([1 => fopen('php://memory', 'r')], 0.05, 16, static function () use (&$t): float {
            return $t += 0.02;
        }, running: static fn (): bool => true, pollPipes: true);
        $this->assertSame(DrainResult::EXPIRED, $r->reason);
    }
}
