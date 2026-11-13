<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Style\Cell;
use SugarCraft\Style\Markup;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

final class MarkupTest extends TestCase
{
    private function assertCellsEqual(array $expected, array $actual): void
    {
        $this->assertSame(count($expected), count($actual));
        foreach ($expected as $idx => $expectedCell) {
            $this->assertSame($expectedCell->rune, $actual[$idx]->rune);
        }
    }

    public function testParseBoldText(): void
    {
        $cells = Markup::parse('[bold]hello[/]', Style::new());
        $this->assertSame('hello', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        // All cells should have bold=true
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isBold());
        }
    }

    public function testParseItalicText(): void
    {
        $cells = Markup::parse('[italic]hello[/]', Style::new());
        $this->assertSame('hello', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isItalic());
        }
    }

    public function testParseUnderlineText(): void
    {
        $cells = Markup::parse('[underline]hello[/]', Style::new());
        $this->assertSame('hello', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isUnderline());
        }
    }

    public function testParseRedText(): void
    {
        $cells = Markup::parse('[red]hello[/]', Style::new());
        $this->assertSame('hello', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        foreach ($cells as $cell) {
            $this->assertNotNull($cell->style->getForeground());
            $fg = $cell->style->getForeground();
            $this->assertSame(205, $fg->r);
            $this->assertSame(0, $fg->g);
            $this->assertSame(0, $fg->b);
        }
    }

    public function testParseBlueText(): void
    {
        $cells = Markup::parse('[blue]hello[/]', Style::new());
        $this->assertSame('hello', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(0, $fg->r);
            $this->assertSame(0, $fg->g);
            $this->assertSame(238, $fg->b);
        }
    }

    public function testParseBoldRedText(): void
    {
        $cells = Markup::parse('[bold red]hello[/]', Style::new());
        $this->assertSame('hello', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isBold());
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(205, $fg->r);
        }
    }

    public function testParsePlainTextNoMarkup(): void
    {
        $cells = Markup::parse('hello', Style::new());
        $this->assertSame('hello', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        foreach ($cells as $cell) {
            $this->assertFalse($cell->style->isBold());
        }
    }

    public function testParseMixedPlainAndStyled(): void
    {
        $cells = Markup::parse('hello [bold]world[/] end', Style::new());
        $this->assertSame('hello world end', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
    }

    public function testParseMultipleStyledRegions(): void
    {
        $cells = Markup::parse('[bold]one[/] [red]two[/]', Style::new());
        $this->assertSame('one two', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
    }

    public function testParseBrightColor(): void
    {
        $cells = Markup::parse('[bright-red]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(255, $fg->r);
        }
    }

    public function testParseFgColorShortcut(): void
    {
        $cells = Markup::parse('[fg:blue]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(0, $fg->r);
            $this->assertSame(0, $fg->g);
            $this->assertSame(238, $fg->b);
        }
    }

    public function testParseBgColorShortcut(): void
    {
        $cells = Markup::parse('[bg:yellow]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $bg = $cell->style->getBackground();
            $this->assertNotNull($bg);
            $this->assertSame(205, $bg->r);
            $this->assertSame(205, $bg->g);
            $this->assertSame(0, $bg->b);
        }
    }

    public function testParseStrikeText(): void
    {
        $cells = Markup::parse('[strike]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isStrikethrough());
        }
    }

    public function testParseReverseText(): void
    {
        $cells = Markup::parse('[reverse]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isReverse());
        }
    }

    public function testParseDimText(): void
    {
        $cells = Markup::parse('[dim]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isFaint());
        }
    }

    public function testParseEmptyString(): void
    {
        $cells = Markup::parse('', Style::new());
        $this->assertSame([], $cells);
    }

    public function testParseUnclosedMarkup(): void
    {
        // Unclosed markup should render the [ as literal and continue
        $cells = Markup::parse('[bold]hello', Style::new());
        $this->assertGreaterThan(0, count($cells));
    }

    public function testUnterminatedOpenBracketFlushesRemainder(): void
    {
        // Finding 022: an unterminated '[' used to drop everything after it.
        $cells = Markup::parse('abc[def', Style::new());
        $this->assertSame('abc[def', implode(array_map(fn (Cell $c) => $c->rune, $cells)));
    }

    public function testUnterminatedCloseTagFlushesRemainder(): void
    {
        // The malformed '[/' twin: the styled text before it survives, the
        // tail rides verbatim, and the literal '[/y' itself is unstyled.
        $cells = Markup::parse('[bold]x[/y', Style::new());
        $this->assertSame('x[/y', implode(array_map(fn (Cell $c) => $c->rune, $cells)));
        $this->assertTrue($cells[0]->style->isBold());
        $this->assertFalse($cells[1]->style->isBold());
    }

    public function testParseClosingTagWithoutOpening(): void
    {
        // Malformed closing tag - should just output the content normally
        $cells = Markup::parse('hello[/] world', Style::new());
        $this->assertSame('hello[/] world', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
    }

    public function testParseCyanColor(): void
    {
        $cells = Markup::parse('[cyan]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(0, $fg->r);
            $this->assertSame(205, $fg->g);
            $this->assertSame(205, $fg->b);
        }
    }

    public function testParseGreenColor(): void
    {
        $cells = Markup::parse('[green]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(0, $fg->r);
            $this->assertSame(205, $fg->g);
            $this->assertSame(0, $fg->b);
        }
    }

    public function testParseWhiteColor(): void
    {
        $cells = Markup::parse('[white]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(229, $fg->r);
            $this->assertSame(229, $fg->g);
            $this->assertSame(229, $fg->b);
        }
    }

    public function testParseBlackColor(): void
    {
        $cells = Markup::parse('[black]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(0, $fg->r);
            $this->assertSame(0, $fg->g);
            $this->assertSame(0, $fg->b);
        }
    }

    public function testParseYellowColor(): void
    {
        $cells = Markup::parse('[yellow]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(205, $fg->r);
            $this->assertSame(205, $fg->g);
            $this->assertSame(0, $fg->b);
        }
    }

    public function testParseMagentaColor(): void
    {
        $cells = Markup::parse('[magenta]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(205, $fg->r);
            $this->assertSame(0, $fg->g);
            $this->assertSame(205, $fg->b);
        }
    }

    public function testParseStrikethroughAlias(): void
    {
        // "strikethrough" should work the same as "strike"
        $cells = Markup::parse('[strikethrough]hello[/]', Style::new());
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isStrikethrough());
        }
    }

    public function testParseUnknownTagIsReEmittedLiterally(): void
    {
        // A region whose every word is unknown survives verbatim — it used
        // to be silently deleted ("[unknown-tag]hello" came out "hello").
        $cells = Markup::parse('[unknown-tag]hello[/]', Style::new());
        $this->assertSame('[unknown-tag]hello[/]', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
    }

    public function testParseMixedColorTags(): void
    {
        $cells = Markup::parse('[bright-green]text[/]', Style::new());
        foreach ($cells as $cell) {
            $fg = $cell->style->getForeground();
            $this->assertNotNull($fg);
            $this->assertSame(0, $fg->r);
            $this->assertSame(255, $fg->g);
            $this->assertSame(0, $fg->b);
        }
    }

    public function testParseBrightColorsAllVariants(): void
    {
        // Test bright-black, bright-white for completeness
        $cells = Markup::parse('[bright-black]a[/]', Style::new());
        $fg = $cells[0]->style->getForeground();
        $this->assertSame(127, $fg->r);

        $cells = Markup::parse('[bright-white]a[/]', Style::new());
        $fg = $cells[0]->style->getForeground();
        $this->assertSame(255, $fg->r);
    }

    public function testNoEffectBracketedRunsSurviveVerbatim(): void
    {
        // Every one of these used to LOSE characters: brackets whose words
        // carry no effect are plain text, not tags.
        foreach ([
            'a[b]c',            // unknown word between brackets
            '[on] status',      // brackets in ordinary prose
            '[]',               // empty region
            '[[lit]]',          // nested-looking literal
            '[fg:zzz x]',       // only an unresolvable color shortcut
        ] as $input) {
            $cells = Markup::parse($input, Style::new());
            $this->assertSame(
                $input,
                implode(array_map(fn(Cell $c) => $c->rune, $cells)),
                'literal preservation moved: ' . $input,
            );
        }
    }

    public function testEffectiveTagStillSwallowsDelimitersAndIgnoresUnknownWords(): void
    {
        // The counterpart: an EFFECTIVE region is still consumed as a tag,
        // and merely-unknown words inside it stay ignored (they do not
        // force the whole region back to literal).
        $cells = Markup::parse('[bold typo]hi[/]', Style::new());
        $this->assertSame('hi', implode(array_map(fn(Cell $c) => $c->rune, $cells)));
        foreach ($cells as $cell) {
            $this->assertTrue($cell->style->isBold());
        }
    }

    public function testNestingBeyondTheDepthCeilingIsRefused(): void
    {
        // 128 concurrent open regions parse; 129 refuse loudly instead of
        // growing the style stack per codepoint of adversarial input.
        $ok = str_repeat('[bold]', 128) . 'x' . str_repeat('[/]', 128);
        $cells = Markup::parse($ok, Style::new());
        $this->assertCount(1, $cells);
        $this->assertSame('x', $cells[0]->rune);
        $this->assertTrue($cells[0]->style->isBold());

        $this->expectException(\RuntimeException::class);
        Markup::parse(str_repeat('[bold]', 129) . 'x', Style::new());
    }
}
