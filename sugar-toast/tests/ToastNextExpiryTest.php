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

    // ------------------------------------------------------------------
    // TST-TTL arms: $ttlSeconds is a relative lifetime resolved into an
    // absolute epoch AT THE DOOR (the conversion Alert::withExpiry() already
    // does), mutually exclusive with $expiresAt, and available on the four
    // shortcuts, which used to take the message alone — extra arguments
    // were silently DROPPED by PHP, buttons and id included.
    // ------------------------------------------------------------------

    public function testTtlSecondsResolvesToAbsoluteEpochWithinItsOwnBracket(): void
    {
        $before = \microtime(true);
        $t = Toast::new()->alert(ToastType::Info, 'hi', ttlSeconds: 5.0);
        $after = \microtime(true);

        $expires = $t->activeAlerts()[0]->expiresAt;
        $this->assertNotNull($expires);
        $this->assertGreaterThanOrEqual($before + 5.0, $expires, 'ttl resolved short of a full second');
        $this->assertLessThanOrEqual($after + 5.0, $expires, 'ttl resolved past the door instant');
    }

    public function testTtlAndAbsoluteExpiryTogetherThrowNamingBothParams(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$expiresAt and $ttlSeconds are mutually exclusive');
        Toast::new()->alert(ToastType::Info, 'hi', \microtime(true) + 60.0, ttlSeconds: 5.0);
    }

    public function testProgressToastCarriesTheSameTtlDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$expiresAt and $ttlSeconds are mutually exclusive');
        Toast::new()->progressToast(ToastType::Info, 'sync', 0.5, \microtime(true) + 60.0, ttlSeconds: 5.0);
    }

    public function testTtlBeatsConfiguredDurationInheritance(): void
    {
        // A resolved ttl is no longer "null expiry", so the duration-inheritance
        // arm must not overwrite the caller's explicit relative lifetime.
        $before = \microtime(true);
        $t = Toast::new()->withDuration(300.0)->alert(ToastType::Info, 'mine', ttlSeconds: 5.0);
        $after = \microtime(true);

        $expires = $t->activeAlerts()[0]->expiresAt;
        $this->assertLessThanOrEqual($after + 5.0, $expires, 'duration clobbered the explicit ttl');
        $this->assertGreaterThanOrEqual($before + 5.0, $expires);
    }

    public function testShortcutCarriesActionsIdAndTtlTogether(): void
    {
        $action = new \SugarCraft\Toast\Action('Retry', static function (): void {});
        $before = \microtime(true);
        $t = Toast::new(60)->info('Save changes?', ttlSeconds: 5.0, actions: [$action], id: 'x1');
        $after = \microtime(true);

        $alert = $t->activeAlerts()[0];
        $this->assertSame([$action], $alert->actions, 'shortcut dropped the buttons');
        $this->assertSame('x1', $alert->id, 'shortcut dropped the id');
        $this->assertGreaterThanOrEqual($before + 5.0, $alert->expiresAt);
        $this->assertLessThanOrEqual($after + 5.0, $alert->expiresAt);
    }

    public function testNonFiniteTtlIsRefusedNotImmortalised(): void
    {
        // The resolved instant rides the Alert epoch guard: NAN + t = NAN.
        foreach ([\NAN, \INF] as $bad) {
            try {
                Toast::new()->alert(ToastType::Info, 'hi', ttlSeconds: $bad);
                $this->fail('non-finite ttlSeconds must throw');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('finite absolute epoch', $e->getMessage());
            }
        }
    }

    public function testTtlActuallyExpiresTheAlertEndToEnd(): void
    {
        // Scaled-down probe of the acceptance contract: ttlSeconds: 0.02 must
        // leave the queue past its window with zero host clock math.
        $t = Toast::new()->alert(ToastType::Info, 'blink', ttlSeconds: 0.02);
        $this->assertTrue($t->hasActiveAlert());
        \usleep(30_000);
        $this->assertFalse($t->pruneExpired()->hasActiveAlert());
    }
}
