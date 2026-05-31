<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffOptimiser;
use SugarCraft\Buffer\Diff\EraseRunOp;
use SugarCraft\Buffer\Diff\MoveCursorOp;
use SugarCraft\Buffer\Diff\RepeatRunOp;
use SugarCraft\Buffer\Diff\SetCellOp;
use SugarCraft\Buffer\Diff\SetHyperlinkOp;
use SugarCraft\Buffer\Diff\SetStyleOp;
use SugarCraft\Buffer\Style;

final class DiffOptimiserTest extends TestCase
{
    private DiffOptimiser $optimiser;

    protected function setUp(): void
    {
        $this->optimiser = new DiffOptimiser();
    }

    public function testOptimiseEmptyOps(): void
    {
        $result = $this->optimiser->optimise([]);

        $this->assertSame([], $result);
    }

    public function testOptimiseCollapseAdjacentSetStyleOps(): void
    {
        $ops = [
            new SetStyleOp(Style::new(null, null, Style::ATTR_BOLD)),
            new SetStyleOp(Style::new(null, null, Style::ATTR_ITALIC)),
            new SetStyleOp(Style::new(null, null, Style::ATTR_UNDERLINE)),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
        $this->assertEquals(Style::new(null, null, Style::ATTR_UNDERLINE), $result[0]->style);
    }

    public function testOptimisePreservesNonStyleBetweenStyles(): void
    {
        $ops = [
            new SetStyleOp(Style::new(null, null, Style::ATTR_BOLD)),
            new MoveCursorOp(5, 0),
            new SetStyleOp(Style::new(null, null, Style::ATTR_ITALIC)),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(3, $result);
    }

    public function testOptimiseMergeCellSpansSameStyle(): void
    {
        $style = Style::new(null, null, Style::ATTR_BOLD);
        $ops = [
            new SetCellOp([Cell::new('A', $style)]),
            new SetCellOp([Cell::new('B', $style)]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
        $this->assertCount(1, $result[0]->cells);
        $this->assertCount(1, $result[1]->cells);
    }

    public function testOptimiseDoesNotMergeDifferentStyles(): void
    {
        $styleA = Style::new(null, null, Style::ATTR_BOLD);
        $styleB = Style::new(null, null, Style::ATTR_ITALIC);
        $ops = [
            new SetCellOp([Cell::new('A', $styleA)]),
            new SetCellOp([Cell::new('B', $styleB)]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimiseDoesNotMergeDifferentLinks(): void
    {
        $linkA = new \SugarCraft\Buffer\Hyperlink('https://a.com');
        $linkB = new \SugarCraft\Buffer\Hyperlink('https://b.com');
        $ops = [
            new SetCellOp([Cell::new('A', null, $linkA)]),
            new SetCellOp([Cell::new('B', null, $linkB)]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimisePreservesMoveCursorOp(): void
    {
        $ops = [
            new MoveCursorOp(5, 2),
            new MoveCursorOp(10, 2),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(MoveCursorOp::class, $result[0]);
        $this->assertInstanceOf(MoveCursorOp::class, $result[1]);
    }

    public function testOptimisePreservesEraseRunOp(): void
    {
        $ops = [
            new EraseRunOp(5),
            new EraseRunOp(3),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimisePreservesRepeatRunOp(): void
    {
        $ops = [
            new RepeatRunOp('X', 5),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
    }

    public function testOptimisePreservesSetHyperlinkOp(): void
    {
        $link = new \SugarCraft\Buffer\Hyperlink('https://example.com');
        $ops = [
            new SetHyperlinkOp($link),
            new SetHyperlinkOp(null),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimiseRealisticDiffSequence(): void
    {
        $bold = Style::new(null, null, Style::ATTR_BOLD);
        $ops = [
            new SetStyleOp($bold),
            new SetCellOp([Cell::new('H', $bold)]),
            new SetCellOp([Cell::new('e', $bold)]),
            new SetStyleOp(Style::new()),
            new SetCellOp([Cell::new('l', null)]),
            new SetCellOp([Cell::new('l', null)]),
            new SetCellOp([Cell::new('o', null)]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertNotEmpty($result);
        foreach ($result as $op) {
            $this->assertInstanceOf(\SugarCraft\Buffer\Diff\DiffOp::class, $op);
        }
    }

    public function testOptimiseSingleOpPassThrough(): void
    {
        $ops = [new MoveCursorOp(0, 0)];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]->col);
        $this->assertSame(0, $result[0]->row);
    }

    public function testCoalesceRepeatsInSetCellOpSpan(): void
    {
        $cell = Cell::new('X');
        $ops = [
            new SetCellOp([$cell, $cell, $cell]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(SetCellOp::class, $result[0]);
        $this->assertCount(3, $result[0]->cells);
    }
}
