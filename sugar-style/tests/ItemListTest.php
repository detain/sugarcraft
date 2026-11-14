<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Listing\Enumerator;
use SugarCraft\Style\Listing\ItemList;
use SugarCraft\Style\Style;
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

    public function testRomanEnumerator(): void
    {
        $out = ItemList::new()
            ->items(['x', 'y', 'z', 'a', 'b'])
            ->enumerator(Enumerator::roman())
            ->render();
        $lines = explode("\n", $out);
        $this->assertStringStartsWith('i.   ',   $lines[0]);
        $this->assertStringStartsWith('ii.  ',   $lines[1]);
        $this->assertStringStartsWith('iii. ',   $lines[2]);
        $this->assertStringStartsWith('iv.  ',   $lines[3]);
        $this->assertStringStartsWith('v.   ',   $lines[4]);
    }

    public function testRomanUpperEnumerator(): void
    {
        $out = ItemList::new()
            ->items(['a', 'b', 'c'])
            ->enumerator(Enumerator::romanUpper())
            ->render();
        $lines = explode("\n", $out);
        $this->assertStringStartsWith('I.',   $lines[0]);
        $this->assertStringStartsWith('II.',  $lines[1]);
        $this->assertStringStartsWith('III.', $lines[2]);
    }

    public function testItemStyleAppliedToEachItem(): void
    {
        $out = ItemList::new()
            ->items(['x', 'y'])
            ->itemStyle(Style::new()->bold()->colorProfile(ColorProfile::Ansi))
            ->render();
        // Each rendered item should contain the bold SGR.
        $this->assertSame(2, substr_count($out, "\x1b[1m"));
    }

    public function testItemStyleFuncOverrides(): void
    {
        $out = ItemList::new()
            ->items(['a', 'b'])
            ->itemStyleFunc(static fn(int $i): Style =>
                $i === 0
                    ? Style::new()->bold()->colorProfile(ColorProfile::Ansi)
                    : Style::new()->italic()->colorProfile(ColorProfile::Ansi)
            )
            ->render();
        $this->assertStringContainsString("\x1b[1m", $out);
        $this->assertStringContainsString("\x1b[3m", $out);
    }

    public function testEnumeratorStyleAppliedToMarkers(): void
    {
        $out = ItemList::new()
            ->items(['x', 'y'])
            ->enumerator(Enumerator::arabic())
            ->enumeratorStyle(Style::new()->bold()->colorProfile(ColorProfile::Ansi))
            ->render();
        // Both '1.' and '2.' should be wrapped in bold.
        $this->assertSame(2, substr_count($out, "\x1b[1m"));
    }

    public function testNestedSublist(): void
    {
        $sub = ItemList::new()
            ->items(['inner1', 'inner2']);
        $out = ItemList::new()
            ->item('outer')
            ->item($sub)
            ->item('after')
            ->render();
        // Sublist body is indented by the outer marker width + the
        // configured indent (default '  ').
        $this->assertStringContainsString('- outer', $out);
        $this->assertStringContainsString('inner1', $out);
        $this->assertStringContainsString('inner2', $out);
        $this->assertStringContainsString('- after', $out);
    }

    public function testVariadicNewAcceptsItemsInOneCall(): void
    {
        $a = ItemList::new('apple', 'pear', 'quince');
        $b = ItemList::new()->items(['apple', 'pear', 'quince']);
        $this->assertSame($a->render(), $b->render());
    }

    public function testVariadicNewWithNoArgsIsEmpty(): void
    {
        $this->assertSame('', ItemList::new()->render());
    }

    public function testVariadicNewAcceptsNestedSublists(): void
    {
        $sub = ItemList::new('inner-1', 'inner-2');
        $out = ItemList::new('outer', $sub, 'after')->render();
        $this->assertStringContainsString('outer',   $out);
        $this->assertStringContainsString('inner-1', $out);
        $this->assertStringContainsString('inner-2', $out);
        $this->assertStringContainsString('after',   $out);
    }

    public function testTreeEnumeratorBranchGlyphs(): void
    {
        $out = ItemList::new('a', 'b', 'c')
            ->enumerator(Enumerator::tree())
            ->render();
        // Intermediate items use ├─; the last uses └─ — byte-exact rows,
        // not containsString existence (finding 041: alias factories
        // carry parity pins, ST-26).
        $this->assertSame("├─ a\n├─ b\n└─ c", $out);
    }

    public function testAsteriskEnumeratorByteExact(): void
    {
        $out = ItemList::new()
            ->items(['Apple', 'Banana'])
            ->enumerator(Enumerator::asterisk())
            ->render();
        $this->assertSame("* Apple\n* Banana", $out);
    }

    public function testDecimalEnumeratorByteExact(): void
    {
        $out = ItemList::new()
            ->items(['a', 'b'])
            ->enumerator(Enumerator::decimal())
            ->render();
        $this->assertSame("1. a\n2. b", $out);
    }

    public function testDecimalIsAnAliasForArabic(): void
    {
        // lipgloss parity alias (Enumerator::decimal() returns arabic());
        // the alias must render identically across the marker-width
        // boundary, or a caller swapping the name silently changes bytes.
        $items = array_fill(0, 12, 'x');
        $arabic = ItemList::new()->items($items)->enumerator(Enumerator::arabic())->render();
        $decimal = ItemList::new()->items($items)->enumerator(Enumerator::decimal())->render();
        $this->assertSame($arabic, $decimal);
    }

    public function testCustomIndentAppliesToNestedSublists(): void
    {
        // indent() is the sublist-body prefix (outer marker indent is
        // separate); a custom multi-byte rune must ride every sub line.
        $sub = ItemList::new()->items(['inner1', 'inner2']);
        $out = ItemList::new()
            ->item('outer')
            ->item($sub)
            ->item('after')
            ->indent('··')
            ->render();
        $this->assertSame(
            "- outer\n  ··- inner1\n  ··- inner2\n- after",
            $out,
        );
    }

    public function testDefaultIndentIsTwoSpaces(): void
    {
        // Pair pin for the custom-indent leg: the default '  ' plus the
        // 2-space marker indent yields four leading spaces on sub lines.
        $sub = ItemList::new()->items(['inner']);
        $out = ItemList::new()->item('outer')->item($sub)->render();
        $this->assertSame("- outer\n    - inner", $out);
    }
}
