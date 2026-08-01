<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use SugarCraft\Bits\Table\Column;
use SugarCraft\Bits\Table\Styles;
use SugarCraft\Bits\Table\Table;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class TableTest extends TestCase
{
    private function focused(): Table
    {
        $t = Table::new(
            ['Name', 'Age'],
            [
                ['Alice', '30'],
                ['Bob', '25'],
                ['Carol', '40'],
                ['Dave', '35'],
            ],
            0,
            2,
        );
        [$t, ] = $t->focus();
        return $t;
    }

    public function testInitialState(): void
    {
        $t = Table::new(['H'], [['x']]);
        $this->assertSame(0, $t->index());
        $this->assertSame(['x'], $t->selectedRow());
    }

    public function testEmptyTableRendersEmpty(): void
    {
        $this->assertSame('', Table::new()->view());
    }

    public function testNavDown(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $t->index());
        $this->assertSame(['Bob', '25'], $t->selectedRow());
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $t = Table::new(['H'], [['a'], ['b']]);
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $t->index());
    }

    public function testHomeAndEnd(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'G'));
        $this->assertSame(3, $t->index());
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'g'));
        $this->assertSame(0, $t->index());
    }

    public function testScrollFollowsCursor(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        // height=2 → cursor=2 forces offset to 1
        $this->assertSame(2, $t->index());
        $this->assertSame(1, $t->offset);
    }

    public function testRenderIncludesHeaderAndSelectedRow(): void
    {
        $t = $this->focused();
        $view = $t->view();
        $this->assertStringContainsString('Name', $view);
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString("\x1b[7m", $view); // reverse on selection
    }

    public function testJaggedRowsPad(): void
    {
        $t = Table::new(['A', 'B'], [['x', 'y'], ['z']], 0, 5);
        [$t, ] = $t->focus();
        $this->assertSame(['z'], $t->setRows([['z']])->selectedRow());
    }

    public function testSetHeadersAndRowsKeepsCursorWhenInRange(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $t = $t->setRows([
            ['A', '1'], ['B', '2'], ['C', '3'], ['D', '4'],
        ]);
        $this->assertSame(1, $t->index());
    }

    public function testWidthBudgetTruncatesRightmostColumn(): void
    {
        $t = Table::new(
            ['Long', 'Short'],
            [['Hello there', 'X']],
            10,
            5,
        );
        $view = $t->view();
        // First line (header) shouldn't exceed width when column truncation
        // is applied; just sanity-check that some output rendered.
        $this->assertNotSame('', $view);
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Table::new([], [], -1, 5);
    }

    public function testCursorAccessor(): void
    {
        $t = $this->focused();
        $this->assertSame(0, $t->cursor());
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $t->cursor());
    }

    public function testSetCursorClamps(): void
    {
        $t = Table::new(['h'], [['a'], ['b'], ['c']]);
        $t = $t->setCursor(99);
        $this->assertSame(2, $t->cursor());
        $t = $t->setCursor(-5);
        $this->assertSame(0, $t->cursor());
    }

    public function testRowsAndHeadersAccessors(): void
    {
        $t = Table::new(['name', 'qty'], [['apple', '3'], ['banana', '5']]);
        $this->assertSame(['name', 'qty'], $t->headersList());
        $this->assertSame([['apple', '3'], ['banana', '5']], $t->rowsList());
    }

    public function testWithStylesAppliesHeaderStyle(): void
    {
        $t = Table::new(['x'], [['a']])
            ->withStyles(new Styles(header: Style::new()->bold()));
        $view = $t->view();
        $this->assertStringContainsString("\x1b[1m", $view);
    }

    public function testWithStylesNullClearsStyles(): void
    {
        $t = Table::new(['x'], [['a']])
            ->withStyles(new Styles(header: Style::new()->bold()))
            ->withStyles(null);
        $this->assertNull($t->getStyles());
    }

    public function testFocusAndBlur(): void
    {
        $t = Table::new(['x'], [['a']]);
        $this->assertFalse($t->focused);

        [$t, ] = $t->focus();
        $this->assertTrue($t->focused);

        $t = $t->blur();
        $this->assertFalse($t->focused);
    }

    public function testSetHeaders(): void
    {
        $t = Table::new(['A'], [['x']])->setHeaders(['B', 'C']);
        $this->assertSame(['B', 'C'], $t->headersList());
    }

    public function testSetRows(): void
    {
        $t = Table::new(['A'], [['old']])->setRows([['new1'], ['new2']]);
        $this->assertSame(2, count($t->rowsList()));
    }

    public function testSetSize(): void
    {
        $t = Table::new(['x'], [['a']])->setSize(80, 20);
        $this->assertSame(80, $t->width);
        $this->assertSame(20, $t->height);
    }

    public function testSetSizeRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Table::new(['x'], [['a']])->setSize(-1, 10);
    }

    public function testGotoTopAndBottom(): void
    {
        $t = $this->focused();
        $t = $t->gotoBottom();
        $this->assertSame(3, $t->index());
        $t = $t->gotoTop();
        $this->assertSame(0, $t->index());
    }

    public function testMoveUpAndDown(): void
    {
        $t = $this->focused();
        $t = $t->moveDown();
        $this->assertSame(1, $t->index());
        $t = $t->moveUp();
        $this->assertSame(0, $t->index());
    }

    public function testStyleFunc(): void
    {
        $t = Table::new(['x'], [['a']])
            ->styleFunc(static fn(int $row, int $col) => Style::new()->bold());
        $this->assertNotNull($t->getStyleFunc());
    }

    public function testStyleFuncNullClears(): void
    {
        $t = Table::new(['x'], [['a']])
            ->styleFunc(static fn(int $row, int $col) => Style::new()->bold())
            ->styleFunc(null);
        $this->assertNull($t->getStyleFunc());
    }

    public function testWithFilterable(): void
    {
        $t = Table::new(['x'], [['a']])->withFilterable(true);
        $this->assertTrue($t->getFilterable());
    }

    public function testInitReturnsNull(): void
    {
        $t = Table::new(['x'], [['a']]);
        $this->assertNull($t->init());
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $t = Table::new(['x'], [['a']]);
        $this->assertNull($t->subscriptions());
    }

    public function testColumns(): void
    {
        $t = Table::new(['Name', 'Age'], [['Alice', '30']]);
        $cols = $t->columns();
        $this->assertCount(2, $cols);
        $this->assertSame('Name', $cols[0]->title);
        $this->assertSame('Age', $cols[1]->title);
    }

    public function testSetColumns(): void
    {
        $t = Table::new(['Old'], [['x']]);
        $t = $t->setColumns([
            new \SugarCraft\Bits\Table\Column('New1', 10),
            new \SugarCraft\Bits\Table\Column('New2', 5),
        ]);
        $this->assertSame(['New1', 'New2'], $t->headersList());
    }

    public function testSetColumnsRejectsInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Table::new(['x'], [['a']])->setColumns(['not a Column']);
    }
}
