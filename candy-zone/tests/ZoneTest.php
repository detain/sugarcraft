<?php

declare(strict_types=1);

namespace CandyCore\Zone\Tests;

use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Zone\Zone;
use PHPUnit\Framework\TestCase;

final class ZoneTest extends TestCase
{
    private function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }

    public function testInBoundsTrueAtCorners(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertTrue($z->inBounds($this->click(5, 2)));
        $this->assertTrue($z->inBounds($this->click(10, 2)));
        $this->assertTrue($z->inBounds($this->click(5, 4)));
        $this->assertTrue($z->inBounds($this->click(10, 4)));
    }

    public function testInBoundsTrueInteriorPoint(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertTrue($z->inBounds($this->click(7, 3)));
    }

    public function testInBoundsFalseOutsideOnEachAxis(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertFalse($z->inBounds($this->click(4, 3)));   // x too small
        $this->assertFalse($z->inBounds($this->click(11, 3)));  // x too large
        $this->assertFalse($z->inBounds($this->click(7, 1)));   // y too small
        $this->assertFalse($z->inBounds($this->click(7, 5)));   // y too large
    }

    public function testPosReturnsZeroBasedOffset(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertSame([0, 0], $z->pos($this->click(5, 2)));
        $this->assertSame([2, 1], $z->pos($this->click(7, 3)));
        $this->assertSame([5, 2], $z->pos($this->click(10, 4)));
    }

    public function testPosNegativeOutsideZone(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        [$col, $row] = $z->pos($this->click(2, 1));
        $this->assertSame(-3, $col);
        $this->assertSame(-1, $row);
    }

    public function testWidthAndHeight(): void
    {
        $z = new Zone('btn', 5, 2, 10, 4);
        $this->assertSame(6, $z->width());
        $this->assertSame(3, $z->height());
    }

    public function testSingleCellZone(): void
    {
        $z = new Zone('px', 1, 1, 1, 1);
        $this->assertSame(1, $z->width());
        $this->assertSame(1, $z->height());
        $this->assertTrue($z->inBounds($this->click(1, 1)));
        $this->assertFalse($z->inBounds($this->click(2, 1)));
    }

    public function testIdIsExposed(): void
    {
        $z = new Zone('myButton', 0, 0, 5, 5);
        $this->assertSame('myButton', $z->id);
    }
}
