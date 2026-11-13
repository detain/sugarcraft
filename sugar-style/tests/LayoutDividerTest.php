<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Style\Layout;

final class LayoutDividerTest extends TestCase
{
    public function testHorizontalDividerRepeatsOnEveryRow(): void
    {
        $this->assertSame("ab│c\ncd│ ", Layout::joinHorizontalWithDivider(0.0, '│', "ab\ncd", 'c'));
    }

    public function testHorizontalDropsEmptyBlockWithItsDivider(): void
    {
        $this->assertSame("x\ny", Layout::joinHorizontalWithDivider(0.0, '│', '', "x\ny"));
        $this->assertSame('x', Layout::joinHorizontalWithDivider(0.0, '│', 'x', ''));
        $this->assertSame('', Layout::joinHorizontalWithDivider(0.0, '│'));
    }

    public function testHorizontalEmptyDividerIsPlainJoin(): void
    {
        $this->assertSame(Layout::joinHorizontal(0.0, 'a', 'b'), Layout::joinHorizontalWithDivider(0.0, '', 'a', 'b'));
    }

    public function testHorizontalMultiLineDividerIsRowByRow(): void
    {
        $this->assertSame("a┬b\n │c", Layout::joinHorizontalWithDivider(0.0, "┬\n│", 'a', "b\nc"));
    }

    public function testVerticalRuleTiledToWidestBlock(): void
    {
        $this->assertSame("abcd\n────\nxy  ", Layout::joinVerticalWithDivider(0.0, '─', 'abcd', 'xy'));
        $this->assertSame("ab \n-=-\nxyz", Layout::joinVerticalWithDivider(0.0, '-=', 'ab', 'xyz'));
    }

    public function testVerticalDropsEmptyBlockAndRule(): void
    {
        $this->assertSame('a', Layout::joinVerticalWithDivider(0.0, '─', 'a', ''));
        $this->assertSame('a', Layout::joinVerticalWithDivider(0.0, '─', '', 'a'));
    }

    public function testVerticalEmptyDividerIsPlainJoin(): void
    {
        $this->assertSame(Layout::joinVertical(0.5, 'a', 'bcd'), Layout::joinVerticalWithDivider(0.5, '', 'a', 'bcd'));
    }

    public function testTile(): void
    {
        $this->assertSame('-=-', Layout::tile('-=', 3));
        $this->assertSame('', Layout::tile('-', 0));
        $this->assertSame('', Layout::tile('', 4));
        $this->assertSame('ええ', Layout::tile('え', 4));
        $this->assertSame('え', Layout::tile('え', 3));
    }

    public function testClipHeadTail(): void
    {
        $this->assertSame("a\nb", Layout::clipHead("a\nb\nc", 2));
        $this->assertSame("b\nc", Layout::clipTail("a\nb\nc", 2));
        $this->assertSame("a\nb", Layout::clipHead("a\nb", 5));
        $this->assertSame("a\nb", Layout::clipTail("a\nb", 5));
        $this->assertSame('', Layout::clipTail("a\nb", 0));
    }

    public function testClipWidthAnsiAwareAndEllipsis(): void
    {
        $styled = "\e[31mhello world\e[0m";
        $out = Layout::clipWidth($styled, 5);
        $this->assertSame(5, Layout::width($out));
        $this->assertStringContainsString("\e[0m", $out);
        $this->assertSame("hell…\nok", Layout::clipWidth("hello world\nok", 5, '…'));
        $this->assertSame('', Layout::clipWidth('abc', 0));
        $this->assertSame('abc', Layout::clipWidth('abc', 3, '…'));
    }
}
