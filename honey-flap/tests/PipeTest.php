<?php

declare(strict_types=1);

namespace CandyCore\Flap\Tests;

use CandyCore\Flap\Pipe;
use PHPUnit\Framework\TestCase;

final class PipeTest extends TestCase
{
    public function testTickShiftsPipeLeft(): void
    {
        $p = new Pipe(x: 10, gapY: 8, gapHeight: 6);
        $this->assertSame(10, $p->x);
        $this->assertSame(9, $p->tick()->x);
    }

    public function testCollidesOnlyAtMatchingColumn(): void
    {
        $p = new Pipe(x: 10, gapY: 8, gapHeight: 6);
        $this->assertFalse($p->collides(9, 0));
        $this->assertFalse($p->collides(11, 0));
    }

    public function testCellsAboveAndBelowGapCollide(): void
    {
        // gap centred at y=8, height=6 → open from y=5..y=11.
        $p = new Pipe(x: 10, gapY: 8, gapHeight: 6);
        $this->assertTrue($p->collides(10, 0));    // way above
        $this->assertTrue($p->collides(10, 4));    // just above gap
        $this->assertFalse($p->collides(10, 5));   // top of gap
        $this->assertFalse($p->collides(10, 8));   // centre
        $this->assertFalse($p->collides(10, 11));  // bottom of gap
        $this->assertTrue($p->collides(10, 12));   // just below gap
        $this->assertTrue($p->collides(10, 17));   // way below
    }

    public function testIsOffScreenWhenColumnNegative(): void
    {
        $this->assertFalse((new Pipe(0, 8, 6))->isOffScreen());
        $this->assertTrue((new Pipe(-1, 8, 6))->isOffScreen());
    }
}
