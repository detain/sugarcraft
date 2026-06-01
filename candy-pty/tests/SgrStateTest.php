<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use SugarCraft\Pty\Output\SgrState;
use PHPUnit\Framework\TestCase;

final class SgrStateTest extends TestCase
{
    public function testColorConstants(): void
    {
        $this->assertSame(0, SgrState::COLOR_BLACK);
        $this->assertSame(1, SgrState::COLOR_RED);
        $this->assertSame(2, SgrState::COLOR_GREEN);
        $this->assertSame(3, SgrState::COLOR_YELLOW);
        $this->assertSame(4, SgrState::COLOR_BLUE);
        $this->assertSame(5, SgrState::COLOR_MAGENTA);
        $this->assertSame(6, SgrState::COLOR_CYAN);
        $this->assertSame(7, SgrState::COLOR_WHITE);
        $this->assertSame(9, SgrState::COLOR_DEFAULT);
        $this->assertSame(-1, SgrState::COLOR_256);
        $this->assertSame(-2, SgrState::COLOR_RGB);
        $this->assertSame(-3, SgrState::COLOR_DEFAULT_256);
    }

    public function testDefaultState(): void
    {
        $s = new SgrState();
        $this->assertSame(SgrState::COLOR_DEFAULT, $s->foreground);
        $this->assertSame(SgrState::COLOR_DEFAULT, $s->background);
        $this->assertFalse($s->bold);
        $this->assertFalse($s->italic);
        $this->assertFalse($s->underline);
        $this->assertFalse($s->reverse);
        $this->assertFalse($s->strike);
        $this->assertFalse($s->dim);
        $this->assertFalse($s->invisible);
        $this->assertFalse($s->blink);
        $this->assertSame(SgrState::COLOR_256, $s->foreground256);
        $this->assertSame(SgrState::COLOR_256, $s->background256);
        $this->assertSame(0, $s->foregroundRgb);
        $this->assertSame(0, $s->backgroundRgb);
    }

    public function testEqualsIdenticalStates(): void
    {
        $a = new SgrState();
        $b = new SgrState();
        $this->assertTrue($a->equals($b));
    }

    public function testEqualsDifferentForeground(): void
    {
        $a = new SgrState();
        $b = new SgrState(foreground: SgrState::COLOR_RED);
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentBackground(): void
    {
        $a = new SgrState();
        $b = new SgrState(background: SgrState::COLOR_BLUE);
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentBold(): void
    {
        $a = new SgrState();
        $b = new SgrState(bold: true);
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsAllAttributes(): void
    {
        $a = new SgrState(
            foreground: SgrState::COLOR_RED,
            background: SgrState::COLOR_BLUE,
            bold: true,
            italic: true,
            underline: true,
            reverse: true,
            strike: true,
            dim: true,
            invisible: true,
            blink: true,
            foreground256: 196,
            background256: 21,
            foregroundRgb: 0xFF8000,
            backgroundRgb: 0x0080FF,
        );
        $b = new SgrState(
            foreground: SgrState::COLOR_RED,
            background: SgrState::COLOR_BLUE,
            bold: true,
            italic: true,
            underline: true,
            reverse: true,
            strike: true,
            dim: true,
            invisible: true,
            blink: true,
            foreground256: 196,
            background256: 21,
            foregroundRgb: 0xFF8000,
            backgroundRgb: 0x0080FF,
        );
        $this->assertTrue($a->equals($b));
    }

    public function testEqualsDifferentForeground256(): void
    {
        $a = new SgrState(foreground256: 196);
        $b = new SgrState(foreground256: 21);
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsDifferentForegroundRgb(): void
    {
        $a = new SgrState(foregroundRgb: 0xFF0000);
        $b = new SgrState(foregroundRgb: 0x00FF00);
        $this->assertFalse($a->equals($b));
    }

    public function testDescribeDefault(): void
    {
        $this->assertSame('default', (new SgrState())->describe());
    }

    public function testDescribeBold(): void
    {
        $this->assertSame('bold', (new SgrState(bold: true))->describe());
    }

    public function testDescribeItalic(): void
    {
        $this->assertSame('italic', (new SgrState(italic: true))->describe());
    }

    public function testDescribeUnderline(): void
    {
        $this->assertSame('underline', (new SgrState(underline: true))->describe());
    }

    public function testDescribeReverse(): void
    {
        $this->assertSame('reverse', (new SgrState(reverse: true))->describe());
    }

    public function testDescribeStrike(): void
    {
        $this->assertSame('strike', (new SgrState(strike: true))->describe());
    }

    public function testDescribeDim(): void
    {
        $this->assertSame('dim', (new SgrState(dim: true))->describe());
    }

    public function testDescribeInvisible(): void
    {
        $this->assertSame('invisible', (new SgrState(invisible: true))->describe());
    }

    public function testDescribeBlink(): void
    {
        $this->assertSame('blink', (new SgrState(blink: true))->describe());
    }

    public function testDescribeStandardForegroundColor(): void
    {
        $this->assertSame('fg=red', (new SgrState(foreground: SgrState::COLOR_RED))->describe());
        $this->assertSame('fg=green', (new SgrState(foreground: SgrState::COLOR_GREEN))->describe());
        $this->assertSame('fg=yellow', (new SgrState(foreground: SgrState::COLOR_YELLOW))->describe());
        $this->assertSame('fg=blue', (new SgrState(foreground: SgrState::COLOR_BLUE))->describe());
    }

    public function testDescribeStandardBackgroundColor(): void
    {
        $this->assertSame('bg=magenta', (new SgrState(background: SgrState::COLOR_MAGENTA))->describe());
        $this->assertSame('bg=cyan', (new SgrState(background: SgrState::COLOR_CYAN))->describe());
    }

    public function testDescribeForeground256Color(): void
    {
        $this->assertSame('fg=196', (new SgrState(foreground256: 196))->describe());
    }

    public function testDescribeForegroundRgbColor(): void
    {
        $this->assertSame(
            'fg=rgb(255,128,0)',
            (new SgrState(foregroundRgb: (255 << 16) | (128 << 8) | 0))->describe(),
        );
    }

    public function testDescribeCombined(): void
    {
        $this->assertSame(
            'bold italic underline reverse strike dim blink invisible fg=red bg=blue',
            (new SgrState(
                bold: true,
                italic: true,
                underline: true,
                reverse: true,
                strike: true,
                dim: true,
                blink: true,
                invisible: true,
                foreground: SgrState::COLOR_RED,
                background: SgrState::COLOR_BLUE,
            ))->describe(),
        );
    }
}
