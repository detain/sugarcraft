<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Sprinkles\Cell;
use SugarCraft\Sprinkles\Markup;
use SugarCraft\Sprinkles\Style;
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
}
