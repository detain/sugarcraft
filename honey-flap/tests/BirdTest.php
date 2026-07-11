<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use SugarCraft\Bounce\Projectile;
use SugarCraft\Flap\Bird;
use PHPUnit\Framework\TestCase;

final class BirdTest extends TestCase
{
    public function testSpawnPlacesBirdAtRequestedPosition(): void
    {
        $b = Bird::spawn(8, 10.0);
        $this->assertSame(8, $b->x);
        $this->assertSame(10, $b->row());
    }

    public function testGravityPullsBirdDownEachTick(): void
    {
        $b = Bird::spawn(8, 5.0);
        $r0 = $b->row();
        for ($i = 0; $i < 10; $i++) $b = $b->tick();
        $this->assertGreaterThan($r0, $b->row(), 'bird should fall under gravity');
    }

    public function testFlapKicksBirdUp(): void
    {
        $b = Bird::spawn(8, 10.0);
        $b = $b->flap();
        for ($i = 0; $i < 5; $i++) $b = $b->tick();
        $this->assertLessThan(10, $b->row(), 'flap should drive bird upward briefly');
    }

    public function testRowReturnsRoundedY(): void
    {
        $b = Bird::spawn(8, 7.49);
        $this->assertSame(7, $b->row());
        $b2 = Bird::spawn(8, 7.51);
        $this->assertSame(8, $b2->row());
    }

    public function testFallVelocityIsClampedToTerminalGravity(): void
    {
        // Free fall accumulates ~0.6 cells/s of downward velocity per tick, so
        // after ~90 ticks an UNCLAMPED bird would blow well past terminal
        // velocity. Tick far beyond that and assert the cap held.
        $b = Bird::spawn(8, 0.0);
        for ($i = 0; $i < 300; $i++) {
            $b = $b->tick();
        }
        $this->assertLessThanOrEqual(
            Projectile::TERMINAL_GRAVITY,
            $b->body->velocity->y,
            'downward fall velocity must not exceed terminal gravity',
        );
        // And the clamp actually engaged — it didn't pass merely because the
        // bird never sped up.
        $this->assertEqualsWithDelta(
            Projectile::TERMINAL_GRAVITY,
            $b->body->velocity->y,
            0.001,
        );
    }
}
