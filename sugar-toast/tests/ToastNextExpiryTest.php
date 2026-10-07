<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * The loop-integration helper: nextExpiry() / secondsUntilNextExpiry() let a
 * host schedule one precise prune tick instead of polling.
 */
final class ToastNextExpiryTest extends TestCase
{
    public function testNextExpiryNullWhenNoAlerts(): void
    {
        $t = Toast::new();
        $this->assertNull($t->nextExpiry());
        $this->assertNull($t->secondsUntilNextExpiry());
    }

    public function testNextExpiryNullWhenAlertsDoNotAutoDismiss(): void
    {
        // Default Toast has no duration, so an alert without an explicit
        // expiresAt never expires.
        $t = Toast::new()->info('persistent');

        $this->assertTrue($t->hasActiveAlert());
        $this->assertNull($t->nextExpiry());
        $this->assertNull($t->secondsUntilNextExpiry());
    }

    public function testNextExpiryReturnsSoonestAcrossAlerts(): void
    {
        // Explicit future expiries keep the assertion deterministic
        // (nextExpiry() does no microtime() math of its own) AND keep the
        // alerts alive under the expired-on-write prune in appendBounded().
        $base = \microtime(true);
        $t = Toast::new()
            ->alert(ToastType::Info, 'late', $base + 2.0)
            ->alert(ToastType::Error, 'soon', $base + 0.5)
            ->alert(ToastType::Success, 'mid', $base + 1.0);

        $this->assertSame($base + 0.5, $t->nextExpiry());
    }

    public function testNextExpiryIgnoresNonExpiringSiblings(): void
    {
        // Future expiry: alive through the expired-on-write prune.
        $base = \microtime(true);
        $t = Toast::new()
            ->info('persistent')              // no expiry
            ->alert(ToastType::Warning, 'timed', $base + 0.75);

        $this->assertSame($base + 0.75, $t->nextExpiry());
    }

    public function testNextExpiryReturnsPastInstantWhenAlreadyDue(): void
    {
        $past = \microtime(true) - 100.0;
        $t = Toast::new()->alert(ToastType::Info, 'overdue', $past);

        // nextExpiry() reports the raw instant (in the past) ...
        $this->assertSame($past, $t->nextExpiry());
        // ... while secondsUntilNextExpiry() clamps it so the host prunes now.
        $this->assertSame(0.0, $t->secondsUntilNextExpiry());
    }

    public function testSecondsUntilNextExpiryIsPositiveForAFutureAlert(): void
    {
        $t = Toast::new()->alert(ToastType::Info, 'future', \microtime(true) + 5.0);

        $delay = $t->secondsUntilNextExpiry();
        $this->assertNotNull($delay);
        $this->assertGreaterThan(4.0, $delay);
        $this->assertLessThanOrEqual(5.0, $delay);
    }

    public function testSecondsUntilNextExpiryNullWhenNothingExpires(): void
    {
        $this->assertNull(Toast::new()->secondsUntilNextExpiry());
        $this->assertNull(Toast::new()->info('x')->secondsUntilNextExpiry());
    }
}
