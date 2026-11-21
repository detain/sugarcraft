<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

final class WidthTruncateEllipsisTest extends TestCase
{
    public function testTextThatFitsIsReturnedUntouched(): void
    {
        $this->assertSame('abc', Width::truncateEllipsis('abc', 5));
        $this->assertSame('abcde', Width::truncateEllipsis('abcde', 5));
    }

    public function testFitsKeepsAnsiBecauseNothingIsClipped(): void
    {
        $styled = "\x1b[31mred\x1b[0m";
        $this->assertSame($styled, Width::truncateEllipsis($styled, 3));
    }

    public function testClipEndsWithEllipsisInsideTheBudget(): void
    {
        $out = Width::truncateEllipsis('hello world', 8);
        $this->assertSame('hello...', $out);
        $this->assertLessThanOrEqual(8, Width::string($out));
    }

    public function testCustomEllipsis(): void
    {
        $this->assertSame('hello…', Width::truncateEllipsis('hello world', 6, '…'));
    }

    public function testClipDropsAnsi(): void
    {
        $this->assertSame('red b...', Width::truncateEllipsis("\x1b[31mred\x1b[0m bright", 8));
    }

    public function testEllipsisThatDoesNotFitFallsBackToPlainHeadCut(): void
    {
        // Three cells of '...' in a two-cell budget: no room for it, so cut the text.
        $this->assertSame('he', Width::truncateEllipsis('hello', 2));
        // A four-cell ellipsis in a three-cell budget does not fit: plain head cut.
        $this->assertSame('hel', Width::truncateEllipsis('hello', 3, '....'));
    }

    /**
     * An ellipsis that exactly fills the budget fits, so the marker stays. The
     * docblock promises the head cut only when the ellipsis alone does not fit.
     */
    public function testEllipsisThatExactlyFillsTheBudgetKeepsTheMarker(): void
    {
        $this->assertSame('...', Width::truncateEllipsis('hello', 3, '...'));
        $this->assertSame('h...', Width::truncateEllipsis('hello', 4, '...'));
        $this->assertSame('a...', Width::truncateEllipsis('abcdefgh', 4, '...'));
        $this->assertSame('...', Width::truncateEllipsis('hello', 3));
    }

    public function testNonPositiveBudgetIsEmpty(): void
    {
        $this->assertSame('', Width::truncateEllipsis('hello', 0));
        $this->assertSame('', Width::truncateEllipsis('hello', -4));
    }

    public function testWideGraphemesAreNeverSplit(): void
    {
        // 中文字符 is 8 cells; the budget leaves 2 cells for text beside the 3-cell ellipsis.
        $out = Width::truncateEllipsis('中文字符', 5);
        $this->assertSame("中...", $out);
        $this->assertLessThanOrEqual(5, Width::string($out));
    }
}
