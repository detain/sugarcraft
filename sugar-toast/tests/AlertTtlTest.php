<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\Alert;
use SugarCraft\Toast\Toast;
use SugarCraft\Toast\ToastType;

/**
 * Alert::withTtl(): a relative lifetime whose clock starts when push()
 * queues the alert.
 */
final class AlertTtlTest extends TestCase
{
    public function testWithTtlStoresTheLifetimeWithoutArmingIt(): void
    {
        $base = Alert::new(ToastType::Error, 'Disk full');
        $alert = $base->withTtl(3.0);

        $this->assertNotSame($base, $alert);
        $this->assertNull($base->ttl);
        $this->assertSame(3.0, $alert->ttl);
        $this->assertNull($alert->expiresAt, 'nothing is armed until the alert is queued');
        $this->assertFalse($alert->isExpired());
    }

    public function testPushArmsTheTtlFromTheMomentOfQueueing(): void
    {
        $before = \microtime(true);
        $queued = Toast::new()->push(Alert::new(ToastType::Error, 'Disk full')->withTtl(3.0))->activeAlerts()[0];
        $after = \microtime(true);

        $this->assertNotNull($queued->expiresAt);
        $this->assertGreaterThanOrEqual($before + 3.0, $queued->expiresAt);
        $this->assertLessThanOrEqual($after + 3.0, $queued->expiresAt);
        $this->assertNull($queued->ttl, 'armed once: the lifetime became the expiry');
    }

    public function testTheTtlOverridesTheToastDuration(): void
    {
        $queued = Toast::new()->withDuration(60.0)
            ->push(Alert::new(ToastType::Info, 'brief')->withTtl(1.0))
            ->activeAlerts()[0];

        $this->assertLessThan(\microtime(true) + 2.0, $queued->expiresAt);
    }

    public function testWithTtlClearsPersistenceAndAbsoluteExpiry(): void
    {
        $alert = Alert::new(ToastType::Info, 'x')->withExpiry(50.0)->withCancelledExpiry()->withTtl(2.0);

        $this->assertFalse($alert->persistent);
        $this->assertNull($alert->expiresAt);
        $this->assertSame(2.0, $alert->ttl);
    }

    public function testTheOtherExpiryDoorsClearAPendingTtl(): void
    {
        $alert = Alert::new(ToastType::Info, 'x')->withTtl(2.0);

        $this->assertNull($alert->withExpiry(5.0)->ttl);
        $this->assertNull($alert->withExtendedExpiry(5.0)->ttl);
        $this->assertNull($alert->withCancelledExpiry()->ttl);
    }

    public function testTheFactoryAndConstructorCarryTheTtl(): void
    {
        $this->assertSame(4.0, Alert::new(ToastType::Info, 'x', ttl: 4.0)->ttl);
        $this->assertSame(4.0, (new Alert(ToastType::Info, 'x', ttl: 4.0))->withProgress(0.5)->ttl);
    }

    /** @return iterable<string, array{float}> */
    public static function badTtls(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'nan' => [\NAN];
        yield 'inf' => [\INF];
    }

    /** @dataProvider badTtls */
    public function testWithTtlRefusesANonPositiveOrNonFiniteLifetime(float $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Alert::new(ToastType::Info, 'x')->withTtl($ttl);
    }

    /** @dataProvider badTtls */
    public function testTheConstructorRefusesTheSameLifetimes(float $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Alert(ToastType::Info, 'x', ttl: $ttl);
    }
}
