<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests;

use SugarCraft\Veil\{Position, Veil};
use PHPUnit\Framework\TestCase;

final class VeilTest extends TestCase
{
    private Veil $veil;

    protected function setUp(): void
    {
        $this->veil = Veil::new();
    }

    public function testNew(): void
    {
        $v = Veil::new();
        $this->assertInstanceOf(Veil::class, $v);
    }

    public function testSplitLines(): void
    {
        $lines = $this->veil->splitLines("a\nb\nc");
        $this->assertCount(3, $lines);
        $this->assertSame('a', $lines[0]);
        $this->assertSame('b', $lines[1]);
        $this->assertSame('c', $lines[2]);
    }

    public function testSplitLinesIgnoresTrailingNewline(): void
    {
        $lines = $this->veil->splitLines("a\nb\n");
        $this->assertCount(2, $lines);
    }

    public function testMaxLineWidth(): void
    {
        $lines = ['short', 'medium text', 'tiny'];
        $this->assertSame(11, $this->veil->maxLineWidth($lines));
    }

    public function testMaxLineWidthStripsAnsi(): void
    {
        $lines = ["\x1b[31mred\x1b[0m"];
        $this->assertSame(3, $this->veil->maxLineWidth($lines));
    }

    public function testLineWidth(): void
    {
        $this->assertSame(5, $this->veil->lineWidth('hello'));
    }

    public function testLineWidthWithAnsi(): void
    {
        $this->assertSame(8, $this->veil->lineWidth("\x1b[1m\x1b[38;5;196mbold red\x1b[0m"));
    }

    public function testCompositeCentered(): void
    {
        $bg = "..........\n..........\n..........";
        $fg = "XXX";

        $result = $this->veil->composite($fg, $bg, Position::CENTER, Position::CENTER);

        // FG should appear in the center line
        $this->assertStringContainsString('XXX', $result);
        $this->assertStringContainsString('.', $result); // background preserved
    }

    public function testCompositeTopLeft(): void
    {
        $bg = "..........\n..........";
        $fg = "A";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines = $this->veil->splitLines($result);

        $this->assertStringStartsWith('A', $lines[0]);
    }

    public function testCompositeBottomRight(): void
    {
        $bg = "..........\n..........";
        $fg = "B";

        $result = $this->veil->composite($fg, $bg, Position::BOTTOM, Position::RIGHT);
        $lines = $this->veil->splitLines($result);

        // B should be in last column of last line
        $lastLine = \end($lines);
        $this->assertStringEndsWith('B', \trim($lastLine));
    }

    public function testCompositeWithOffset(): void
    {
        $bg = "..........\n..........";
        $fg = "X";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT, xOffset: 3, yOffset: 1);
        $lines = $this->veil->splitLines($result);

        $this->assertStringStartsWith('...X', $lines[1]);
    }

    public function testCompositeClampStaysInBounds(): void
    {
        // Very large offset should be clamped to visible area
        $bg = "..........\n..........";
        $fg = "T";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT, xOffset: 9999, yOffset: 9999);
        $lines = $this->veil->splitLines($result);

        // Should not crash; should place T somewhere visible
        $this->assertNotEmpty($lines);
    }

    public function testCompositePreservesBackgroundUnaffectedArea(): void
    {
        $bg = "aaaaaaaaaa\naaaaaaaaaa\naaaaaaaaaa";
        $fg = "X";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);

        // Top-left char replaced
        $this->assertStringStartsWith('X', $result);
        // Rest of background preserved
        $this->assertStringContainsString('a', $result);
    }

    public function testCompositeMultiline(): void
    {
        $bg  = "..........\n..........\n..........\n..........";
        $fg  = "AAA\nBBB";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines  = $this->veil->splitLines($result);

        $this->assertStringStartsWith('AAA', $lines[0]);
        $this->assertStringStartsWith('BBB', $lines[1]);
    }

    public function testCompositeReplacesOnlyForegroundCells(): void
    {
        $bg = "abcdefghij";
        $fg = "X";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);

        // X replaces 'a', rest preserved
        $lines = $this->veil->splitLines($result);
        $this->assertSame('Xbcdefghij', $lines[0]);
    }

    public function testEmptyBackground(): void
    {
        $result = $this->veil->composite('X', '', Position::CENTER, Position::CENTER);
        $this->assertSame('', $result);
    }

    public function testEmptyForeground(): void
    {
        $bg = "..........";
        $result = $this->veil->composite('', $bg, Position::TOP, Position::LEFT);
        $this->assertSame($bg, $result);
    }

    public function testPositionYOffset(): void
    {
        $this->assertSame(0,                           Position::TOP->yOffset(5, 20));
        $this->assertSame(15,                          Position::BOTTOM->yOffset(5, 20));
        $this->assertSame(7,                           Position::CENTER->yOffset(6, 20));
    }

    public function testPositionXOffset(): void
    {
        $this->assertSame(0,       Position::LEFT->xOffset(10, 40));
        $this->assertSame(30,      Position::RIGHT->xOffset(10, 40));
        $this->assertSame(15,      Position::CENTER->xOffset(10, 40));
    }
}
