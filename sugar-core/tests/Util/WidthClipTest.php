<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

final class WidthClipTest extends TestCase
{
    public function testFittingTextIsUntouched(): void
    {
        self::assertSame('hello', Width::clipBytes('hello', 5));
        self::assertSame('hello', Width::clipBytes('hello', 99));
        self::assertSame('', Width::clipBytes('', 3));
    }

    public function testMarkerIsInsideTheByteBudget(): void
    {
        $out = Width::clipBytes('abcdefghij', 6);
        self::assertSame('abc…', $out);
        self::assertSame(6, \strlen($out));
        self::assertSame('abcde~', Width::clipBytes('abcdefghij', 6, '~'));
        self::assertSame('abcdef', Width::clipBytes('abcdefghij', 6, ''));
    }

    public function testNeverSplitsAMultiByteSequence(): void
    {
        // 'é' is 2 bytes, '日' 3, '😀' 4.
        self::assertSame('aé…', Width::clipBytes('aéééé', 6));      // 1+2 + 3 marker
        self::assertSame('a…', Width::clipBytes('aéééé', 5));       // cut would land mid-é
        self::assertSame('日…', Width::clipBytes('日日日日', 7));    // 3 + 3(marker) = 6 <= 7
        self::assertSame('…', Width::clipBytes('😀😀😀', 5));        // 4-byte char does not fit beside marker
    }

    /** @return iterable<string,array{string,int}> */
    public static function boundCases(): iterable
    {
        foreach (['plain ascii text here', 'éàüñ日本語😀 mixed content', "\xE6\x97", str_repeat('😀', 9)] as $i => $text) {
            foreach ([1, 2, 3, 4, 5, 7, 11, 16] as $max) {
                yield "{$i}/{$max}" => [$text, $max];
            }
        }
    }

    #[DataProvider('boundCases')]
    public function testBoundHoldsAndValidUtf8StaysValid(string $text, int $max): void
    {
        $out = Width::clipBytes($text, $max);
        self::assertLessThanOrEqual($max, \strlen($out));
        if (mb_check_encoding($text, 'UTF-8')) {
            self::assertTrue(mb_check_encoding($out, 'UTF-8'), bin2hex($out));
        }
    }

    public function testMarkerWiderThanBudgetIsItselfClipped(): void
    {
        self::assertSame('', Width::clipBytes('abcdef', 2, '…'));      // 3-byte marker, 2 bytes: no whole char fits
        self::assertSame('..', Width::clipBytes('abcdef', 2, '....'));
        self::assertSame('…', Width::clipBytes('abcdef', 3, '…'));
    }

    public function testNonPositiveBudgetIsEmpty(): void
    {
        self::assertSame('', Width::clipBytes('abc', 0));
        self::assertSame('', Width::clipBytes('abc', -4));
        self::assertSame('', Width::clipCells('abc', 0));
    }

    public function testClipCellsMarkerInsideTheCellBudget(): void
    {
        self::assertSame('hello', Width::clipCells('hello', 5));
        self::assertSame('hel…', Width::clipCells('helloworld', 4));
        self::assertSame(4, Width::string(Width::clipCells('helloworld', 4)));
        self::assertSame('he~', Width::clipCells('helloworld', 3, '~'));
    }

    public function testClipCellsCountsWideCellsAndNeverSplitsOne(): void
    {
        // each 日 is 2 cells; budget 5 minus 1 for the marker leaves 4 = two glyphs.
        self::assertSame('日日…', Width::clipCells('日日日日', 5));
        // budget 4 leaves 3 cells: one glyph fits, the second would overshoot.
        self::assertSame('日…', Width::clipCells('日日日日', 4));
    }

    public function testClipCellsMarkerWiderThanBudgetIsTruncated(): void
    {
        self::assertSame('..', Width::clipCells('abcdef', 2, '....'));
    }

    public function testClipCellsKeepsAnsiWhenTheTextFits(): void
    {
        $styled = "\e[31mred\e[0m";
        self::assertSame($styled, Width::clipCells($styled, 3));
    }
}
