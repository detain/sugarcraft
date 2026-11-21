<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\RateGate;

final class RateGateTest extends TestCase
{
    private float $now = 100.0;

    private function clock(): float
    {
        return $this->now;
    }

    public function testTheFirstCallIsAlwaysAllowed(): void
    {
        $gate = RateGate::new(1.0, $this->clock(...));

        self::assertTrue($gate->allow());
    }

    public function testCallsInsideTheWindowAreDenied(): void
    {
        $gate = RateGate::new(1.0, $this->clock(...));
        $gate->allow();

        $this->now += 0.5;
        self::assertFalse($gate->allow());
    }

    public function testTheWindowReopensExactlyAtTheInterval(): void
    {
        $gate = RateGate::new(1.0, $this->clock(...));
        $gate->allow();

        $this->now += 1.0;
        self::assertTrue($gate->allow());
        self::assertFalse($gate->allow());
    }

    public function testDeniedCallsDoNotMoveTheWindow(): void
    {
        $gate = RateGate::new(1.0, $this->clock(...));
        $gate->allow();

        // A steady stream of denials must not push the reopening point out.
        // Quarter-second steps are exact in binary floating point.
        for ($i = 0; $i < 3; $i++) {
            $this->now += 0.25;
            self::assertFalse($gate->allow());
        }
        $this->now += 0.25;
        self::assertTrue($gate->allow());
    }

    public function testResetAnswersTheNextCallAllowed(): void
    {
        $gate = RateGate::new(60.0, $this->clock(...));
        $gate->allow();
        self::assertFalse($gate->allow());

        $gate->reset();
        self::assertTrue($gate->allow());
    }

    public function testSecondsReportsTheConfiguredSpacing(): void
    {
        self::assertSame(2.5, RateGate::new(2.5, $this->clock(...))->seconds());
    }

    public function testTheDefaultClockAllowsTheFirstCall(): void
    {
        self::assertTrue(RateGate::new(60.0)->allow());
    }

    /** @dataProvider invalidSecondsProvider */
    public function testRejectsANonPositiveOrNonFiniteInterval(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RateGate::new($seconds);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidSecondsProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.5];
        yield 'infinite' => [INF];
        yield 'nan' => [NAN];
    }
}
