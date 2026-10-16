<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use SugarCraft\Spring\Gravity;
use SugarCraft\Spring\Projectile;
use SugarCraft\Spring\Vector;
use PHPUnit\Framework\TestCase;

final class GravityTest extends TestCase
{
    public function testStandardIsYUp(): void
    {
        $g = Gravity::standard();
        $this->assertSame(0.0,    $g->x);
        $this->assertSame(-9.81,  $g->y);
        $this->assertSame(0.0,    $g->z);
    }

    public function testTerminalIsYUp(): void
    {
        $g = Gravity::terminal();
        $this->assertSame(0.0,   $g->x);
        $this->assertSame(-53.0, $g->y);
    }

    public function testStandardYDownIsPositiveY(): void
    {
        $g = Gravity::standardYDown();
        $this->assertSame(9.81, $g->y);
    }

    public function testTerminalYDownIsPositiveY(): void
    {
        $g = Gravity::terminalYDown();
        $this->assertSame(53.0, $g->y);
    }

    public function testEachAccessorReturnsFreshInstance(): void
    {
        $a = Gravity::standard();
        $b = Gravity::standard();
        $this->assertNotSame($a, $b);
        $this->assertEquals($a, $b);
    }

    public function testAliasesProjectileFactories(): void
    {
        $this->assertEquals(Projectile::gravity(),             Gravity::standard());
        $this->assertEquals(Projectile::terminalGravity(),     Gravity::terminal());
        $this->assertEquals(Projectile::gravityYDown(),        Gravity::standardYDown());
        $this->assertEquals(Projectile::terminalGravityYDown(), Gravity::terminalYDown());
    }

    public function testReturnedVectorPluggableAsAcceleration(): void
    {
        $p = Projectile::new(
            deltaTime:    1.0 / 60.0,
            position:     new \SugarCraft\Spring\Point(0.0, 0.0),
            velocity:     new Vector(0.0, 10.0),
            acceleration: Gravity::standard(),
        );
        // After one frame the Y velocity should drop by 9.81 * dt.
        $next = $p->update();
        $this->assertEqualsWithDelta(10.0 - 9.81 / 60.0, $next->velocity->y, 1e-6);
    }
}
