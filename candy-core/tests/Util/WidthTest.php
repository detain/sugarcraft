<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class WidthTest extends TestCase
{
    public function testAsciiWidth(): void
    {
        $this->assertSame(11, Width::string('hello world'));
    }

    public function testStripsAnsiBeforeMeasuring(): void
    {
        $this->assertSame(5, Width::string("\x1b[31mhello\x1b[0m"));
    }

    public function testEmpty(): void
    {
        $this->assertSame(0, Width::string(''));
    }

    public function testCjkWideEachCounts2(): void
    {
        $this->assertSame(4, Width::string('日本'));
    }

    public function testEmojiCounts2(): void
    {
        $this->assertSame(2, Width::string('🎉'));
    }

    public function testOfHandlesZwjFamilyEmoji(): void
    {
        $this->assertSame(2, Width::of("👨‍👩‍👧‍👦"));
    }

    public function testZeroWidthJoinerInvisible(): void
    {
        $this->assertSame(0, Width::string("\u{200b}"));
    }

    public function testCombiningMarkInvisible(): void
    {
        $this->assertSame(1, Width::string("e\u{0301}"));
    }

    public function testTruncate(): void
    {
        $this->assertSame('hello', Width::truncate('hello world', 5));
    }

    public function testTruncateRespectsWideChars(): void
    {
        $this->assertSame('日', Width::truncate('日本', 3));
    }

    public function testTruncateZero(): void
    {
        $this->assertSame('', Width::truncate('hello', 0));
    }

    public function testTruncateAnsiPreservesEscapes(): void
    {
        $out = Width::truncateAnsi("\x1b[31mhello\x1b[0m", 3);
        $this->assertSame("\x1b[31mhel\x1b[0m", $out);
    }

    public function testTruncateAnsiRespectsWideChars(): void
    {
        $out = Width::truncateAnsi("\x1b[31m日本\x1b[0m", 3);
        // '日' uses 2 cells; '本' would need 4 → drop, keep trailing ANSI.
        $this->assertSame("\x1b[31m日\x1b[0m", $out);
    }

    public function testTruncateAnsiZero(): void
    {
        $this->assertSame('', Width::truncateAnsi("\x1b[31mhi\x1b[0m", 0));
    }

    public function testPadRight(): void
    {
        $this->assertSame('hi   ', Width::padRight('hi', 5));
        $this->assertSame('hello', Width::padRight('hello', 5));
        $this->assertSame('hello', Width::padRight('hello', 3));
        $this->assertSame('hi***', Width::padRight('hi', 5, '*'));
    }

    public function testPadLeft(): void
    {
        $this->assertSame('   hi', Width::padLeft('hi', 5));
        $this->assertSame('00042', Width::padLeft('42', 5, '0'));
    }

    public function testPadCenter(): void
    {
        $this->assertSame(' hi  ', Width::padCenter('hi', 5));
        $this->assertSame('  hi  ', Width::padCenter('hi', 6));
    }

    public function testPadIgnoresAnsi(): void
    {
        $padded = Width::padRight("\x1b[31mhi\x1b[0m", 5);
        $this->assertSame("\x1b[31mhi\x1b[0m   ", $padded);
        $this->assertSame(5, Width::string($padded));
    }

    public function testWrapShortText(): void
    {
        $this->assertSame('hello', Width::wrap('hello', 10));
    }

    public function testWrapBreaksOnSpaces(): void
    {
        $this->assertSame("hello\nworld", Width::wrap('hello world', 5));
    }

    public function testWrapHonorsExistingNewlines(): void
    {
        $this->assertSame("a\nb", Width::wrap("a\nb", 80));
    }

    public function testWrapBreaksLongWord(): void
    {
        $this->assertSame("abcd\nefgh\ni", Width::wrap('abcdefghi', 4));
    }

    public function testWrapZeroOrNegativeReturnsInput(): void
    {
        $this->assertSame('hello world', Width::wrap('hello world', 0));
        $this->assertSame('hello world', Width::wrap('hello world', -1));
    }

    public function testWrapMultipleWordsAcrossLines(): void
    {
        $out = Width::wrap('the quick brown fox jumps over the lazy dog', 12);
        $this->assertSame("the quick\nbrown fox\njumps over\nthe lazy dog", $out);
    }

    public function testWrapAnsiPreservesStyling(): void
    {
        $out = Width::wrapAnsi("\x1b[31mhello\x1b[0m world", 5);
        $this->assertSame("\x1b[31mhello\x1b[0m\nworld", $out);
    }

    public function testTruncateMiddleShortStringUnchanged(): void
    {
        $this->assertSame('short', Width::truncateMiddle('short', 10));
    }

    public function testTruncateMiddleKeepsBothEnds(): void
    {
        // "abcdefghij" (10) into 7 cells: budget 6, head 3, tail 3 → "abc…hij".
        $this->assertSame('abc…hij', Width::truncateMiddle('abcdefghij', 7));
    }

    public function testTruncateMiddleResultFitsWidth(): void
    {
        $out = Width::truncateMiddle('/var/lib/mysql/data/very/deep/path.db', 20);
        $this->assertLessThanOrEqual(20, Width::string($out));
        $this->assertStringContainsString('…', $out);
    }

    public function testTruncateMiddleStripsAnsi(): void
    {
        $this->assertSame('hello', Width::truncateMiddle("\x1b[31mhello\x1b[0m", 10));
    }

    public function testTruncateMiddleZeroWidth(): void
    {
        $this->assertSame('', Width::truncateMiddle('anything', 0));
    }

    public function testTruncateMiddleEllipsisWiderThanMaxFallsBack(): void
    {
        // max smaller than the ellipsis → plain head-truncate, still fits.
        $out = Width::truncateMiddle('abcdef', 1, '...');
        $this->assertLessThanOrEqual(1, Width::string($out));
    }

    public function testStringMemoRepeatedInputReturnsIdentical(): void
    {
        $s = "\x1b[31mhello 日本\x1b[0m";
        $first  = Width::string($s);       // cold: computes + memoizes
        $second = Width::string($s);       // warm: memo hit
        $this->assertSame($first, $second);
        // "hello" (5) + " " (1) + 日 (2) + 本 (2) = 10.
        $this->assertSame(10, $second);
    }

    /**
     * The static memo behind {@see Width::string()} must never grow past its
     * cap, and every result (fresh, memo-hit, or evicted-and-recomputed) must
     * equal the uncached width. Reverting the cap makes the memo unbounded and
     * fails the size assertion.
     */
    public function testStringMemoStaysBoundedAndCorrect(): void
    {
        $memoProp = new \ReflectionProperty(Width::class, 'memo');
        $memoProp->setAccessible(true);
        $memoProp->setValue(null, []); // clean baseline for a precise size check

        $cap = (new \ReflectionClassConstant(Width::class, 'MEMO_MAX'))->getValue();
        $this->assertIsInt($cap);

        // Feed more distinct strings than the cap; each is pure ASCII so its
        // display width equals its byte length.
        for ($i = 0; $i < $cap + 500; $i++) {
            $s = 'row-' . $i;
            $this->assertSame(\strlen($s), Width::string($s));
        }

        $memo = $memoProp->getValue();
        $this->assertIsArray($memo);
        $this->assertGreaterThan(0, \count($memo), 'memo should be populated');
        $this->assertLessThanOrEqual($cap, \count($memo), 'memo must stay bounded');

        $memoProp->setValue(null, []); // don't leak state into sibling tests
    }
}
