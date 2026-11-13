<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use SugarCraft\Minesweeper\Cell;
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

    public function testMarkerCyclesFlagQuestionThenClear(): void
    {
        // msw-28: the hidden-cell marker walks a strict 3-cycle.
        $c = new Cell(mine: false);
        $flagged = $c->cycleMarker();
        $this->assertTrue($flagged->flagged);
        $this->assertFalse($flagged->questioned);
        $questioned = $flagged->cycleMarker();
        $this->assertFalse($questioned->flagged, 'F hands off to ?');
        $this->assertTrue($questioned->questioned);
        $this->assertSame($c->adjacent, $questioned->adjacent, 'the cycle never touches the census');
        $cleared = $questioned->cycleMarker();
        $this->assertFalse($cleared->questioned);
        $this->assertFalse($cleared->flagged);
    }

    public function testQuestionMarkClearsOnReveal(): void
    {
        // A memo is a note about a HIDDEN cell — opening it retires the
        // note, and the revealed+questioned pair must never exist anywhere
        // (the save loader refuses it, so reveal() is the only creator).
        $c = new Cell(mine: false, adjacent: 3, questioned: true);
        $open = $c->reveal();
        $this->assertTrue($open->revealed);
        $this->assertFalse($open->questioned);
        $this->assertSame(3, $open->adjacent);
    }

    public function testToggleFlagOnRevealedReturnsSameInstance(): void
    {
        $c = new Cell(mine: false, revealed: true);
        $this->assertSame($c, $c->cycleMarker());
    }

    public function testCellPreservesAdjacentThroughTransitions(): void
    {
        $c = (new Cell(mine: false, adjacent: 4))->reveal();
        $this->assertSame(4, $c->adjacent);
    }
}
