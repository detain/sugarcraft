<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\WidthHelper;

/**
 * @covers \SugarCraft\Glow\WidthHelper
 */
final class WidthHelperTest extends TestCase
{
    public function testVisualWidthReturnsZeroForEmptyString(): void
    {
        self::assertSame(0, WidthHelper::visualWidth(''));
    }

    public function testVisualWidthReturnsCorrectWidthForAscii(): void
    {
        self::assertSame(5, WidthHelper::visualWidth('hello'));
        self::assertSame(1, WidthHelper::visualWidth('a'));
    }

    public function testVisualWidthReturnsCorrectWidthForFullWidthCharacters(): void
    {
        // Chinese characters are full-width (2 visual units)
        self::assertSame(4, WidthHelper::visualWidth('中文')); // 2 chars * 2 = 4
        self::assertSame(6, WidthHelper::visualWidth('日本語')); // 3 chars * 2 = 6
    }

    public function testVisualWidthReturnsCorrectWidthForEmoji(): void
    {
        // Emoji are typically full-width
        self::assertSame(2, WidthHelper::visualWidth('🎉'));
        self::assertSame(2, WidthHelper::visualWidth('👍'));
        self::assertSame(4, WidthHelper::visualWidth('🎉👍')); // 2 emoji * 2 = 4
    }

    public function testVisualWidthReturnsCorrectWidthForMixedContent(): void
    {
        self::assertSame(6, WidthHelper::visualWidth('hi中文')); // 2 + 4 = 6
        self::assertSame(7, WidthHelper::visualWidth('hello🎉')); // 5 + 2 = 7
    }

    public function testPadRightPadsToExactWidth(): void
    {
        self::assertSame('hello     ', WidthHelper::padRight('hello', 10));
        self::assertSame('hi   ', WidthHelper::padRight('hi', 5));
    }

    public function testPadRightDoesNotPadWhenAlreadyAtWidth(): void
    {
        self::assertSame('hello', WidthHelper::padRight('hello', 5));
    }

    public function testPadRightPadsFullWidthCharacters(): void
    {
        $result = WidthHelper::padRight('日本語', 8, '-');
        self::assertSame(8, WidthHelper::visualWidth($result));
        self::assertStringStartsWith('日本語', $result);
    }

    public function testPadRightReturnsEmptyForZeroWidth(): void
    {
        self::assertSame('', WidthHelper::padRight('hello', 0));
    }

    public function testPadRightUsesCustomPadCharacter(): void
    {
        self::assertSame('hello*****', WidthHelper::padRight('hello', 10, '*'));
    }

    public function testSliceReturnsEmptyForInvalidRange(): void
    {
        self::assertSame('', WidthHelper::slice('hello', 3, 2));
        self::assertSame('', WidthHelper::slice('hello', 10, 20));
    }

    public function testSliceReturnsEmptyForEmptyString(): void
    {
        self::assertSame('', WidthHelper::slice('', 0, 5));
    }

    public function testSliceReturnsFullStringWhenStartIsZero(): void
    {
        self::assertSame('hello', WidthHelper::slice('hello', 0, 5));
    }

    public function testSliceSlicesCorrectly(): void
    {
        self::assertSame('he', WidthHelper::slice('hello', 0, 2));
        self::assertSame('llo', WidthHelper::slice('hello', 2, 5));
    }

    public function testSliceSlicesFullWidthCharacters(): void
    {
        // Full-width char '日' has width 2, so slice(0, 2) returns it
        self::assertSame('日', WidthHelper::slice('日本語', 0, 2));
        // slice(0, 1) still returns '日' because we can't split full-width chars
        self::assertSame('日', WidthHelper::slice('日本語', 0, 1));
        self::assertSame('日本', WidthHelper::slice('日本語', 0, 4));
    }

    public function testIsFullWidthReturnsFalseForEmptyString(): void
    {
        self::assertFalse(WidthHelper::isFullWidth(''));
    }

    public function testIsFullWidthReturnsFalseForAscii(): void
    {
        self::assertFalse(WidthHelper::isFullWidth('a'));
        self::assertFalse(WidthHelper::isFullWidth(' '));
        self::assertFalse(WidthHelper::isFullWidth('!'));
    }

    public function testIsFullWidthReturnsTrueForFullWidthCharacters(): void
    {
        self::assertTrue(WidthHelper::isFullWidth('日'));
        self::assertTrue(WidthHelper::isFullWidth('中'));
        self::assertTrue(WidthHelper::isFullWidth('文'));
    }

    public function testIsFullWidthReturnsTrueForEmoji(): void
    {
        self::assertTrue(WidthHelper::isFullWidth('🎉'));
        self::assertTrue(WidthHelper::isFullWidth('👍'));
    }

    public function testTruncateReturnsEmptyForZeroMaxWidth(): void
    {
        self::assertSame('', WidthHelper::truncate('hello', 0));
    }

    public function testTruncateReturnsFullStringWhenUnderMaxWidth(): void
    {
        self::assertSame('hi', WidthHelper::truncate('hi', 10));
    }

    public function testTruncateTruncatesCorrectly(): void
    {
        self::assertSame('hel', WidthHelper::truncate('hello', 3));
    }

    public function testTruncateTruncatesFullWidthCharacters(): void
    {
        $result = WidthHelper::truncate('日本語テスト', 6);
        // 6 visual width should give us 3 characters (3 * 2 = 6)
        self::assertSame(6, WidthHelper::visualWidth($result));
        self::assertStringStartsWith('日本', $result);
    }

    public function testTruncateDoesNotSplitFullWidthCharacters(): void
    {
        // truncate('日本語', 3) calls slice('日本語', 0, 3)
        // First char '日' (width 2) is included, then '本' (width 2) is also included
        // since cursor (2) >= end (3) only after processing '本', making total width 4
        $result = WidthHelper::truncate('日本語', 3);
        self::assertSame(4, WidthHelper::visualWidth($result));
        self::assertSame('日本', $result);
    }
}
