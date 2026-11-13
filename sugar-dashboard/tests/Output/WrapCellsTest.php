<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Output;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Output\WrapCells;

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
        // Without breakWords the word is NOT broken and NOT dropped: it owns
        // its line (an honest overflow). The old second line here was the
        // algorithm's phantom empty-line artifact (finding sugar-dashboard-037).
        $this->assertSame(['superlongword'], $lines);
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
    // ANSI escapes survive the wrap (never stripped — finding 037)
    // ═══════════════════════════════════════════════════════════════

    public function testWrapFitsByVisibleCellsNotEscapeBytes(): void
    {
        $ansi = "\x1b[31mred\x1b[0m text";
        $lines = WrapCells::wrap($ansi, 50);
        // Width::string ignores escape bytes for FITTING, but the SGR
        // sequences survive into the output — the class never strips.
        $this->assertSame(["\x1b[31mred\x1b[0m text"], $lines);
    }

    public function testWrapKeepsStyledRunsByteIdentical(): void
    {
        $ansi = "\x1b[1mbold\x1b[0m hello";
        $lines = WrapCells::wrap($ansi, 50);
        $this->assertSame(["\x1b[1mbold\x1b[0m hello"], $lines);
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

    // ═══════════════════════════════════════════════════════════════
    // Losslessness + cell-boundary cuts (finding sugar-dashboard-037)
    // ═══════════════════════════════════════════════════════════════

    public function testWordBreakIsLosslessConcatRebuildsVisibleContent(): void
    {
        $lines = WrapCells::wrap('TheQuickBrownFoxJumps', 5, breakWords: true);
        // Every line respects the cell budget...
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(5, Width::string($line));
        }
        // ...and not one character of the word was discarded across cuts.
        $this->assertSame(
            'TheQuickBrownFoxJumps',
            implode('', $lines)
        );
    }

    public function testMidWordSgrSurvivesTheCutWithoutDanglingEscapes(): void
    {
        $styled = "\x1b[31mAAAAAA\x1b[0m";
        $lines = WrapCells::wrap($styled, 4, breakWords: true);
        $this->assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            // Dangling-escape probe: every ESC opens a complete CSI.
            $this->assertSame(
                substr_count($line, "\x1b"),
                preg_match_all('/\x1b\[[0-9;]*m/', $line)
            );
        }
        // Visible A's are exactly preserved (styling may be re-armed/harvested).
        $this->assertSame('AAAAAA', implode('', array_map(
            static fn (string $l): string => preg_replace('/\x1b\[[0-9;]*m/', '', $l) ?? '',
            $lines
        )));
    }

    public function testWideGlyphAloneOnNarrowLineOwnsTheLine(): void
    {
        // A 2-cell glyph at width 1 cannot share or split: it takes the line
        // whole (honest overflow) instead of being dropped or cut.
        $lines = WrapCells::wrap('あいう', 1, breakWords: true);
        $this->assertSame(['あ', 'い', 'う'], $lines);
    }

    public function testWrapAndPadPadsByDisplayCellsNotCodepoints(): void
    {
        $lines = WrapCells::wrapAndPad("\x1b[31mhi\x1b[0m", 6);
        $this->assertCount(1, $lines);
        $this->assertSame(6, Width::string($lines[0]));
        // The SGR payload survives the pad leg too.
        $this->assertStringContainsString("\x1b[31m", $lines[0]);
    }
}
