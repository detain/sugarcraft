<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Sprinkles\Listing\Enumerator;
use CandyCore\Sprinkles\Listing\ItemList;
use PHPUnit\Framework\TestCase;

final class ItemListTest extends TestCase
{
    public function testEmptyList(): void
    {
        $this->assertSame('', ItemList::new()->render());
    }

    public function testDashEnumerator(): void
    {
        $out = ItemList::new()->item('Apple')->item('Banana')->render();
        $this->assertSame("- Apple\n- Banana", $out);
    }

    public function testBulletEnumerator(): void
    {
        $out = ItemList::new()
            ->item('Apple')->item('Banana')
            ->enumerator(Enumerator::bullet())
            ->render();
        $this->assertSame("• Apple\n• Banana", $out);
    }

    public function testArabicEnumerator(): void
    {
        $out = ItemList::new()
            ->items(['Apple', 'Banana', 'Cherry'])
            ->enumerator(Enumerator::arabic())
            ->render();
        $this->assertSame("1. Apple\n2. Banana\n3. Cherry", $out);
    }

    public function testArabicAlignsMarkers(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = "item$i";
        }
        $out = ItemList::new()
            ->items($items)
            ->enumerator(Enumerator::arabic())
            ->render();
        $lines = explode("\n", $out);
        // " 1." vs "10." — both 3 chars, then space, then text. So all items
        // align to column 4.
        $this->assertSame('1.  item0',  $lines[0]);
        $this->assertSame('10. item9',  $lines[9]);
    }

    public function testAlphabetEnumerator(): void
    {
        $out = ItemList::new()
            ->items(['x', 'y', 'z'])
            ->enumerator(Enumerator::alphabet())
            ->render();
        $this->assertSame("A. x\nB. y\nC. z", $out);
    }

    public function testAlphabetWrapsAfter26(): void
    {
        $items = array_fill(0, 27, 'x');
        $out = ItemList::new()
            ->items($items)
            ->enumerator(Enumerator::alphabet())
            ->render();
        $lines = explode("\n", $out);
        $this->assertStringStartsWith('A. ',  $lines[0]);
        $this->assertStringStartsWith('Z. ',  $lines[25]);
        $this->assertStringStartsWith('AA. ', $lines[26]);
    }

    public function testNoneEnumerator(): void
    {
        $out = ItemList::new()
            ->items(['Apple', 'Banana'])
            ->enumerator(Enumerator::none())
            ->render();
        $this->assertSame("Apple\nBanana", $out);
    }

    public function testMultiLineItemIndentsContinuation(): void
    {
        $out = ItemList::new()
            ->item("Apple\nfresh")
            ->item('Banana')
            ->render();
        $this->assertSame("- Apple\n  fresh\n- Banana", $out);
    }
}
