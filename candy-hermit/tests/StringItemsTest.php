<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\{Hermit, FilteredItem, Item};
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the string-items code path.
 *
 * Covers: raw strings passed to ::new(), coercion to FilteredItem,
 * filter narrowing, selected() returning a valid Item, withItems()
 * re-coercion, custom formatter receiving string value, and count methods.
 */
final class StringItemsTest extends TestCase
{
    public function testShowTypeFilterNarrowsStringItems(): void
    {
        // 'ba' matches only 'banana' (apple/cherry have no 'ba' substring).
        $h = Hermit::new(['apple', 'banana', 'cherry'])->show();

        $h = $h->type('ba');

        $this->assertSame(1, $h->itemCount());
        $this->assertSame('banana', $h->selected()->value());
        $this->assertSame(2, $h->selected()->number());
    }

    public function testViewRenderContainsPromptAndMatchedItem(): void
    {
        $h = Hermit::new(['apple', 'banana', 'cherry'])->show()->type('ba');

        // Use a tall-enough background so items render at y-offset 0.
        $bg = str_repeat(str_repeat(' ', 80) . "\n", 20);
        $result = $h->View($bg);

        $this->assertStringContainsString('> ', $result);
        $this->assertStringContainsString('ba', $result);
        $this->assertStringContainsString('banana', $result);
    }

    public function testSelectedReturnsItemNotNullWhenListNonEmpty(): void
    {
        $h = Hermit::new(['apple', 'banana', 'cherry'])->show()->type('a');

        $selected = $h->selected();

        $this->assertNotNull($selected);
        $this->assertInstanceOf(Item::class, $selected);
    }

    public function testSelectedValueIsOriginalString(): void
    {
        $h = Hermit::new(['apple', 'banana', 'cherry'])->show()->type('na');

        $this->assertSame('banana', $h->selected()->value());
    }

    public function testWithItemsReCoercesStringsToFilteredItem(): void
    {
        $h = Hermit::new(['apple', 'banana'])->show()->type('ap');

        $h2 = $h->withItems(['x', 'y', 'z']);

        // withItems resets cursor to 0; filterText 'ap' stays but no item matches.
        $this->assertSame(3, $h2->allCount());
        // filtered list is now empty (x/y/z don't contain 'ap'), so selected is null
        $this->assertNull($h2->selected());
    }

    public function testCustomItemFormatterReceivesStringValue(): void
    {
        // Use a custom formatter that produces a distinctive, verifiable marker.
        $h = Hermit::new(['apple', 'banana', 'cherry'])
            ->show()
            ->setItemFormatter(
                static fn(string $value, bool $selected): string =>
                    'ITEM:' . $value . ':' . ($selected ? 'SEL' : 'NOSEL')
            )
            ->type('a'); // matches apple and banana

        $bg = str_repeat(str_repeat(' ', 80) . "\n", 20);
        $result = $h->View($bg);

        // The formatter output appears in the View, proving it received string values.
        $this->assertStringContainsString('ITEM:apple:SEL', $result);  // apple at cursor 0
        $this->assertStringContainsString('ITEM:banana:NOSEL', $result); // banana not selected
    }

    public function testAllCountAndItemCountWithStringItems(): void
    {
        $h = Hermit::new(['apple', 'banana', 'cherry'])->show();

        $this->assertSame(3, $h->allCount());
        $this->assertSame(3, $h->itemCount());

        $h = $h->type('a');

        $this->assertSame(3, $h->allCount());
        // 'a' matches apple (pos 0, 0*2<5✓) and banana (pos 0, 0*2<6✓); cherry fails anchor check
        $this->assertSame(2, $h->itemCount());
    }

    public function testItemNumberIsOrdinalAfterCoercion(): void
    {
        $h = Hermit::new(['first', 'second', 'third'])->show();

        $items = $h->items();

        $this->assertSame(1, $items[0]->number());
        $this->assertSame(2, $items[1]->number());
        $this->assertSame(3, $items[2]->number());
    }

    public function testCustomFormatterReceivesSelectedFlag(): void
    {
        $h = Hermit::new(['apple', 'banana', 'cherry'])
            ->show()
            ->setItemFormatter(
                static fn(string $value, bool $selected): string =>
                    ($selected ? '[SELECTED] ' : '[        ] ') . $value
            )
            ->type('ba'); // selects 'banana'

        $bg = str_repeat(str_repeat(' ', 80) . "\n", 20);
        $result = $h->View($bg);

        // banana is the selected item; apple and cherry are filtered out.
        // Only banana is visible in filtered items.
        $this->assertStringContainsString('[SELECTED] banana', $result);
    }

    public function testDefaultFormatterShowsItemOrdinal(): void
    {
        // FilteredItem(3, 'cherry') should render as "3. cherry" with the default formatter.
        $h = Hermit::new([new FilteredItem(3, 'cherry')])->show();

        $bg = str_repeat(str_repeat(' ', 80) . "
", 20);
        $result = $h->View($bg);

        // The default formatter shows "  3. cherry" (2-space indent + ordinal + dot + space + value).
        $this->assertStringContainsString('3. cherry', $result);
    }

}
