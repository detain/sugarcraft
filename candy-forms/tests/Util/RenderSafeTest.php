<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Util\RenderSafe;

final class RenderSafeTest extends TestCase
{
    public function testCleanPassesThroughOrdinaryText(): void
    {
        $this->assertSame('hello world', RenderSafe::clean('hello world'));
        $this->assertSame('日本語', RenderSafe::clean('日本語'));
        $this->assertSame('Emoji 🎉', RenderSafe::clean('Emoji 🎉'));
    }

    public function testCleanPreservesTabAndNewline(): void
    {
        $this->assertSame("a\tb\nc", RenderSafe::clean("a\tb\nc"));
    }

    public function testCleanStripsC0ControlBytes(): void
    {
        // 0x00-0x08 stripped; TAB (0x09) and LF (0x0A) preserved; 0x0B/0x0C stripped
        $input = 'a' . chr(0x00) . 'b' . chr(0x01) . 'c' . chr(0x0B) . 'd' . chr(0x0C) . 'e' . chr(0x0E) . 'f';
        $this->assertSame('abcdef', RenderSafe::clean($input));
    }

    public function testCleanStripsDel(): void
    {
        // DEL (0x7F) stripped
        $input = 'a' . chr(0x7F) . 'b';
        $this->assertSame('ab', RenderSafe::clean($input));
    }

    public function testCleanPreservesSgrSequences(): void
    {
        // Valid SGR: ESC + '[' + params + 'm'
        $sgr = "\x1b[31m";
        $this->assertSame($sgr, RenderSafe::clean($sgr));

        $sgr2 = "\x1b[1;32m";
        $this->assertSame($sgr2, RenderSafe::clean($sgr2));

        $reset = "\x1b[0m";
        $this->assertSame($reset, RenderSafe::clean($reset));
    }

    public function testCleanStripsBareEsc(): void
    {
        // Lone ESC followed by a non-'[' byte — stripped
        $input = 'a' . chr(0x1B) . 'X' . 'b';
        $this->assertSame('aXb', RenderSafe::clean($input));
    }

    public function testCleanStripsBareEscButNotSgr(): void
    {
        // Mix of bare ESC and SGR — bare ESC + its following byte stripped;
        // SGR sequence (\x1b[33m) is preserved intact; c is kept.
        $bareEsc = 'a' . chr(0x1B) . 'X';  // bare ESC + X
        $sgr     = "\x1b[33m";              // yellow SGR
        $input   = $bareEsc . $sgr . 'c';
        // Expected: aX + SGR preserved (with its ESC) + c
        // Use chr() to guarantee the ESC byte (0x1B) since "\x1b" in the
        // assertion string itself would not be interpreted as ESC.
        $this->assertSame("aX" . chr(0x1B) . "[33m" . "c", RenderSafe::clean($input));
    }

    public function testCleanHandlesEmptyString(): void
    {
        $this->assertSame('', RenderSafe::clean(''));
    }

    public function testCleanHandlesOnlyDangerousBytes(): void
    {
        // All C0 + DEL + bare ESC — all stripped
        $input = chr(0x00) . chr(0x01) . chr(0x7F) . chr(0x1B);
        $this->assertSame('', RenderSafe::clean($input));
    }
}
