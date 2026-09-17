<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\ItemList;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Forms\ItemList\Item;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;

/**
 * E736 5.14 (round 88): mouse routing on ItemList — click selects the row
 * under the pointer, the wheel moves the selection, everything else stays
 * dropped. Coordinates are list-relative, 1-based (the caller subtracts the
 * pane origin before dispatching, mirroring charmbracelet/bubbles list.go).
 */
final class ItemListMouseTest extends TestCase
{
    /** @return list<StringItem> */
    private function items(): array
    {
        return [
            new StringItem('apple'),
            new StringItem('banana'),
            new StringItem('cherry'),
            new StringItem('date'),
        ];
    }

    private function focused(): ItemList
    {
        $l = ItemList::new($this->items(), 20, 5);
        [$l, ] = $l->focus();
        return $l;
    }

    private function click(int $y, MouseButton $button = MouseButton::Left): MouseMsg
    {
        return new MouseMsg(1, $y, $button, MouseAction::Press);
    }

    /** Item with a non-empty description, to pin the two-rows-per-item walk. */
    private function describedItem(string $title, string $description): Item
    {
        return new class ($title, $description) implements Item {
            public function __construct(
                private readonly string $itemTitle,
                private readonly string $itemDescription,
            ) {}

            public function title(): string { return $this->itemTitle; }
            public function description(): string { return $this->itemDescription; }
            public function filterValue(): string { return $this->itemTitle; }
        };
    }

    public function testClickSelectsRowUnderPointer(): void
    {
        $l = $this->focused()->goToEnd();
        $this->assertSame(3, $l->index());
        [$l2, $cmd] = $l->update($this->click(1));
        $this->assertNull($cmd);
        $this->assertSame(0, $l2->index());
        $this->assertSame('apple', $l2->selectedItem()?->title());
    }

    public function testClickBelowStatusAndAboveBodyIsIgnored(): void
    {
        $l = $this->focused();
        // y=5 is the status bar (4 items render at y=1..4 below a title-less
        // list); y=0 is not producible by a real terminal but must be a
        // safe no-op for synthesized messages.
        [$l5, ] = $l->update($this->click(5));
        $this->assertSame($l, $l5);
        [$l0, ] = $l->update($this->click(0));
        $this->assertSame($l, $l0);
        [$l99, ] = $l->update($this->click(99));
        $this->assertSame($l, $l99);
    }

    public function testClickHonoursScrollOffset(): void
    {
        // height 2 with the cursor at the end: the window shows items 2..3,
        // so the first visible line maps to absolute index 2, not 0.
        $l = ItemList::new($this->items(), 20, 2);
        [$l, ] = $l->focus();
        $l = $l->goToEnd();
        [$l2, ] = $l->update($this->click(1));
        $this->assertSame(2, $l2->index());
        [$l3, ] = $l->update($this->click(2));
        $this->assertSame(3, $l3->index());
    }

    public function testClickUnderTitleAndFilterRowsShiftsToBody(): void
    {
        $l = ItemList::new($this->items(), 20, 5)->withTitle('Picks');
        [$l, ] = $l->focus();
        // y=1 is the title row: no item there, so the model is returned
        // unchanged rather than silently selecting row 0.
        [$same, ] = $l->update($this->click(1));
        $this->assertSame($l, $same);
        [$hit, ] = $l->update($this->click(3));
        $this->assertSame(1, $hit->index()); // y=2 'apple', y=3 'banana'
    }

    public function testClickOnDescriptionRowSelectsOwningItem(): void
    {
        $items = [
            $this->describedItem('one', 'first description'),
            $this->describedItem('two', 'second description'),
        ];
        $l = ItemList::new($items, 20, 6);
        [$l, ] = $l->focus();
        // layout: y1 one, y2 its description, y3 two, y4 its description
        [$y2, ] = $l->update($this->click(2));
        $this->assertSame(0, $y2->index());
        [$y3, ] = $l->update($this->click(3));
        $this->assertSame(1, $y3->index());
        [$y4, ] = $l->update($this->click(4));
        $this->assertSame(1, $y4->index());
    }

    public function testWheelMovesSelectionAndPansViewport(): void
    {
        $l = $this->focused();
        [$l2, $cmd] = $l->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertNull($cmd);
        $this->assertSame(1, $l2->index());
        [$l3, ] = $l2->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press));
        $this->assertSame(0, $l3->index());
        // wheel-up at the top is a clamp, not a crash or a wrap
        [$l4, ] = $l3->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press));
        $this->assertSame(0, $l4->index());
        $this->assertSame(0, $l4->offset);
    }

    public function testRightButtonAndNonPressActionsIgnored(): void
    {
        $l = $this->focused();
        [$r, ] = $l->update($this->click(2, MouseButton::Right));
        $this->assertSame($l, $r);
        [$rel, ] = $l->update(new MouseReleaseMsg(1, 2, MouseButton::Left, MouseAction::Release));
        $this->assertSame($l, $rel);
        [$mot, ] = $l->update(new MouseMotionMsg(1, 2, MouseButton::Left, MouseAction::Motion));
        $this->assertSame($l, $mot);
        // A bare (non-subclassed) press with the Middle button is dropped too.
        [$mid, ] = $l->update($this->click(2, MouseButton::Middle));
        $this->assertSame($l, $mid);
    }

    public function testMouseWhileUnfocusedIsIgnored(): void
    {
        $l = ItemList::new($this->items(), 20, 5);
        [$l2, ] = $l->update($this->click(2));
        $this->assertSame($l, $l2);
        [$w, ] = $l->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertSame($l, $w);
    }

    public function testMouseWhileFilteringIsIgnored(): void
    {
        $l = $this->focused();
        [$f, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertTrue($f->isFiltering());
        [$f2, ] = $f->update($this->click(3));
        $this->assertSame($f, $f2);
        [$w, ] = $f->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        $this->assertSame($f, $w);
    }

    public function testClickOnEmptyListIsIgnored(): void
    {
        $l = ItemList::new([], 20, 5);
        [$l, ] = $l->focus();
        [$l2, ] = $l->update($this->click(1));
        $this->assertSame($l, $l2);
    }

    public function testClickUnderActiveFilterMapsFilteredRows(): void
    {
        $l = $this->focused()->withKeepFilter(true);
        // Drive the filter through the keyboard seam, then exit filter mode
        // with Enter + keepFilter: the results stay and the click arm is
        // live against the FILTERED view (banana/date remain, cherry drops).
        [$f, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(['apple', 'banana', 'date'], array_map(
            static fn(Item $i) => $i->title(),
            $f->visibleItems(),
        ));
        [$f, ] = $f->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($f->isFiltering());
        $this->assertSame('a', $f->filterValue());
        // layout: y1 = '/a' filter row, y2 apple, y3 banana, y4 date.
        [$onFilterRow, ] = $f->update($this->click(1));
        $this->assertSame($f, $onFilterRow);
        [$hit, ] = $f->update($this->click(3));
        $this->assertSame(1, $hit->index());
        $this->assertSame('banana', $hit->selectedItem()?->title());
    }
}
