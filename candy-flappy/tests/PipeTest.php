<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use SugarCraft\Flappy\Pipe;
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
        // M2: gapHeight is the number of OPEN rows. Even bands tilt one
        // row toward the floor: y=8 h=6 opens rows 6..11, not 5..11.
        $p = new Pipe(x: 10, gapY: 8, gapHeight: 6);
        $this->assertTrue($p->collides(10, 0));    // way above
        $this->assertTrue($p->collides(10, 4));    // just above gap
        $this->assertTrue($p->collides(10, 5));    // was open under the old ±half split
        $this->assertFalse($p->collides(10, 6));   // top of gap
        $this->assertFalse($p->collides(10, 8));   // centre
        $this->assertFalse($p->collides(10, 11));  // bottom of gap
        $this->assertTrue($p->collides(10, 12));   // just below gap
        $this->assertTrue($p->collides(10, 17));   // way below
    }

    public function testGapOpensExactlyGapHeightRowsForEveryDifficultyBand(): void
    {
        // README difficulty table: gap heights 6/5/4/3 must open exactly
        // that many rows (the old ±intdiv(h,2) split opened 7/5/5/3).
        foreach ([6, 5, 4, 3] as $h) {
            $p = new Pipe(x: 10, gapY: 8, gapHeight: $h);
            $open = [];
            for ($row = 0; $row <= 16; $row++) {
                if ($p->collides(10, $row) === false) {
                    $open[] = $row;
                }
            }
            $this->assertCount($h, $open, "gapHeight {$h} must open exactly {$h} rows");
            $this->assertSame(
                range(8 - intdiv($h - 1, 2), 8 + intdiv($h, 2)),
                $open,
                "gapHeight {$h} must open a contiguous band",
            );
        }
    }

    public function testEvenGapTiltsTowardTheFloor(): void
    {
        // The documented asymmetry: with an even band the extra open row
        // goes to the floor side, so the ceiling side is the tight one.
        $p = new Pipe(x: 10, gapY: 8, gapHeight: 6);
        $this->assertTrue($p->collides(10, 5));   // ceiling side gives up its row
        $this->assertFalse($p->collides(10, 11)); // floor side keeps the extra row
    }

    public function testIsOffScreenWhenColumnNegative(): void
    {
        $this->assertFalse((new Pipe(0, 8, 6))->isOffScreen());
        $this->assertTrue((new Pipe(-1, 8, 6))->isOffScreen());
    }
}
