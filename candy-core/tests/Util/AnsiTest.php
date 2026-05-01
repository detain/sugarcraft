<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Util;

use CandyCore\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class AnsiTest extends TestCase
{
    public function testSgrEmitsCsi(): void
    {
        $this->assertSame("\x1b[1;31m", Ansi::sgr(1, 31));
        $this->assertSame("\x1b[m",     Ansi::sgr());
        $this->assertSame("\x1b[0m",    Ansi::reset());
    }

    public function testFgRgb(): void
    {
        $this->assertSame("\x1b[38;2;255;128;0m", Ansi::fgRgb(255, 128, 0));
    }

    public function testFgRgbRejectsOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ansi::fgRgb(256, 0, 0);
    }

    public function testFg256(): void
    {
        $this->assertSame("\x1b[38;5;42m", Ansi::fg256(42));
    }

    public function testCursorMovement(): void
    {
        $this->assertSame("\x1b[1A",   Ansi::cursorUp());
        $this->assertSame("\x1b[5B",   Ansi::cursorDown(5));
        $this->assertSame("\x1b[3;7H", Ansi::cursorTo(3, 7));
    }

    public function testCursorMovementClampsToOne(): void
    {
        $this->assertSame("\x1b[1A", Ansi::cursorUp(0));
        $this->assertSame("\x1b[1A", Ansi::cursorUp(-3));
    }

    public function testStripCsi(): void
    {
        $s = "\x1b[31mhello\x1b[0m world";
        $this->assertSame('hello world', Ansi::strip($s));
    }

    public function testStripOscBel(): void
    {
        $s = "\x1b]0;title\x07after";
        $this->assertSame('after', Ansi::strip($s));
    }

    public function testStripOscSt(): void
    {
        $s = "\x1b]0;title\x1b\\after";
        $this->assertSame('after', Ansi::strip($s));
    }

    public function testStripPreservesPlainText(): void
    {
        $this->assertSame('hello', Ansi::strip('hello'));
    }

    public function testModeToggles(): void
    {
        $this->assertSame("\x1b[?1049h",                  Ansi::altScreenEnter());
        $this->assertSame("\x1b[?1049l",                  Ansi::altScreenLeave());
        $this->assertSame("\x1b[?2004h",                  Ansi::bracketedPasteOn());
        $this->assertSame("\x1b[?1000h\x1b[?1006h",       Ansi::mouseAllOn());
    }
}
