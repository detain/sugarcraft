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

    public function testNonFiniteExpiresAtIsRefusedAtEveryDoor(): void
    {
        // 004: NAN slips through every comparison, so it cleared the old
        // epoch floor and minted an immortal alert — nextExpiry() answers
        // NAN, pruneExpired() never removes it, and secondsUntilNextExpiry()
        // pins at 0.0, livelocking a host following the documented
        // Cmd::tick recipe. INF is the same silent-immortal outcome.
        foreach ([\NAN, \INF] as $bad) {
            $label = \is_nan($bad) ? 'NAN' : 'INF';
            try {
                Toast::new(50)->alert(ToastType::Info, 'hi', $bad);
                $this->fail("alert() must refuse \$expiresAt {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('NAN/INF', $e->getMessage());
            }
            try {
                Toast::new(50)->progressToast(ToastType::Info, 'p', 0.5, $bad);
                $this->fail("progressToast() must refuse \$expiresAt {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('NAN/INF', $e->getMessage());
            }
            try {
                new Alert(ToastType::Info, 'hi', $bad);
                $this->fail("the Alert constructor must refuse \$expiresAt {$label}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('NAN/INF', $e->getMessage());
            }
        }
    }

    public function testTheAlertConstructorGuardsItsOwnExpiryInvariant(): void
    {
        // 006 parse-don't-validate: the type carrying the invariant enforced
        // it nowhere — a pre-built sub-epoch Alert rode push() straight into
        // the queue, born expired and unrefused.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duration-shaped');
        new Alert(ToastType::Info, 'hi', 5.0);
    }

    public function testExpiryMethsCannotSmuggleNonFinitePastTheCtorGuard(): void
    {
        // withExpiry()/withExtendedExpiry() resolve microtime + duration and
        // rebuild through mutate() → the constructor leg fires for a
        // non-finite request instead of minting an immortal alert.
        $alert = Alert::new(ToastType::Info, 'x');
        foreach ([\NAN, \INF] as $bad) {
            try {
                $alert->withExpiry($bad);
                $this->fail('withExpiry() must refuse non-finite durations');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('NAN/INF', $e->getMessage());
            }
            try {
                $alert->withExtendedExpiry($bad);
                $this->fail('withExtendedExpiry() must refuse non-finite durations');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('NAN/INF', $e->getMessage());
            }
        }
    }

    public function testNonPositiveAndNonFiniteDurationAreRefused(): void
    {
        // 005: withDuration(0)/(-1) was accepted — every later alert born
        // expired, nothing rendered, nothing errored, and
        // secondsUntilNextExpiry() pinned at 0.0 until the next write
        // pruned. The sibling knobs already refused this shape; so does
        // this door now.
        foreach ([0.0, -1.0, \NAN, \INF] as $bad) {
            try {
                Toast::new(50)->withDuration($bad);
                $this->fail('withDuration(' . \var_export($bad, true) . ') must throw');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('positive seconds = TTL', $e->getMessage());
            }
        }
        $this->assertNotNull(Toast::new(50)->withDuration(5.0));
        $this->assertNotNull(Toast::new(50)->withDuration(null));
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

    // TST-WIDTH: the box-width knobs join the fail-fast family. A typo'd
    // width used to floor silently to a 1-cell box — permanently unreadable,
    // no error, the exact "silently suppresses every alert" shape
    // withMaxConcurrent() already refuses. withMinWidth is the asymmetric
    // sibling: 0 there is the FIXED-WIDTH sentinel, not garbage.

    public function testWithMaxWidthRefusesNonPositiveNamingSemantics(): void
    {
        foreach ([0, -1, -640] as $bad) {
            try {
                Toast::new()->withMaxWidth($bad);
                $this->fail("withMaxWidth({$bad}) must throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('positive cells', $e->getMessage());
                $this->assertStringContainsString('1-cell box', $e->getMessage());
            }
        }
    }

    public function testPositiveMaxWidthAndFixedZeroMinWidthStillWork(): void
    {
        $t = Toast::new(40)->withMaxWidth(20)->withMinWidth(0);
        $this->assertStringContainsString('ok', $t->info('ok')->view('', 60, 8));
    }

    public function testWithMinWidthRefusesOnlyNegativeAndSaysWhy(): void
    {
        try {
            Toast::new()->withMinWidth(-1);
            $this->fail('withMinWidth(-1) must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('FIXED-WIDTH sentinel', $e->getMessage());
        }
    }
}
