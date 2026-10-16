<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use SugarCraft\Animation\Frame;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    public function testWidthZeroForEmptyCells(): void
    {
        $f = new Frame([]);
        $this->assertSame(0, $f->width());
    }

    public function testWidthReturnsFirstRowCount(): void
    {
        $f = new Frame([
            [[255, 0, 0], [0, 255, 0]],
            [[0, 0, 255], [128, 128, 128]],
        ]);
        $this->assertSame(2, $f->width());
    }

    public function testHeightReturnsRowCount(): void
    {
        $f = new Frame([
            [[255, 0, 0]],
            [[0, 255, 0]],
            [[0, 0, 255]],
        ]);
        $this->assertSame(3, $f->height());
    }

    public function testCellsAreStoredReadonly(): void
    {
        $cells = [[[255, 0, 0]]];
        $f = new Frame($cells);
        $this->assertSame(255, $f->cells[0][0][0]);
    }
}
