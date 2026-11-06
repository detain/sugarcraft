<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Alert, Action, HistoryLog, Toast, ToastType};

/**
 * Fail-fast refusals: bad config
 * and bad data must die at the door with a descriptive error instead of
 * producing a silently dead notification system, an epoch-1970 invisible
 * alert, a NAN-full progress bar, or a HistoryLog that only explodes later
 * inside a consumer loop.
 */
final class ToastFailFastTest extends TestCase
{
    public function testADurationTypedIntoTheExpiresAtSlotIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute epoch timestamp');
        Toast::new(50)->alert(ToastType::Info, 'hi', 5.0);
    }

    public function testProgressToastRefusesTheSameSlotMixup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Toast::new(50)->progressToast(ToastType::Info, 'loading', 0.5, 30.0);
    }

    public function testARealPastEpochTimestampStillQueues(): void
    {
        // The guard must refuse duration-shaped numbers, not legitimately
        // expired alerts — every lifecycle test in the suite pushes those.
        $t = Toast::new(50)->alert(ToastType::Info, 'stale', \microtime(true) - 10);
        $this->assertInstanceOf(Toast::class, $t); // accepted at the door; expiry semantics untouched
    }

    public function testZeroAndNegativeMaxConcurrentAreRefused(): void
    {
        foreach ([0, -1] as $bad) {
            try {
                Toast::new(50)->withMaxConcurrent($bad);
                $this->fail("withMaxConcurrent({$bad}) must throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('silently suppresses', $e->getMessage());
            }
        }
        // Valid shapes stay valid.
        $this->assertNotNull(Toast::new(50)->withMaxConcurrent(1));
        $this->assertNotNull(Toast::new(50)->withMaxConcurrent(null));
    }

    public function testNonFiniteProgressIsRefusedAtEveryDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Alert(ToastType::Info, 'm', null, \NAN);
    }

    public function testInfiniteProgressIsRefusedByWithProgress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Alert(ToastType::Info, 'm'))->withProgress(\INF);
    }

    public function testFiniteOutOfRangeProgressStillClampsAsDocumented(): void
    {
        // 2026-11-03 policy: only NON-FINITE dies; out-of-range clamping is
        // pinned behaviour elsewhere (AlertTest -5.0 -> 0, ToastProgressTest
        // 1.5 -> full) and stays.
        $this->assertSame(0.0, (new Alert(ToastType::Info, 'm'))->withProgress(-5.0)->progress);
        $this->assertSame(1.0, (new Alert(ToastType::Info, 'm'))->withProgress(1.5)->progress);
    }

    public function testHistoryLogRefusesGarbagePayloads(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HistoryLog(['garbage']);
    }

    public function testHistoryLogRefusesNullEntries(): void
    {
        $alert = new Alert(ToastType::Info, 'ok');
        $this->expectException(\InvalidArgumentException::class);
        new HistoryLog([$alert, null, $alert]);
    }

    public function testHistoryLogRefusesStringKeyedArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HistoryLog(['a' => new Alert(ToastType::Info, 'ok')]);
    }

    public function testHistoryLogAcceptsEmptyAndHonestLists(): void
    {
        $log = new HistoryLog();
        $log = $log->push(new Alert(ToastType::Info, 'filed'), null);
        $this->assertCount(1, $log);
    }

    public function testHistoryLogIsCountable(): void
    {
        $log = new HistoryLog();
        $this->assertInstanceOf(\Countable::class, $log);
        $this->assertSame(0, \count($log));
    }
}
