<?php

declare(strict_types=1);

namespace CandyCore\Mines\Tests;

use CandyCore\Mines\Cell;
use PHPUnit\Framework\TestCase;

final class CellTest extends TestCase
{
    public function testDefaultCellIsHidden(): void
    {
        $c = new Cell(mine: false);
        $this->assertFalse($c->mine);
        $this->assertFalse($c->revealed);
        $this->assertFalse($c->flagged);
        $this->assertSame(0, $c->adjacent);
    }

    public function testWithMineReturnsNewCellAndPreservesState(): void
    {
        $original = new Cell(mine: false, revealed: true, flagged: false, adjacent: 3);
        $next = $original->withMine(true);
        $this->assertNotSame($original, $next);
        $this->assertTrue($next->mine);
        $this->assertTrue($next->revealed);
        $this->assertFalse($next->flagged);
        $this->assertSame(3, $next->adjacent);
        // Original unchanged.
        $this->assertFalse($original->mine);
    }

    public function testWithAdjacent(): void
    {
        $c = (new Cell(mine: false))->withAdjacent(5);
        $this->assertSame(5, $c->adjacent);
    }

    public function testRevealUnrevealedNonFlaggedCell(): void
    {
        $next = (new Cell(mine: false))->reveal();
        $this->assertTrue($next->revealed);
    }

    public function testRevealOnAlreadyRevealedReturnsSameInstance(): void
    {
        $c = new Cell(mine: false, revealed: true);
        $this->assertSame($c, $c->reveal());
    }

    public function testRevealIgnoredWhenFlagged(): void
    {
        $c = new Cell(mine: false, flagged: true);
        $next = $c->reveal();
        $this->assertSame($c, $next);
        $this->assertFalse($next->revealed);
    }

    public function testToggleFlagOnHiddenCell(): void
    {
        $c = new Cell(mine: false);
        $on = $c->toggleFlag();
        $this->assertTrue($on->flagged);
        $off = $on->toggleFlag();
        $this->assertFalse($off->flagged);
    }

    public function testToggleFlagOnRevealedReturnsSameInstance(): void
    {
        $c = new Cell(mine: false, revealed: true);
        $this->assertSame($c, $c->toggleFlag());
    }

    public function testCellPreservesAdjacentThroughTransitions(): void
    {
        $c = (new Cell(mine: false, adjacent: 4))->reveal();
        $this->assertSame(4, $c->adjacent);
    }
}
