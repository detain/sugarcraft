<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * Tests for alert expiry management: cancelAlert, extendAlert, extendAll.
 */
final class ToastAlertExtendTest extends TestCase
{
    // ─── cancelAlert ────────────────────────────────────────────────────────

    public function testCancelAlertMakesAlertNonExpired(): void
    {
        // Start with an alert that would expire in 10 seconds
        $t = Toast::new(50)->alert(ToastType::Info, 'expiring', null, 10.0);

        // Cancel its expiry
        $cancelled = $t->cancelAlert(0);

        $this->assertFalse($cancelled->hasActiveAlert());
        // The alert should now be persistent (no expiry)
        $queue = $this->getQueue($cancelled);
        $this->assertNull($queue[0]->expiresAt);
    }

    public function testCancelAlertReturnsNewInstance(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test');
        $cancelled = $t->cancelAlert(0);

        $this->assertNotSame($t, $cancelled);
        // Original unchanged
        $this->assertNotNull($this->getQueue($t)[0]->expiresAt);
    }

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

    public function testCancelAlertOnlyAffectsTargetIndex(): void
    {
        $t = Toast::new(50)
            ->alert(ToastType::Info, 'first')
            ->alert(ToastType::Warning, 'second');

        // Cancel first alert's expiry but not second
        $cancelled = $t->cancelAlert(0);

        $queue = $this->getQueue($cancelled);
        // First alert should have no expiry
        $this->assertNull($queue[0]->expiresAt);
        // Second alert should still have its original expiry
        $this->assertNotNull($queue[1]->expiresAt);
    }

    // ─── extendAlert ────────────────────────────────────────────────────────

    public function testExtendAlertExtendsExpiry(): void
    {
        $originalExpiry = \microtime(true) + 10.0;
        $t = Toast::new(50)->alert(ToastType::Info, 'test', $originalExpiry);

        $extended = $t->extendAlert(0, 5.0);

        $queue = $this->getQueue($extended);
        // Should be approximately NOW + 5.0 (not originalExpiry + 5.0)
        $expectedExpiry = \microtime(true) + 5.0;
        $this->assertEqualsWithDelta($expectedExpiry, $queue[0]->expiresAt, 1.0);
    }

    public function testExtendAlertReturnsNewInstance(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test', \microtime(true) + 10);
        $extended = $t->extendAlert(0, 5.0);

        $this->assertNotSame($t, $extended);
    }

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

    public function testExtendAlertOnlyAffectsTargetIndex(): void
    {
        $expiry1 = \microtime(true) + 10.0;
        $expiry2 = \microtime(true) + 20.0;

        $t = Toast::new(50)
            ->alert(ToastType::Info, 'first', $expiry1)
            ->alert(ToastType::Warning, 'second', $expiry2);

        $extended = $t->extendAlert(0, 100.0);

        $queue = $this->getQueue($extended);
        // First alert should be extended
        $this->assertGreaterThan($expiry1 + 50.0, $queue[0]->expiresAt);
        // Second alert should be unchanged
        $this->assertEqualsWithDelta($expiry2, $queue[1]->expiresAt, 0.1);
    }

    // ─── extendAll ──────────────────────────────────────────────────────────

    public function testExtendAllExtendsAllExpiringAlerts(): void
    {
        $expiry1 = \microtime(true) + 10.0;
        $expiry2 = \microtime(true) + 20.0;
        $expiry3 = \microtime(true) + 30.0;

        $t = Toast::new(50)
            ->alert(ToastType::Info, 'first', $expiry1)
            ->alert(ToastType::Warning, 'second', $expiry2)
            ->alert(ToastType::Error, 'third', $expiry3);

        $extended = $t->extendAll(5.0);

        $queue = $this->getQueue($extended);
        $now = \microtime(true);

        // All should be extended to approximately NOW + 5.0
        $this->assertEqualsWithDelta($now + 5.0, $queue[0]->expiresAt, 1.0);
        $this->assertEqualsWithDelta($now + 5.0, $queue[1]->expiresAt, 1.0);
        $this->assertEqualsWithDelta($now + 5.0, $queue[2]->expiresAt, 1.0);
    }

    public function testExtendAllSkipsNonExpiringAlerts(): void
    {
        $t = Toast::new(50)
            ->info('persistent')              // no expiry
            ->alert(ToastType::Warning, 'timed', \microtime(true) + 10.0);

        $extended = $t->extendAll(5.0);

        $queue = $this->getQueue($extended);
        // First alert (persistent) should remain null expiry
        $this->assertNull($queue[0]->expiresAt);
        // Second alert should be extended
        $this->assertGreaterThan(\microtime(true), $queue[1]->expiresAt);
    }

    public function testExtendAllReturnsNewInstance(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test', \microtime(true) + 10);
        $extended = $t->extendAll(5.0);

        $this->assertNotSame($t, $extended);
    }

    public function testExtendAllOnEmptyQueueReturnsNewInstance(): void
    {
        $t = Toast::new(50);
        $extended = $t->extendAll(5.0);

        $this->assertNotSame($t, $extended);
    }

    public function testExtendAllOnOnlyNonExpiringAlerts(): void
    {
        $t = Toast::new(50)->info('a')->warning('b');
        $extended = $t->extendAll(5.0);

        // Should return a new instance but queue unchanged
        $this->assertNotSame($t, $extended);
        $this->assertCount(2, $this->getQueue($extended));
    }

    public function testExtendAllPreservesAlertContent(): void
    {
        $t = Toast::new(50)
            ->alert(ToastType::Info, 'message', \microtime(true) + 10);

        $extended = $t->extendAll(5.0);

        $queue = $this->getQueue($extended);
        $this->assertSame('message', $queue[0]->message);
        $this->assertSame(ToastType::Info, $queue[0]->type);
    }

    // ─── Integration: cancelAlert + extendAlert ─────────────────────────────

    public function testCancelThenExtendAlert(): void
    {
        $t = Toast::new(50)->alert(ToastType::Info, 'test', 10.0);

        // First cancel (makes it persistent)
        $cancelled = $t->cancelAlert(0);
        $queue1 = $this->getQueue($cancelled);
        $this->assertNull($queue1[0]->expiresAt);

        // Then extend (should still do nothing to persistent, but shouldn't error)
        $extended = $cancelled->extendAlert(0, 5.0);
        $queue2 = $this->getQueue($extended);
        $this->assertNull($queue2[0]->expiresAt);
    }

    // Helper to access private queue
    private function getQueue(Toast $t): array
    {
        $ref = (new \ReflectionClass($t))->getProperty('queue');
        $ref->setAccessible(true);
        return $ref->getValue($t);
    }
}
