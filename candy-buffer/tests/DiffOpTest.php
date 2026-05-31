<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffOp;
use SugarCraft\Buffer\Diff\EraseRunOp;
use SugarCraft\Buffer\Diff\MoveCursorOp;
use SugarCraft\Buffer\Diff\RepeatRunOp;
use SugarCraft\Buffer\Diff\SetCellOp;
use SugarCraft\Buffer\Diff\SetHyperlinkOp;
use SugarCraft\Buffer\Diff\SetStyleOp;
use SugarCraft\Buffer\Hyperlink;
use SugarCraft\Buffer\Style;

final class DiffOpTest extends TestCase
{
    public function testTypeConstants(): void
    {
        $this->assertSame('move_cursor', DiffOp::TYPE_MOVE_CURSOR);
        $this->assertSame('set_cell', DiffOp::TYPE_SET_CELL);
        $this->assertSame('erase_run', DiffOp::TYPE_ERASE_RUN);
        $this->assertSame('repeat_run', DiffOp::TYPE_REPEAT_RUN);
        $this->assertSame('set_style', DiffOp::TYPE_SET_STYLE);
        $this->assertSame('set_hyperlink', DiffOp::TYPE_SET_HYPERLINK);
    }

    public function testMoveCursorOpConstruction(): void
    {
        $op = new MoveCursorOp(5, 3);

        $this->assertSame(5, $op->col);
        $this->assertSame(3, $op->row);
    }

    public function testMoveCursorOpInfo(): void
    {
        $op = new MoveCursorOp(5, 3);

        $info = $op->info();
        $this->assertSame(5, $info['col']);
        $this->assertSame(3, $info['row']);
    }

    public function testSetCellOpConstruction(): void
    {
        $cell = Cell::new('X', Style::bold());
        $op = new SetCellOp([$cell]);

        $this->assertCount(1, $op->cells);
        $this->assertSame('X', $op->cells[0]->rune());
    }

    public function testSetCellOpCount(): void
    {
        $op = new SetCellOp([
            Cell::new('A'),
            Cell::new('B'),
            Cell::new('C'),
        ]);

        $this->assertSame(3, $op->count());
    }

    public function testEraseRunOpConstruction(): void
    {
        $op = new EraseRunOp(10);

        $this->assertSame(10, $op->count);
    }

    public function testRepeatRunOpConstruction(): void
    {
        $op = new RepeatRunOp('X', 5, 1);

        $this->assertSame('X', $op->rune);
        $this->assertSame(5, $op->count);
        $this->assertSame(1, $op->width);
    }

    public function testRepeatRunOpDefaultWidth(): void
    {
        $op = new RepeatRunOp('Y', 3);

        $this->assertSame(1, $op->width);
    }

    public function testSetStyleOpConstruction(): void
    {
        $style = Style::bold();
        $op = new SetStyleOp($style);

        $this->assertSame($style, $op->style);
    }

    public function testSetStyleOpWithNullStyle(): void
    {
        $op = new SetStyleOp(null);

        $this->assertNull($op->style);
    }

    public function testSetHyperlinkOpConstruction(): void
    {
        $link = Hyperlink::new('https://example.com', 'id123');
        $op = new SetHyperlinkOp($link);

        $this->assertSame($link, $op->hyperlink);
        $this->assertSame('https://example.com', $op->hyperlink->url());
        $this->assertSame('id123', $op->hyperlink->id());
    }

    public function testSetHyperlinkOpClose(): void
    {
        $op = new SetHyperlinkOp(null);

        $this->assertNull($op->hyperlink);
    }
}
