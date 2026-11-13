<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\ItemList;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Forms\ItemList\Item;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;

/**
 * SF-27 (sugar-forms-031): `height` counts ITEMS while showDescription
 * doubles painted lines — a height-8 list emits up to 16 rows, so hosts
 * laying out panes by the declared budget were overrunning it. The cure is
 * additive: withVisibleLines() opts the page into a strict LINES budget,
 * the pan math follows whole item bodies in BOTH directions, and the mouse
 * walk reads the SAME window (clicks stay consistent under budgeting).
 * The default path (budget 0) must stay byte-for-byte the item-count shape.
 */
final class ItemListHeightTest extends TestCase
{
    /** @param list<array{string,string}> $pairs title/description pairs */
    private function described(array $pairs, int $height = 10): ItemList
    {
        $items = [];
        foreach ($pairs as [$title, $description]) {
            $items[] = new class ($title, $description) implements Item {
                public function __construct(
                    private readonly string $itemTitle,
                    private readonly string $itemDescription,
                ) {}

                public function title(): string { return $this->itemTitle; }
                public function description(): string { return $this->itemDescription; }
                public function filterValue(): string { return $this->itemTitle; }
            };
        }

        $list = ItemList::new($items, 40, $height)
            ->withTitle('')
            ->withShowStatusBar(false)
            ->withShowFilter(false);
        [$focused, ] = $list->focus();
        assert($focused instanceof ItemList);
        return $focused;
    }

    /** @return list<string> */
    private function lines(ItemList $list): array
    {
        return explode("\n", $list->view());
    }

    private function wheelDown(ItemList $list): ItemList
    {
        [$next, ] = $list->update(new MouseWheelMsg(1, 1, MouseButton::WheelDown, MouseAction::Press));
        assert($next instanceof ItemList);
        return $next;
    }

    public function testDefaultPathPaintsItemSemanticsUnchanged(): void
    {
        $list = $this->described(
            array_map(static fn(int $i): array => ["item{$i}", "desc{$i}"], range(0, 9)),
            8
        );

        $this->assertCount(16, $this->lines($list), 'height-8 items with descriptions double to 16 painted rows — the documented item semantics');
        // Budget explicitly 0 == historical bytes.
        $same = $list->withVisibleLines(0);
        $this->assertSame($list->view(), $same->view(), 'visibleLines(0) must repaint zero bytes off the default shape');
    }

    public function testBudgetFillsByLinesNotItems(): void
    {
        $list = $this->described(
            array_map(static fn(int $i): array => ["item{$i}", "desc{$i}"], range(0, 9)),
            10
        )->withVisibleLines(10);

        // 10 lines / 2 per described item == 5 items — NOT the 10 the old
        // item-count window painted.
        $this->assertCount(10, $this->lines($list));
        $this->assertSame(5, substr_count($list->view(), 'desc'), 'exactly five bodies inside the line budget');
    }

    public function testPanPushesForwardAcrossTwoLineBodies(): void
    {
        $list = $this->described(
            array_map(static fn(int $i): array => ["item{$i}", "desc{$i}"], range(0, 7)),
            4
        )->withVisibleLines(4);

        $list = $this->wheelDown($list); // cursor 1: body lines 3..4 straddle the budget
        $list = $this->wheelDown($list); // cursor 2: must fit — top slides forward
        $this->assertSame(2, $list->index());
        $this->assertStringContainsString('item2', $list->view(), 'cursor body must be inside the window');
        $this->assertLessThanOrEqual(4, count($this->lines($list)));
    }

    public function testPanPullsBackAcrossTwoLineBodies(): void
    {
        $list = $this->described(
            array_map(static fn(int $i): array => ["item{$i}", "desc{$i}"], range(0, 7)),
            4
        )->withVisibleLines(4);

        for ($i = 0; $i < 6; $i++) {
            $list = $this->wheelDown($list);
        }
        $this->assertSame(6, $list->index());

        [$up, ] = $list->update(new MouseWheelMsg(1, 1, MouseButton::WheelUp, MouseAction::Press));
        assert($up instanceof ItemList);
        $this->assertSame(5, $up->index());
        $this->assertStringContainsString('item5', $up->view());
        $this->assertStringNotContainsString('item0', $up->view(), 'window followed the cursor; stale top gone');
    }

    public function testMouseClickStaysConsistentUnderBudget(): void
    {
        $list = $this->described([['one', 'first body'], ['two', 'second body'], ['three', 'third body']], 10)
            ->withVisibleLines(4); // window == items 0..1

        [$picked, ] = $list->update(new MouseMsg(1, 4, MouseButton::Left, MouseAction::Press)); // line 4 == item two's description row
        assert($picked instanceof ItemList);
        $this->assertSame(1, $picked->index(), 'clicking the description line of the second budgeted item selects it');

        [$outside, ] = $list->update(new MouseMsg(1, 6, MouseButton::Left, MouseAction::Press)); // line 6 is past the window
        assert($outside instanceof ItemList);
        $this->assertSame(0, $outside->index(), 'clicks outside the painted body never move the cursor');
    }

    public function testRenderedLineCountIsHonest(): void
    {
        $plain = $this->described(array_map(static fn(int $i): array => ["item{$i}", ''], range(0, 9)), 8);
        $this->assertSame(8, $plain->renderedLineCount(), 'undecorated bodies: 8 items == 8 lines');

        $described = $this->described(array_map(static fn(int $i): array => ["item{$i}", "d{$i}"], range(0, 9)), 8);
        $this->assertSame(16, $described->renderedLineCount(), 'described bodies: the SAME height paints 16 rows — the defect 031');

        $budgeted = $described->withVisibleLines(6);
        $this->assertSame(6, $budgeted->renderedLineCount(), 'with the budget on, the honest count equals it');
    }

    public function testBudgetSmallerThanOneBodyStillPaintsThatBody(): void
    {
        $list = $this->described([['one', 'a wide second line'], ['two', 'x']], 10)->withVisibleLines(1);
        $lines = $this->lines($list);
        $this->assertGreaterThanOrEqual(2, count($lines), 'minimum-one item (never an empty page) even when its body alone overruns the budget');
        $this->assertStringContainsString('one', $list->view());
    }

    public function testNegativeBudgetThrowsAtTheConfigDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->described([['one', '']], 10)->withVisibleLines(-1);
    }

    public function testSelectPassThroughForwardsTheBudget(): void
    {
        $field = \SugarCraft\Forms\Field\Select::new('pick', ['a' => 'A', 'b' => 'B'])->withVisibleLines(3);
        $this->assertSame(3, $field->list->visibleLines, 'field-level plumbing reaches the list');
        $this->assertSame(0, $field->withVisibleLines(0)->list->visibleLines, 'and 0 returns the list to item semantics');
    }
}
