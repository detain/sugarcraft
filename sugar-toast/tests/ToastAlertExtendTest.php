<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * Tests for alert expiry management: cancelAlert, extendAlert, extendAll.
 *
 * These three methods previously passed a Closure to the array-based
 * mutate() helper, so every valid-index path raised a TypeError and the
 * suite could only exercise the out-of-bounds early returns. The fix rewrote
 * them to copy the queue array and mutate the clone via mutate(['queue'=>…]),
 * so the valid paths are now first-class behaviour covered below.
 *
 * Semantic note: a persistent alert (expiresAt === null) has no timer to
 * extend, so extendAlert() is a no-op on it — matching extendAll()'s existing
 * "only affects alerts that have an expiry" contract.
 */
final class ToastAlertExtendTest extends TestCase
{
    // ─── cancelAlert out-of-bounds cases ───────────────────────────────────

    public function testCancelAlertOutOfBoundsReturnsSameInstance(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test');
        $result = $t->cancelAlert(99);  // out of bounds

        $this->assertSame($t, $result);
    }

    public function testCancelAlertNegativeIndexReturnsSameInstance(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test');
        $result = $t->cancelAlert(-1);

        $this->assertSame($t, $result);
    }

    public function testCancelAlertOnEmptyQueueReturnsSameInstance(): void
    {
        $t = Toast::new(50);
        $result = $t->cancelAlert(0);

        $this->assertSame($t, $result);
    }

    public function testCancelAlertOutOfBoundsPreservesQueue(): void
    {
        $t = Toast::new(50)->info('first')->warning('second');
        $result = $t->cancelAlert(99);

        // Queue should be unchanged
        $this->assertCount(2, $this->getQueue($result));
    }

    // ─── extendAlert out-of-bounds cases ───────────────────────────────────

    public function testExtendAlertOutOfBoundsReturnsSameInstance(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test');
        $result = $t->extendAlert(99, 5.0);

        $this->assertSame($t, $result);
    }

    public function testExtendAlertNegativeIndexReturnsSameInstance(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test');
        $result = $t->extendAlert(-1, 5.0);

        $this->assertSame($t, $result);
    }

    public function testExtendAlertOnEmptyQueueReturnsSameInstance(): void
    {
        $t = Toast::new(50);
        $result = $t->extendAlert(0, 5.0);

        $this->assertSame($t, $result);
    }

    public function testExtendAlertOutOfBoundsPreservesQueue(): void
    {
        $t = Toast::new(50)->info('first')->warning('second');
        $result = $t->extendAlert(99, 5.0);

        // Queue should be unchanged
        $this->assertCount(2, $this->getQueue($result));
    }

    public function testExtendAlertOutOfBoundsPreservesExpiry(): void
    {
        $t = Toast::new(50)
            ->withDuration(10.0)
            ->alert(ToastType::Info, 'test');

        $originalQueue = $this->getQueue($t);
        $originalExpiry = $originalQueue[0]->expiresAt;

        $result = $t->extendAlert(99, 5.0);

        // Queue unchanged - expiry still set
        $resultQueue = $this->getQueue($result);
        $this->assertEqualsWithDelta($originalExpiry, $resultQueue[0]->expiresAt, 0.001);
    }

    // ─── cancelAlert valid paths (bug fixed: mutate() receives an array) ─────

    public function testCancelAlertClearsExpiryOnTargetIndex(): void
    {
        $t = Toast::new(50)
            ->withDuration(10.0)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second');

        $result = $t->cancelAlert(0);

        $queue = $this->getQueue($result);
        $this->assertNull($queue[0]->expiresAt, 'cancelled alert must never expire');
        $this->assertNotNull($queue[1]->expiresAt, 'sibling alert keeps its timer');
        $this->assertCount(2, $queue);
    }

    public function testCancelAlertReturnsNewInstanceLeavingOriginalUntouched(): void
    {
        $t = Toast::new(50)->withDuration(10.0)->info('test');

        $result = $t->cancelAlert(0);

        $this->assertNotSame($t, $result);
        $this->assertNotNull($this->getQueue($t)[0]->expiresAt, 'the original instance is immutable');
    }

    public function testCancelAlertOnPersistentAlertKeepsItPersistent(): void
    {
        $t = Toast::new(50)->info('no timer configured');

        $queue = $this->getQueue($t->cancelAlert(0));

        $this->assertNull($queue[0]->expiresAt);
    }

