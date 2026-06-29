<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Output;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Output\Truncate;

/**
 * Tests for the ANSI-aware truncation helper.
 */
final class TruncateTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Basic truncation
    // ═══════════════════════════════════════════════════════════════

    public function testTruncateReturnsFullStringIfWithinWidth(): void
    {
        $result = Truncate::truncate('hello', 10);
        $this->assertSame('hello', $result);
    }

    public function testTruncateReturnsFullStringIfExactlyWidth(): void
    {
        $result = Truncate::truncate('hello', 5);
        $this->assertSame('hello', $result);
    }

    public function testTruncateReturnsEmptyIfWidthIsZero(): void
    {
        $result = Truncate::truncate('hello', 0);
        $this->assertSame('', $result);
    }

    public function testTruncateReturnsEmptyIfWidthIsNegative(): void
    {
        $result = Truncate::truncate('hello', -5);
        $this->assertSame('', $result);
    }

    public function testTruncateAddsEllipsisWhenShortened(): void
    {
        $result = Truncate::truncate('hello world', 8);
        $this->assertStringEndsWith('…', $result);
    }

    public function testTruncateOmitsEllipsisWhenNotNeeded(): void
    {
        $result = Truncate::truncate('hello', 5);
        $this->assertSame('hello', $result);
        $this->assertStringNotContainsString('…', $result);
    }

    public function testTruncateAtExactlyWidthZeroWithEllipsisFits(): void
    {
        // When availableWidth <= 0 but ellipsis fits in width...
        $result = Truncate::truncate('abc', 1);
        // Should return single char or ellipsis
        $this->assertNotSame('abc', $result);
    }

    public function testTruncateCustomEllipsis(): void
    {
        $result = Truncate::truncate('hello world', 8, '~~');
        $this->assertStringEndsWith('~~', $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multibyte / UTF-8 handling
    // ═══════════════════════════════════════════════════════════════

    public function testTruncateJapaneseMultibyte(): void
    {
        // CJK chars are 2 cells wide, ellipsis is 1 cell. With width=7: 3 CJK (6 cells) + ellipsis (1 cell) = 7.
        $result = Truncate::truncate('こんにちは世界', 7);
        $this->assertSame('こんに…', $result);
    }

    public function testTruncateEmoji(): void
    {
        $result = Truncate::truncate('⚠️ warning', 6);
        // Should not cut in middle of emoji
        $this->assertStringNotContainsString("\x1b", $result);
    }

    public function testTruncateMixedScripts(): void
    {
        $result = Truncate::truncate('Hello世界123', 8);
        $this->assertLessThanOrEqual(8, mb_strlen($result, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment variants
    // ═══════════════════════════════════════════════════════════════

    public function testTruncateLeftPadsRight(): void
    {
        $result = Truncate::truncateLeft('hi', 5);
        $this->assertSame('hi   ', $result);
    }

    public function testTruncateLeftTruncatesWithPadding(): void
    {
        $result = Truncate::truncateLeft('hello world', 5);
        $this->assertStringEndsWith('…', $result);
        $this->assertLessThanOrEqual(5, mb_strlen($result, 'UTF-8'));
    }

    public function testTruncateRightPadsLeft(): void
    {
        $result = Truncate::truncateRight('hi', 5);
        $this->assertSame('   hi', $result);
    }

    public function testTruncateRightTruncatesWithPadding(): void
    {
        // truncateRight keeps rightmost chars, prepends ellipsis for overflow.
        $result = Truncate::truncateRight('hello world', 5);
        // Keeps 'orld' (4 cells) + ellipsis (1 cell) = 5 cells
        $this->assertStringStartsWith('…', $result);
        $this->assertLessThanOrEqual(5, mb_strlen($result, 'UTF-8'));
        // Correct output: …orld (ellipsis + right 4 chars)
        $this->assertSame('…orld', $result);
    }

    public function testTruncateCenterPadsBothSides(): void
    {
        $result = Truncate::truncateCenter('hi', 5);
        $this->assertSame(' hi  ', $result);
    }

    public function testTruncateCenterTruncatesWithPadding(): void
    {
        $result = Truncate::truncateCenter('hello world', 5);
        $this->assertStringContainsString('…', $result);
        $this->assertLessThanOrEqual(5, mb_strlen($result, 'UTF-8'));
    }

    public function testTruncateCenterEvenWidth(): void
    {
        $result = Truncate::truncateCenter('ab', 4);
        $this->assertLessThanOrEqual(4, mb_strlen($result, 'UTF-8'));
    }

    public function testTruncateCenterOddWidth(): void
    {
        $result = Truncate::truncateCenter('ab', 5);
        $this->assertLessThanOrEqual(5, mb_strlen($result, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // ANSI escape codes stripped from width calculation
    // ═══════════════════════════════════════════════════════════════

    public function testTruncateStripsAnsiForWidthCalculation(): void
    {
        $ansi = "\x1b[31mred\x1b[0m";
        $result = Truncate::truncate($ansi . 'text', 10);
        // Width of "redtext" is 7, which fits in 10, so no truncation
        $this->assertStringNotContainsString("\x1b", $result);
    }

    public function testTruncatePreservesContentAfterAnsiStripped(): void
    {
        $ansi = "\x1b[1mbold\x1b[0mhello";
        $result = Truncate::truncate($ansi, 10);
        $this->assertStringContainsString('hello', $result);
    }
}
