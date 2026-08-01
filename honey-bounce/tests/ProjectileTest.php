<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Tests;

use SugarCraft\Bounce\Point;
use SugarCraft\Bounce\Projectile;
use SugarCraft\Bounce\Spring;
use SugarCraft\Bounce\Vector;
use PHPUnit\Framework\TestCase;

final class ProjectileTest extends TestCase
{
    public function testZeroVelocityZeroAccelerationStaysPut(): void
    {
        $p = Projectile::new(
            deltaTime:    1.0,
            position:     new Point(5.0, 10.0),
            velocity:     Vector::zero(),
            acceleration: Vector::zero(),
        );
        $next = $p->update();
        $this->assertSame(5.0,  $next->position->x);
        $this->assertSame(10.0, $next->position->y);
        $this->assertSame(0.0,  $next->position->z);
        $this->assertSame(0.0,  $next->velocity->x);
        $this->assertSame(0.0,  $next->velocity->y);
        $this->assertSame(0.0,  $next->velocity->z);
    }

    public function testConstantVelocityAdvancesByVelocityTimesDt(): void
    {
        $p = Projectile::new(
            deltaTime:    0.5,
            position:     Point::zero(),
            velocity:     new Vector(2.0, 4.0),
            acceleration: Vector::zero(),
        );
        $next = $p->update();
        $this->assertSame(1.0, $next->position->x);  // 0 + 2 * 0.5
        $this->assertSame(2.0, $next->position->y);  // 0 + 4 * 0.5
        // Velocity unchanged because acceleration is zero.
        $this->assertSame(2.0, $next->velocity->x);
        $this->assertSame(4.0, $next->velocity->y);
    }

    public function testGravityIsYUpMatchingUpstream(): void
    {
        $g = Projectile::gravity();
        $this->assertSame(0.0,    $g->x);
        $this->assertEqualsWithDelta(-9.81, $g->y, 1e-9);  // Y-up: gravity is negative
        $this->assertSame(0.0,    $g->z);
    }

    public function testGravityAccelerationDecelaratesUpwardThrow(): void
    {
        // Throw upward (positive Y) at 10 m/s, gravity (negative Y)
        // should decelerate to zero around t=1s.
        $p = Projectile::new(
            deltaTime:    1.0,
            position:     Point::zero(),
            velocity:     new Vector(0.0, 10.0),
            acceleration: Projectile::gravity(),
        );
        $next = $p->update();
        // After 1s of -9.81 m/s² gravity: velocity y = 10 + (-9.81) = 0.19
        $this->assertEqualsWithDelta(0.19, $next->velocity->y, 1e-6);
    }

    public function testTerminalGravityIsYUp(): void
    {
        $tg = Projectile::terminalGravity();
        $this->assertEqualsWithDelta(-53.0, $tg->y, 1e-9);
        $this->assertSame(0.0, $tg->x);
        $this->assertSame(0.0, $tg->z);
    }

    public function testLegacyYDownGravityForBackCompat(): void
    {
        $g = Projectile::gravityYDown();
        $this->assertEqualsWithDelta(9.81, $g->y, 1e-9);
        $tg = Projectile::terminalGravityYDown();
        $this->assertEqualsWithDelta(53.0, $tg->y, 1e-9);
    }

    public function testProjectileSimulatesUpwardThrowWithYUpGravity(): void
    {
        $dt = Spring::fps(60);
        $p = Projectile::new(
            deltaTime:    $dt,
            position:     Point::zero(),
            velocity:     new Vector(0.0, 10.0),     // upward, Y-up
            acceleration: Projectile::gravity(),       // pulls down (-Y)
        );
        // Walk 60 frames (1 simulated second) and check the final
        // velocity has decayed toward zero.
        for ($i = 0; $i < 60; $i++) {
            $p = $p->update();
        }
        $this->assertEqualsWithDelta(0.19, $p->velocity->y, 0.05);
    }

    public function testVectorMath3D(): void
    {
        $a = new Vector(1.0, 2.0, 3.0);
        $b = new Vector(4.0, 5.0, 6.0);
        $sum = $a->add($b);
        $this->assertSame(5.0, $sum->x);
        $this->assertSame(7.0, $sum->y);
        $this->assertSame(9.0, $sum->z);
        $diff = $b->sub($a);
        $this->assertSame(3.0, $diff->x);
        $this->assertSame(3.0, $diff->y);
        $this->assertSame(3.0, $diff->z);
        $scaled = $a->scale(2.0);
        $this->assertSame(2.0, $scaled->x);
        $this->assertSame(4.0, $scaled->y);
        $this->assertSame(6.0, $scaled->z);
        // |(1,2,3)| = sqrt(14)
        $this->assertEqualsWithDelta(sqrt(14.0), $a->length(), 1e-9);
    }

    public function testVectorDotProduct(): void
    {
        $a = new Vector(1.0, 2.0, 3.0);
        $b = new Vector(4.0, 5.0, 6.0);
        $this->assertSame(1.0 * 4.0 + 2.0 * 5.0 + 3.0 * 6.0, $a->dot($b));
    }

    public function testVectorCrossProductFollowsRightHandRule(): void
    {
        // i × j = k
        $i = new Vector(1.0, 0.0, 0.0);
        $j = new Vector(0.0, 1.0, 0.0);
        $k = $i->cross($j);
        $this->assertSame(0.0, $k->x);
        $this->assertSame(0.0, $k->y);
        $this->assertSame(1.0, $k->z);
    }

    public function testVectorTwoArgConstructorDefaultsZToZero(): void
    {
        $v = new Vector(1.0, 2.0);
        $this->assertSame(0.0, $v->z);
    }

    public function testPointAddVector3D(): void
    {
        $p = new Point(10.0, 20.0, 30.0);
        $v = new Vector(1.0, 2.0, 3.0);
        $result = $p->add($v);
        $this->assertSame(11.0, $result->x);
        $this->assertSame(22.0, $result->y);
        $this->assertSame(33.0, $result->z);
    }

    public function testPointDistance(): void
    {
        $a = new Point(0.0, 0.0, 0.0);
        $b = new Point(3.0, 4.0, 0.0);
        $this->assertEqualsWithDelta(5.0, $a->distance($b), 1e-9);
    }

    public function testPointTwoArgConstructorDefaultsZToZero(): void
    {
        $p = new Point(1.0, 2.0);
        $this->assertSame(0.0, $p->z);
    }

    public function testGravityConstants(): void
    {
        $this->assertEqualsWithDelta(9.81, Projectile::GRAVITY,          1e-9);
        $this->assertEqualsWithDelta(53.0, Projectile::TERMINAL_GRAVITY, 1e-9);
    }

    public function testVelocityAccessorReturnsVelocityVector(): void
    {
        $v = new Vector(1.0, 2.0, 3.0);
        $p = Projectile::new(
            deltaTime:    1.0,
            position:     Point::zero(),
            velocity:     $v,
            acceleration: Vector::zero(),
        );
        $this->assertSame($v, $p->velocity());
    }

    public function testAccelerationAccessorReturnsAccelerationVector(): void
    {
        $a = Projectile::gravity();
        $p = Projectile::new(
            deltaTime:    1.0,
            position:     Point::zero(),
            velocity:     Vector::zero(),
            acceleration: $a,
        );
        $this->assertSame($a, $p->acceleration());
    }
}
