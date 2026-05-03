<?php

declare(strict_types=1);

namespace CandyCore\Mines\Tests;

use CandyCore\Mines\Board;
use PHPUnit\Framework\TestCase;

final class BoardTest extends TestCase
{
    public function testBlankBoardHasNoRevealedOrMineCells(): void
    {
        $b = Board::blank(5, 5, 3);
        $this->assertSame(5, $b->width);
        $this->assertSame(5, $b->height);
        $this->assertFalse($b->minesPlaced);
        foreach ($b->rows() as $row) {
            foreach ($row as $c) {
                $this->assertFalse($c->revealed);
                $this->assertFalse($c->mine);
            }
        }
    }

    public function testInvalidDimensionsThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Board::blank(1, 5, 1);
    }

    public function testInvalidMineCountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 5×5 grid = 25 cells; minimum 1 unmined cell required → cap at 24.
        Board::blank(5, 5, 25);
    }

    public function testFirstRevealAvoidsMinesNearCursor(): void
    {
        // Deterministic shuffle: use $rand that always returns $max
        // → equivalent to no-shuffle.
        $rand = static fn(int $max): int => $max;
        $b = Board::blank(5, 5, 5)->reveal(2, 2, $rand);
        // Centre cell + its 8 neighbours must not be mined.
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $cell = $b->cell(2 + $dx, 2 + $dy);
                $this->assertNotNull($cell);
                $this->assertFalse($cell->mine, "expected (2,2)+($dx,$dy) safe");
            }
        }
    }

    public function testFirstRevealOnEmptyAreaFloodsRecursively(): void
    {
        // Board with no mines at all: everything should reveal in one click.
        $rand = static fn(int $max): int => 0;
        // We need at least 1 mine; place it in a corner the flood won't reach
        // before stopping at adjacency cells.
        $b = Board::blank(5, 5, 1)->reveal(2, 2, $rand);
        $revealedCount = 0;
        $unrevealed = 0;
        foreach ($b->rows() as $row) {
            foreach ($row as $c) {
                if ($c->revealed) $revealedCount++;
                else $unrevealed++;
            }
        }
        // The whole board minus the corner mine + adjacency border should reveal.
        $this->assertGreaterThan(15, $revealedCount);
    }

    public function testFlagToggle(): void
    {
        $b = Board::blank(3, 3, 1);
        $b = $b->toggleFlag(1, 1);
        $this->assertTrue($b->cell(1, 1)->flagged);
        $b = $b->toggleFlag(1, 1);
        $this->assertFalse($b->cell(1, 1)->flagged);
    }

    public function testFlaggedCellCannotReveal(): void
    {
        $rand = static fn(int $max): int => 0;
        $b = Board::blank(3, 3, 1)->toggleFlag(1, 1)->reveal(1, 1, $rand);
        $this->assertFalse($b->cell(1, 1)->revealed);
    }

    public function testRevealingMineExplodes(): void
    {
        // Force every "random" pick to keep the original ordering, then
        // reveal a corner that we know is in the candidate-list tail.
        $rand = static fn(int $max): int => 0;
        // Click at (0,0): mines placed in cells outside that 3×3.
        $b = Board::blank(3, 4, 1)->reveal(0, 0, $rand);
        $this->assertTrue($b->minesPlaced);
        $this->assertFalse($b->exploded);  // first click is always safe
        // Find the mined cell + reveal it.
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                if ($b->cell($x, $y)->mine) {
                    $b = $b->reveal($x, $y, $rand);
                    break 2;
                }
            }
        }
        $this->assertTrue($b->exploded);
    }

    public function testWinDetection(): void
    {
        $rand = static fn(int $max): int => 0;
        $b = Board::blank(3, 3, 1)->reveal(0, 0, $rand);
        // Find the mine cell, flag it; reveal everything else.
        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 3; $x++) {
                if (!$b->cell($x, $y)->mine && !$b->cell($x, $y)->revealed) {
                    $b = $b->reveal($x, $y, $rand);
                }
            }
        }
        $this->assertFalse($b->exploded);
        $this->assertTrue($b->isWon());
    }
}