    // ─── extendAlert valid paths ─────────────────────────────────────────────

    public function testExtendAlertMovesExpiryToSecondsFromNow(): void
    {
        $t = Toast::new(50)
            ->withDuration(10.0)
            ->alert(ToastType::Info, 'test');

        $before = \microtime(true);
        $result = $t->extendAlert(0, 30.0);
        $expiry = $this->getQueue($result)[0]->expiresAt;

        $this->assertNotNull($expiry);
        // withExtendedExpiry() re-anchors the deadline at "now + $additionalSeconds",
        // so it must land inside [before+30, now+30] — not merely after the old 10s expiry.
        $this->assertGreaterThanOrEqual($before + 30.0, $expiry);
        $this->assertLessThanOrEqual(\microtime(true) + 30.0, $expiry);
    }

    public function testExtendAlertOnPersistentAlertIsANoOp(): void
    {
        $t = Toast::new(50)->info('persistent');

        $result = $t->extendAlert(0, 5.0);

        // A persistent alert has no timer to extend (mirrors extendAll()) —
        // it must NOT silently gain one.
        $this->assertSame($t, $result);
        $this->assertNull($this->getQueue($result)[0]->expiresAt);
    }

    public function testExtendAlertOnlyAffectsTargetIndex(): void
    {
        $t = Toast::new(50)
            ->withDuration(10.0)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second');

        $oldSecondExpiry = $this->getQueue($t)[1]->expiresAt;
        $result = $t->extendAlert(0, 60.0);

        $queue = $this->getQueue($result);
        $this->assertGreaterThan(60.0 - 1.0, $queue[0]->expiresAt - \microtime(true));
        $this->assertEqualsWithDelta($oldSecondExpiry, $queue[1]->expiresAt, 0.001);
    }

    // ─── extendAll valid paths ───────────────────────────────────────────────

    public function testExtendAllExtendsExpiringAndLeavesPersistentUnchanged(): void
    {
        $t = Toast::new(50)
            ->withDuration(10.0)
            ->alert(ToastType::Info, 'expiring')
            ->cancelAlert(0)
            ->alert(ToastType::Success, 'second-keeps-timer');

        $result = $t->extendAll(45.0);

        $queue = $this->getQueue($result);
        $this->assertNull($queue[0]->expiresAt, 'persistent alert is never given a timer');
        $this->assertNotNull($queue[1]->expiresAt);
        $this->assertGreaterThan(44.0, $queue[1]->expiresAt - \microtime(true));
    }

    public function testExtendAllOnEmptyQueueSucceeds(): void
    {
        $t = Toast::new(50);

        $result = $t->extendAll(5.0);

        $this->assertNotSame($t, $result);
        $this->assertCount(0, $this->getQueue($result));
    }

    public function testExtendAllKeepsQueueOrderAndLength(): void
    {
        $t = Toast::new(50)
            ->withDuration(10.0)
            ->info('a')
            ->warning('b')
            ->success('c');

        $queue = $this->getQueue($t->extendAll(20.0));

        $this->assertCount(3, $queue);
        $this->assertSame('a', $queue[0]->message);
        $this->assertSame('b', $queue[1]->message);
        $this->assertSame('c', $queue[2]->message);
    }

    // ─── cancelAlert with duration configured (out-of-bounds) ────────────────

    public function testCancelAlertWithDurationConfiguredOutOfBounds(): void
    {
        $t = Toast::new(50)->withDuration(10.0)->alert(ToastType::Info, 'test');

        // Verify alert has expiry (from configured duration)
        $queue = $this->getQueue($t);
        $this->assertNotNull($queue[0]->expiresAt);

        // cancelAlert with out-of-bounds returns same instance
        $result = $t->cancelAlert(99);
        $this->assertSame($t, $result);
    }

    // ─── Multiple alerts - cancelAlert only affects target index ───────────

    public function testCancelAlertOnlyAffectsTargetIndex(): void
    {
        $t = Toast::new(50)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second');

        // Cancel first alert's expiry (but we're using out-of-bounds, so no change)
        $result = $t->cancelAlert(99);

        // Neither alert should be modified
        $this->assertCount(2, $this->getQueue($result));
    }

    // Helper to access private queue
    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
