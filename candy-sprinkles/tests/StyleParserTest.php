<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Cell;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Sprinkles\StyleParser;

final class StyleParserTest extends TestCase
{
    private Style $defaultStyle;

    protected function setUp(): void
    {
        $this->defaultStyle = Style::new();
    }

    // ═══════════════════════════════════════════════════════════════
    // Empty input
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyString(): void
    {
        $cells = StyleParser::parse('', $this->defaultStyle);
        $this->assertSame([], $cells);
    }

    // ═══════════════════════════════════════════════════════════════
    // Plain text (no styling)
    // ═══════════════════════════════════════════════════════════════

    public function testPlainText(): void
    {
        $cells = StyleParser::parse('hello', $this->defaultStyle);

        $this->assertCount(5, $cells);
        $this->assertSame('h', $cells[0]->rune);
        $this->assertSame('e', $cells[1]->rune);
        $this->assertSame('l', $cells[2]->rune);
        $this->assertSame('l', $cells[3]->rune);
        $this->assertSame('o', $cells[4]->rune);

        // All should have default style
        foreach ($cells as $cell) {
            $this->assertSame($this->defaultStyle, $cell->style);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic inline styling
    // ═══════════════════════════════════════════════════════════════

    public function testFgColorOnly(): void
    {
        $cells = StyleParser::parse('[text](fg:red)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertNotNull($cells[$i]->style->getForeground());
            $this->assertSame(205, $cells[$i]->style->getForeground()->r);
            $this->assertSame(0, $cells[$i]->style->getForeground()->g);
            $this->assertSame(0, $cells[$i]->style->getForeground()->b);
        }
    }

    public function testBgColorOnly(): void
    {
        $cells = StyleParser::parse('[text](bg:blue)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertNotNull($cells[$i]->style->getBackground());
            $this->assertSame(0, $cells[$i]->style->getBackground()->r);
            $this->assertSame(0, $cells[$i]->style->getBackground()->g);
            $this->assertSame(238, $cells[$i]->style->getBackground()->b);
        }
    }

    public function testBoldModifier(): void
    {
        $cells = StyleParser::parse('[text](bold)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertTrue($cells[$i]->style->isBold());
        }
    }

    public function testItalicModifier(): void
    {
        $cells = StyleParser::parse('[text](italic)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertTrue($cells[$i]->style->isItalic());
        }
    }

    public function testUnderlineModifier(): void
    {
        $cells = StyleParser::parse('[text](underline)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertTrue($cells[$i]->style->isUnderline());
        }
    }

    public function testDimModifier(): void
    {
        $cells = StyleParser::parse('[text](dim)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertTrue($cells[$i]->style->isFaint());
        }
    }

    public function testReverseModifier(): void
    {
        $cells = StyleParser::parse('[text](reverse)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertTrue($cells[$i]->style->isReverse());
        }
    }

    public function testStrikeModifier(): void
    {
        $cells = StyleParser::parse('[text](strike)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertTrue($cells[$i]->style->isStrikethrough());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Combined styling
    // ═══════════════════════════════════════════════════════════════

    public function testFgAndBg(): void
    {
        $cells = StyleParser::parse('[text](fg:red,bg:blue)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            // Red foreground
            $this->assertNotNull($cells[$i]->style->getForeground());
            $this->assertSame(205, $cells[$i]->style->getForeground()->r);
            $this->assertSame(0, $cells[$i]->style->getForeground()->g);
            $this->assertSame(0, $cells[$i]->style->getForeground()->b);
            // Blue background
            $this->assertNotNull($cells[$i]->style->getBackground());
            $this->assertSame(0, $cells[$i]->style->getBackground()->r);
            $this->assertSame(0, $cells[$i]->style->getBackground()->g);
            $this->assertSame(238, $cells[$i]->style->getBackground()->b);
        }
    }

    public function testAllModifiers(): void
    {
        $cells = StyleParser::parse('[text](fg:red,bg:blue,bold,italic,underline)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertNotNull($cells[$i]->style->getForeground());
            $this->assertSame(205, $cells[$i]->style->getForeground()->r);
            $this->assertNotNull($cells[$i]->style->getBackground());
            $this->assertSame(0, $cells[$i]->style->getBackground()->r);
            $this->assertTrue($cells[$i]->style->isBold());
            $this->assertTrue($cells[$i]->style->isItalic());
            $this->assertTrue($cells[$i]->style->isUnderline());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Named colors
    // ═══════════════════════════════════════════════════════════════

    public function testNamedColors(): void
    {
        $colors = [
            'black' => [0, 0, 0],
            'red' => [205, 0, 0],
            'green' => [0, 205, 0],
            'yellow' => [205, 205, 0],
            'blue' => [0, 0, 238],
            'magenta' => [205, 0, 205],
            'cyan' => [0, 205, 205],
            'white' => [229, 229, 229],
        ];

        foreach ($colors as $name => [$er, $eg, $eb]) {
            $cells = StyleParser::parse("[x](fg:{$name})", $this->defaultStyle);
            $this->assertCount(1, $cells, "Failed for color: {$name}");
            $this->assertSame($er, $cells[0]->style->getForeground()->r, "Failed for color: {$name}");
            $this->assertSame($eg, $cells[0]->style->getForeground()->g, "Failed for color: {$name}");
            $this->assertSame($eb, $cells[0]->style->getForeground()->b, "Failed for color: {$name}");
        }
    }

    public function testBrightColors(): void
    {
        $colors = [
            'bright-black' => [127, 127, 127],
            'bright-red' => [255, 0, 0],
            'bright-green' => [0, 255, 0],
            'bright-yellow' => [255, 255, 0],
            'bright-blue' => [92, 92, 255],
            'bright-magenta' => [255, 0, 255],
            'bright-cyan' => [0, 255, 255],
            'bright-white' => [255, 255, 255],
        ];

        foreach ($colors as $name => [$er, $eg, $eb]) {
            $cells = StyleParser::parse("[x](fg:{$name})", $this->defaultStyle);
            $this->assertCount(1, $cells, "Failed for bright color: {$name}");
            $this->assertSame($er, $cells[0]->style->getForeground()->r, "Failed for bright color: {$name}");
            $this->assertSame($eg, $cells[0]->style->getForeground()->g, "Failed for bright color: {$name}");
            $this->assertSame($eb, $cells[0]->style->getForeground()->b, "Failed for bright color: {$name}");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Hex colors
    // ═══════════════════════════════════════════════════════════════

    public function testHex3Color(): void
    {
        $cells = StyleParser::parse('[x](fg:#f00)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        // #f00 should expand to #ff0000 (red)
        $this->assertSame(255, $cells[0]->style->getForeground()->r);
        $this->assertSame(0, $cells[0]->style->getForeground()->g);
        $this->assertSame(0, $cells[0]->style->getForeground()->b);
    }

    public function testHex6Color(): void
    {
        $cells = StyleParser::parse('[x](fg:#ff0000)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame(255, $cells[0]->style->getForeground()->r);
        $this->assertSame(0, $cells[0]->style->getForeground()->g);
        $this->assertSame(0, $cells[0]->style->getForeground()->b);
    }

    public function testHex6ColorGreen(): void
    {
        $cells = StyleParser::parse('[x](fg:#00ff00)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame(0, $cells[0]->style->getForeground()->r);
        $this->assertSame(255, $cells[0]->style->getForeground()->g);
        $this->assertSame(0, $cells[0]->style->getForeground()->b);
    }

    public function testHex6ColorBlue(): void
    {
        $cells = StyleParser::parse('[x](fg:#0000ff)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame(0, $cells[0]->style->getForeground()->r);
        $this->assertSame(0, $cells[0]->style->getForeground()->g);
        $this->assertSame(255, $cells[0]->style->getForeground()->b);
    }

    // ═══════════════════════════════════════════════════════════════
    // Unknown tokens (should not crash, just ignore)
    // ═══════════════════════════════════════════════════════════════

    public function testUnknownColorIgnored(): void
    {
        // Should not crash, unknown color fg:nonexistent should be ignored
        $cells = StyleParser::parse('[text](fg:nonexistent)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            // foreground should remain null (defaultStyle has no foreground)
            $this->assertNull($cells[$i]->style->getForeground());
        }
    }

    public function testUnknownModifierIgnored(): void
    {
        // Should not crash, unknown modifier should be ignored
        $cells = StyleParser::parse('[text](unknown)', $this->defaultStyle);

        $this->assertCount(4, $cells);
        $expected = ['t', 'e', 'x', 't'];
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
        }
    }

    public function testUnknownForegroundWithValidBackground(): void
    {
        // Unknown fg should be ignored but valid bg should still apply
        $cells = StyleParser::parse('[x](fg:unknown,bg:red)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertNull($cells[0]->style->getForeground());
        $this->assertNotNull($cells[0]->style->getBackground());
        $this->assertSame(205, $cells[0]->style->getBackground()->r);
    }

    // ═══════════════════════════════════════════════════════════════
    // Malformed input (falls back to default style on malformed input)
    // ═══════════════════════════════════════════════════════════════

    public function testUnclosedBracket(): void
    {
        // Unclosed bracket - parser finds no closing ] so treats rest as plain text
        $cells = StyleParser::parse('[text', $this->defaultStyle);

        $this->assertCount(5, $cells);
        $this->assertSame('[', $cells[0]->rune);
        $this->assertSame('t', $cells[1]->rune);
        $this->assertSame('e', $cells[2]->rune);
        $this->assertSame('x', $cells[3]->rune);
        $this->assertSame('t', $cells[4]->rune);
    }

    public function testMalformedLPARENReturnsDefaultStyledCells(): void
    {
        // Malformed [( returns default-styled cells (no crash)
        $cells = StyleParser::parse('[(', $this->defaultStyle);

        $this->assertGreaterThanOrEqual(1, count($cells));
        // Both chars should be default-styled
        foreach ($cells as $cell) {
            $this->assertSame($this->defaultStyle, $cell->style);
        }
    }

    public function testExtraOpenBracket(): void
    {
        // Double open bracket - first [ triggers style mode, second [ is just text
        $cells = StyleParser::parse('[[text](fg:red)', $this->defaultStyle);

        $this->assertGreaterThanOrEqual(1, count($cells));
    }

    public function testExtraCloseBracket(): void
    {
        // Extra close bracket after style - should not crash
        $cells = StyleParser::parse('[text]](fg:red)', $this->defaultStyle);

        $this->assertGreaterThanOrEqual(1, count($cells));
    }

    public function testMismatchedParentheses(): void
    {
        // Missing closing ) for style attributes
        $cells = StyleParser::parse('[text](fg:red', $this->defaultStyle);

        // Without closing ), the style is still applied to [text]
        // Output is 4 cells: t, e, x, t with fg:red style
        $this->assertCount(4, $cells);
        $this->assertSame(205, $cells[0]->style->getForeground()->r);
    }

    public function testEmptyStyleAttributes(): void
    {
        // Empty style attributes should not crash
        $cells = StyleParser::parse('[x]()', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame('x', $cells[0]->rune);
    }

    public function testTrailingCommaInStyle(): void
    {
        // Trailing comma in style attributes
        $cells = StyleParser::parse('[x](fg:red,)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame(205, $cells[0]->style->getForeground()->r);
    }

    // ═══════════════════════════════════════════════════════════════
    // Nested brackets
    // ═══════════════════════════════════════════════════════════════

    public function testNestedBrackets(): void
    {
        // Nested brackets - outer style applies to all, inner style applies to inner text
        $cells = StyleParser::parse('[[outer](fg:red)](fg:blue)', $this->defaultStyle);

        $this->assertGreaterThanOrEqual(1, count($cells));
    }

    public function testDeeplyNestedBrackets(): void
    {
        // Three levels of nesting
        $cells = StyleParser::parse('[[[x](fg:red)](fg:green)](fg:blue)', $this->defaultStyle);

        // Should parse without crashing
        $this->assertGreaterThanOrEqual(1, count($cells));
    }

    public function testNestedAndPlainText(): void
    {
        // Mix of styled and plain text with nesting
        $cells = StyleParser::parse('plain [styled](fg:red) more', $this->defaultStyle);

        $this->assertGreaterThanOrEqual(1, count($cells));
    }

    // ═══════════════════════════════════════════════════════════════
    // Multi-character text
    // ═══════════════════════════════════════════════════════════════

    public function testLongerText(): void
    {
        $cells = StyleParser::parse('[hello world](fg:green)', $this->defaultStyle);

        $this->assertCount(11, $cells);
        $expected = ['h', 'e', 'l', 'l', 'o', ' ', 'w', 'o', 'r', 'l', 'd'];
        for ($i = 0; $i < 11; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertSame(0, $cells[$i]->style->getForeground()->r);
            $this->assertSame(205, $cells[$i]->style->getForeground()->g);
            $this->assertSame(0, $cells[$i]->style->getForeground()->b);
        }
    }

    public function testMultipleStyledSegments(): void
    {
        // Multiple styled segments in one string
        $cells = StyleParser::parse('[red](fg:red)[green](fg:green)', $this->defaultStyle);

        // 3 chars red + 5 chars green = 8 cells
        $this->assertCount(8, $cells);

        // First 3 should be red
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(205, $cells[$i]->style->getForeground()->r);
        }

        // Next 5 should be green
        for ($i = 3; $i < 8; $i++) {
            $this->assertSame(0, $cells[$i]->style->getForeground()->r);
            $this->assertSame(205, $cells[$i]->style->getForeground()->g);
        }
    }

    public function testStyledTextBetweenPlainText(): void
    {
        $cells = StyleParser::parse('before [styled](fg:blue) after', $this->defaultStyle);

        // 'before ' = 7 chars default style
        // 'styled' = 6 chars blue
        // ' after' = 6 chars default
        $this->assertCount(19, $cells);

        // First 7 chars should have null foreground
        for ($i = 0; $i < 7; $i++) {
            $this->assertNull($cells[$i]->style->getForeground());
        }

        // Next 6 chars should be blue
        for ($i = 7; $i < 13; $i++) {
            $this->assertSame(0, $cells[$i]->style->getForeground()->r);
            $this->assertSame(0, $cells[$i]->style->getForeground()->g);
            $this->assertSame(238, $cells[$i]->style->getForeground()->b);
        }

        // Last 6 chars should have null foreground
        for ($i = 13; $i < 19; $i++) {
            $this->assertNull($cells[$i]->style->getForeground());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Style inheritance and stacking
    // ═══════════════════════════════════════════════════════════════

    public function testStyleInheritanceFromDefault(): void
    {
        // When default style has properties, styled text should override
        $defaultWithBg = Style::new()->background(Color::rgb(100, 100, 100));
        $cells = StyleParser::parse('[x](fg:red)', $defaultWithBg);

        $this->assertCount(1, $cells);
        $this->assertSame(205, $cells[0]->style->getForeground()->r);
        // Background should be inherited from default
        $this->assertNotNull($cells[0]->style->getBackground());
    }

    public function testStylePopAfterClosingBracket(): void
    {
        // After closing ], style should revert to previous
        $defaultFg = Style::new()->foreground(Color::rgb(100, 100, 100));
        $cells = StyleParser::parse('[x](fg:red)plain', $defaultFg);

        // 'x' is red, 'plain' (5 cells) should have original default foreground
        $this->assertCount(6, $cells);
        $this->assertSame(205, $cells[0]->style->getForeground()->r); // 'x'
        $this->assertSame(100, $cells[1]->style->getForeground()->r); // 'p'
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleCharacterStyled(): void
    {
        $cells = StyleParser::parse('[x](bold)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame('x', $cells[0]->rune);
        $this->assertTrue($cells[0]->style->isBold());
    }

    public function testWhitespaceOnly(): void
    {
        $cells = StyleParser::parse('   ', $this->defaultStyle);

        $this->assertCount(3, $cells);
        foreach ($cells as $cell) {
            $this->assertSame(' ', $cell->rune);
        }
    }

    public function testWhitespaceInStyledText(): void
    {
        $cells = StyleParser::parse('[a b c](fg:red)', $this->defaultStyle);

        $this->assertCount(5, $cells);
        $expected = ['a', ' ', 'b', ' ', 'c'];
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertSame(205, $cells[0]->style->getForeground()->r);
        }
    }

    public function testSpecialCharacters(): void
    {
        $cells = StyleParser::parse('[!@#$%](fg:yellow)', $this->defaultStyle);

        $this->assertCount(5, $cells);
        foreach ($cells as $cell) {
            $this->assertSame(205, $cell->style->getForeground()->r);
            $this->assertSame(205, $cell->style->getForeground()->g);
            $this->assertSame(0, $cell->style->getForeground()->b);
        }
    }

    public function testForegroundAlias(): void
    {
        // 'foreground' should work same as 'fg'
        $cells = StyleParser::parse('[x](foreground:red)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame(205, $cells[0]->style->getForeground()->r);
    }

    public function testBackgroundAlias(): void
    {
        // 'background' should work same as 'bg'
        $cells = StyleParser::parse('[x](background:blue)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertSame(0, $cells[0]->style->getBackground()->r);
        $this->assertSame(0, $cells[0]->style->getBackground()->g);
        $this->assertSame(238, $cells[0]->style->getBackground()->b);
    }

    public function testModifierCaseSensitivity(): void
    {
        // Modifiers should be case-insensitive
        $cells = StyleParser::parse('[x](BOLD,ITALIC)', $this->defaultStyle);

        $this->assertCount(1, $cells);
        $this->assertTrue($cells[0]->style->isBold());
        $this->assertTrue($cells[0]->style->isItalic());
    }

    // ═══════════════════════════════════════════════════════════════
    // Multi-byte unicode
    // ═══════════════════════════════════════════════════════════════

    public function testMultiByteUnicodeBody(): void
    {
        // Multi-byte unicode body - should be parsed correctly
        $cells = StyleParser::parse('[日本語](fg:red)', $this->defaultStyle);

        // '日本語' is 3 characters
        $this->assertCount(3, $cells);
        $expected = ['日', '本', '語'];
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame($expected[$i], $cells[$i]->rune);
            $this->assertSame(205, $cells[$i]->style->getForeground()->r);
        }
    }

    public function testEmojiInStyledText(): void
    {
        $cells = StyleParser::parse('[🚀](fg:green)', $this->defaultStyle);

        // Rocket emoji is a single grapheme
        $this->assertCount(1, $cells);
        $this->assertSame('🚀', $cells[0]->rune);
        $this->assertSame(0, $cells[0]->style->getForeground()->r);
        $this->assertSame(205, $cells[0]->style->getForeground()->g);
        $this->assertSame(0, $cells[0]->style->getForeground()->b);
    }

    // ═══════════════════════════════════════════════════════════════
    // Performance smoke test
    // ═══════════════════════════════════════════════════════════════

    public function testLongInputPerformanceSmoke(): void
    {
        // Long input (~1MB) - performance smoke test
        $longText = str_repeat('x', 1_000_000);
        $input = "[{$longText}](fg:red)";

        $start = microtime(true);
        $cells = StyleParser::parse($input, $this->defaultStyle);
        $elapsed = microtime(true) - $start;

        $this->assertCount(1_000_000, $cells);
        $this->assertSame(205, $cells[0]->style->getForeground()->r);
        // Should complete in reasonable time (not more than 5 seconds)
        $this->assertLessThan(5.0, $elapsed, "Parser took too long: {$elapsed}s");
    }
}
