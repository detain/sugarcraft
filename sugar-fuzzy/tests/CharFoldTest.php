<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SugarCraft\Fuzzy\Matcher\CharFold;

#[CoversClass(CharFold::class)]
final class CharFoldTest extends TestCase
{
    #[Test]
    public function testEmptyStringFoldsToEmptyList(): void
    {
        $this->assertSame([], CharFold::foldSplit(''));
    }

    #[Test]
    public function testAsciiFastPathEqualsPerCodePointFold(): void
    {
        $ascii = '';
        for ($b = 0; $b < 128; $b++) {
            $ascii .= chr($b);
        }

        $expected = array_map(CharFold::fold(...), mb_str_split($ascii, 1, 'UTF-8'));
        $this->assertSame($expected, CharFold::foldSplit($ascii));
        $this->assertSame(['a', 'b', 'c', '_', '1'], CharFold::foldSplit('AbC_1'));
    }

    #[Test]
    public function testNonAsciiStaysOneElementPerCodePoint(): void
    {
        $folded = CharFold::foldSplit('AİB中');

        $this->assertCount(4, $folded);
        $this->assertSame(['a', mb_strtolower('İ', 'UTF-8'), 'b', '中'], $folded);
    }

    #[Test]
    public function testFoldSplitFoldsMixedCaseNonAsciiInPlace(): void
    {
        // Finding sugar-fuzzy-040 moved the non-ASCII branch to an in-place
        // fold; these pins hold the exact folded list and the list contract
        // (keys stay 0..n-1 — a stray unset/append in the loop would shift
        // every later index).
        $folded = CharFold::foldSplit('ÀÉab');

        $this->assertSame(['à', 'é', 'a', 'b'], $folded);
        $this->assertTrue(array_is_list($folded));
        // Pure function: a second call is not polluted by the first's in-place
        // mutation (the input string itself is never rewritten).
        $this->assertSame($folded, CharFold::foldSplit('ÀÉab'));
    }
}
