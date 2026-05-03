<?php

declare(strict_types=1);

namespace CandyCore\Flap\Tests;

use CandyCore\Flap\Bird;
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
}
