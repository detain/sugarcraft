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
}
