<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\App\BrowseState;
use SugarCraft\Sql\App\ConnectionState;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\NullDatabase;
use SugarCraft\Sql\ResultTable;

/**
 * SQL-O03 cell cursor: the column cursor drives horizontal scroll and paints
 * as a reverse-video cell, but only where the renderer asks for it.
 */
final class ResultTableCursorTest extends TestCase
{
    private function wide(): ResultTable
    {
        $row = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $c) {
            $row[$c] = str_repeat($c, 20);
        }
        return ResultTable::fromRows([$row, $row])->withVisibleWidth(50);
    }

    public function testRevealColumnScrollsJustFarEnough(): void
    {
        $t = $this->wide();
        $this->assertSame(0, $t->offset);

        $shown = $t->revealColumn(2);
        $this->assertGreaterThan(0, $shown->offset);
        $this->assertLessThanOrEqual(2, $shown->offset);
        $this->assertSame($shown->offset, $shown->revealColumn(2)->offset, 'already visible -> no-op');

        $back = $shown->revealColumn(0);
        $this->assertSame(0, $back->offset, 'a column left of the window scrolls back to it');
    }

    public function testRevealColumnClampsToTheColumnRange(): void
    {
        $t = $this->wide();

        $this->assertSame($t->revealColumn(5)->offset, $t->revealColumn(500)->offset);
        $this->assertSame(0, $t->revealColumn(-3)->offset);
    }

    public function testCursorCellIsReverseVideoOnTheCursorRowOnly(): void
    {
        $t = $this->wide()->withViewport(10, 1)->withCursorCol(0);
        $lines = explode("\n", $t->render());

        $this->assertStringNotContainsString("\e[7m", $lines[1], 'row 0 is not the cursor row');
        $this->assertStringContainsString("\e[7m", $lines[2], 'row 1 carries the cursor cell');
    }

    public function testMinusOneMeansNoHighlight(): void
    {
        $t = $this->wide()->withViewport(10, 1)->withCursorCol(-1);

        $this->assertStringNotContainsString("\e[7m", $t->render());
    }

    public function testCursorColumnIsScrolledIntoViewAtRenderTime(): void
    {
        $t = $this->wide()->withViewport(10, 0)->withCursorCol(5);

        $this->assertStringContainsString(str_repeat('f', 20), $t->render());
        $this->assertStringContainsString("\e[7m", $t->render());
    }

    public function testBrowseStateColCursorIsImmutableAndNeverNegative(): void
    {
        $s = BrowseState::new();

        $this->assertSame(0, $s->colCursor);
        $this->assertSame(3, $s->withColCursor(3)->colCursor);
        $this->assertSame(0, $s->withColCursor(-4)->colCursor);
        $this->assertSame(0, $s->colCursor, 'the original is untouched');
    }

    public function testConnectionStateReadOnlyFlag(): void
    {
        $c = ConnectionState::new(new NullDatabase(), Flavor::Sqlite);

        $this->assertFalse($c->readOnly);
        $this->assertTrue($c->withReadOnly(true)->readOnly);
        $this->assertTrue(ConnectionState::new(new NullDatabase(), Flavor::Sqlite, null, '', true)->readOnly);
        $this->assertFalse($c->readOnly);
    }
}
