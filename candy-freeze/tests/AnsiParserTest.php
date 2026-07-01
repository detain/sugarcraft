<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\AnsiParser;
use PHPUnit\Framework\TestCase;

final class AnsiParserTest extends TestCase
{
    public function testPlainTextOneSegment(): void
    {
        $segs = AnsiParser::parse('hello');
        $this->assertCount(1, $segs);
        $this->assertSame('hello', $segs[0]->text);
        $this->assertNull($segs[0]->fg);
        $this->assertFalse($segs[0]->bold);
    }

    public function testForegroundChangeProducesSegments(): void
    {
        $segs = AnsiParser::parse("a\x1b[31mb\x1b[0mc");
        $this->assertCount(3, $segs);
        $this->assertSame('a', $segs[0]->text);
        $this->assertNull($segs[0]->fg);
        $this->assertSame('b', $segs[1]->text);
        $this->assertSame('#cd0000', $segs[1]->fg);
        $this->assertSame('c', $segs[2]->text);
        $this->assertNull($segs[2]->fg);
    }

    public function testBoldAttribute(): void
    {
        $segs = AnsiParser::parse("\x1b[1mhi\x1b[22m bye");
        $this->assertSame('hi',   $segs[0]->text);
        $this->assertTrue($segs[0]->bold);
        $this->assertSame(' bye', $segs[1]->text);
        $this->assertFalse($segs[1]->bold);
    }

    public function testTrueColor(): void
    {
        $segs = AnsiParser::parse("\x1b[38;2;255;128;0morange");
        $this->assertSame('#ff8000', $segs[0]->fg);
        $this->assertSame('orange',  $segs[0]->text);
    }

    public function testXterm256Greyscale(): void
    {
        $segs = AnsiParser::parse("\x1b[38;5;232mlast");
        // 232 = grey level 8 → #080808.
        $this->assertSame('#080808', $segs[0]->fg);
    }

    public function testOscPassesThroughSilently(): void
    {
        $segs = AnsiParser::parse("\x1b]0;title\x07hi");
        $this->assertCount(1, $segs);
        $this->assertSame('hi', $segs[0]->text);
    }

    public function testResetClearsFgAndAttrs(): void
    {
        $segs = AnsiParser::parse("\x1b[1;31mhot\x1b[0mcold");
        $this->assertTrue($segs[0]->bold);
        $this->assertSame('#cd0000', $segs[0]->fg);
        $this->assertFalse($segs[1]->bold);
        $this->assertNull($segs[1]->fg);
    }

    public function testMixedXterm256AndTrueColor(): void
    {
        // Red fg (256-color) + green bg (truecolor)
        $segs = AnsiParser::parse("\x1b[38;5;196m\x1b[48;2;0;255;0mcolored\x1b[0m");
        $this->assertCount(1, $segs);
        $this->assertSame('#ff0000', $segs[0]->fg); // ANSI 196 = bright red
        $this->assertSame('#00ff00', $segs[0]->bg); // truecolor 0;255;0
        $this->assertSame('colored', $segs[0]->text);
    }
}
