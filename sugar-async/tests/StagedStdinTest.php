<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\StagedStdin;

/**
 * The retry decision for a staged Windows stdin payload. It runs on every
 * platform: the unlink result is injected, so no Windows file lock is needed.
 */
final class StagedStdinTest extends TestCase
{
    public function testARemovalThatWorksWhileTheChildIsAliveOwesNoRetry(): void
    {
        self::assertFalse(StagedStdin::removeOrRetry('/staged/a', false, static fn (string $p): bool => true));
    }

    public function testARemovalThatWorksAfterTheReapOwesNoRetry(): void
    {
        self::assertFalse(StagedStdin::removeOrRetry('/staged/a', true, static fn (string $p): bool => true));
    }

    public function testAFailedRemovalWhileTheChildIsAliveIsKeptForTheReapPath(): void
    {
        // The Windows case: the live child holds the payload open, so unlink is refused.
        self::assertTrue(StagedStdin::removeOrRetry('/staged/a', false, static fn (string $p): bool => false));
    }

    public function testAFailedRemovalAfterTheReapIsFinal(): void
    {
        // Nothing holds the file any more, so a second refusal has no retry to wait for.
        self::assertFalse(StagedStdin::removeOrRetry('/staged/a', true, static fn (string $p): bool => false));
    }

    public function testTheSeamReceivesTheStagedPathExactlyOnce(): void
    {
        $seen = [];
        $seam = static function (string $p) use (&$seen): bool {
            $seen[] = $p;

            return false;
        };

        StagedStdin::removeOrRetry('/staged/payload.bin', false, $seam);

        self::assertSame(['/staged/payload.bin'], $seen);
    }

    public function testTheDefaultSeamRemovesARealFileAndOwesNoRetry(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'staged-stdin-');
        self::assertIsString($path);
        \file_put_contents($path, 'payload');

        try {
            self::assertFalse(StagedStdin::removeOrRetry($path, false));
            self::assertFileDoesNotExist($path);
        } finally {
            @\unlink($path);
        }
    }

    public function testTheDefaultSeamKeepsTheRetryWhenTheFileIsGoneAndTheChildIsAlive(): void
    {
        $path = \sys_get_temp_dir() . '/staged-stdin-missing-' . \bin2hex(\random_bytes(6));

        self::assertTrue(StagedStdin::removeOrRetry($path, false));
        self::assertFalse(StagedStdin::removeOrRetry($path, true));
    }

    public function testNoReapHookIsOwedWithoutAPathOrWithoutARetry(): void
    {
        self::assertNull(StagedStdin::reapHook(null, true));
        self::assertNull(StagedStdin::reapHook('/staged/a', false));
    }

    public function testTheReapHookRemovesTheRefusedPayloadOnceTheChildIsReaped(): void
    {
        // The retry was owed, so the hook runs on the reap: the removal is attempted
        // with the child reaped, and its path is the one the refused removal kept.
        $seen = [];
        $hook = StagedStdin::reapHook('/staged/payload.bin', true, static function (string $p) use (&$seen): bool {
            $seen[] = $p;

            return true;
        });

        self::assertInstanceOf(\Closure::class, $hook);
        self::assertSame([], $seen, 'nothing is removed before the reap');
        $hook(0);
        self::assertSame(['/staged/payload.bin'], $seen);
    }

    public function testAnExitedPathRemovalThatWorksFirstTimeIsNotRetried(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;

        StagedStdin::removeAfterExit($loop, '/staged/a', static function (string $p) use (&$calls): bool {
            $calls++;

            return true;
        });
        $this->runFor($loop, 0.2);

        self::assertSame(1, $calls);
    }

    public function testARefusedExitedPathRemovalIsRetriedOnTheLoopUntilItWorks(): void
    {
        // The first attempt runs at once; the refusals are retried on the loop, and the
        // third attempt takes the file once the OS has released its handle.
        $loop = new StreamSelectLoop();
        $seen = [];
        $results = [false, false, true];

        StagedStdin::removeAfterExit($loop, '/staged/payload.bin', static function (string $p) use (&$seen, &$results): bool {
            $seen[] = $p;

            return \array_shift($results) ?? true;
        });
        self::assertSame(['/staged/payload.bin'], $seen, 'the first attempt is synchronous');

        $this->runFor($loop, 0.5);

        self::assertSame(['/staged/payload.bin', '/staged/payload.bin', '/staged/payload.bin'], $seen);
    }

    public function testTheExitedPathRetryStopsAfterItsBudget(): void
    {
        // A file that never comes free is left behind: the retries are bounded, and
        // the loop goes idle once the budget is spent.
        $loop = new StreamSelectLoop();
        $calls = 0;

        StagedStdin::removeAfterExit($loop, '/staged/a', static function (string $p) use (&$calls): bool {
            $calls++;

            return false;
        }, 3, 0.01);
        $this->runFor($loop, 0.3);

        self::assertSame(3, $calls);
    }

    private function runFor(StreamSelectLoop $loop, float $seconds): void
    {
        $loop->addTimer($seconds, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
    }
}
