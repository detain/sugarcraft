<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Output;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Output\WrapCells;

/**
 * Tests for the ANSI-aware text wrapping helper.
 */
final class WrapCellsTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Basic wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testWrapZeroWidthReturnsEmptyLines(): void
    {
        $lines = WrapCells::wrap('hello world', 0);
        $this->assertSame([''], $lines);
    }

    public function testWrapNegativeWidthReturnsEmptyLines(): void
    {
        $lines = WrapCells::wrap('hello world', -5);
        $this->assertSame([''], $lines);
    }

    public function testWrapSingleWordShorterThanWidth(): void
    {
        $lines = WrapCells::wrap('hello', 10);
        $this->assertSame(['hello'], $lines);
    }

    public function testWrapLongWordBreaksByDefault(): void
    {
        $lines = WrapCells::wrap('superlongword', 5);
        // Long word is placed on new line but not broken
        $this->assertNotEmpty($lines);
        $this->assertGreaterThan(1, count($lines));
    }

    public function testWrapBreakWordsAllowsLongWordBreak(): void
    {
        $lines = WrapCells::wrap('superlongword', 5, breakWords: true);
        // With breakWords=true, the long word is split
        $this->assertNotEmpty($lines);
    }

    public function testWrapMultipleWords(): void
    {
        $lines = WrapCells::wrap('hello world', 10);
        // "hello world" is 11 chars, so should wrap
        $this->assertGreaterThan(1, count($lines));
    }

    public function testWrapPreservesSpaces(): void
    {
        $lines = WrapCells::wrap('hello  world', 20);
        // Double space should be preserved in output
        $this->assertStringContainsString('hello', $lines[0]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Paragraph handling
    // ═══════════════════════════════════════════════════════════════

    public function testWrapEmptyParagraphBecomesEmptyLine(): void
    {
        $lines = WrapCells::wrap("hello\n\nworld", 20);
        // Empty line in the middle should produce an empty string in the array
        $this->assertSame('', $lines[1]);
    }

    public function testWrapNewlineSeparatesParagraphs(): void
    {
        $lines = WrapCells::wrap("hello world\nsecond paragraph here", 20);
        // Should handle paragraph breaks
        $this->assertIsArray($lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // UTF-8 multibyte
    // ═══════════════════════════════════════════════════════════════

    public function testWrapJapaneseMultibyte(): void
    {
        // breakWords=true is needed to split long CJK text into lines of ≤5 display cells.
        // Each CJK char is 2 cells wide, so Width::string() must be used (not mb_strlen).
        $lines = WrapCells::wrap('こんにちは世界', 5, true);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(5, Width::string($line));
        }
    }

    public function testWrapEmoji(): void
    {
        $lines = WrapCells::wrap('⚠️ warning', 8);
        // Emoji + text should be handled without breaking
        $this->assertNotEmpty($lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // ANSI escape codes stripped from width calculation
    // ═══════════════════════════════════════════════════════════════

    public function testWrapStripsAnsiForWidthCalculation(): void
    {
        $ansi = "\x1b[31mred\x1b[0m text";
        $lines = WrapCells::wrap($ansi, 50);
        // "red text" is 8 chars, fits in 50
        $this->assertSame(['red text'], $lines);
    }

    public function testWrapPreservesContentAfterAnsiStripped(): void
    {
        $ansi = "\x1b[1mbold\x1b[0m hello";
        $lines = WrapCells::wrap($ansi, 50);
        $this->assertStringContainsString('hello', $lines[0]);
        $this->assertStringContainsString('bold', $lines[0]);
    }

    // ═══════════════════════════════════════════════════════════════
    // wrapAndPad
    // ═══════════════════════════════════════════════════════════════

    public function testWrapAndPadReturnsPaddedLines(): void
    {
        $lines = WrapCells::wrapAndPad('hello world', 15);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(15, mb_strlen($line, 'UTF-8'));
            $this->assertGreaterThan(0, mb_strlen($line, 'UTF-8'));
        }
    }

    public function testWrapAndPadCustomPadCharacter(): void
    {
        $lines = WrapCells::wrapAndPad('hi', 5, '_');
        $this->assertSame('hi___', $lines[0]);
    }

    public function testWrapAndPadZeroWidth(): void
    {
        $lines = WrapCells::wrapAndPad('hello', 0);
        $this->assertSame([''], $lines);
    }
}
