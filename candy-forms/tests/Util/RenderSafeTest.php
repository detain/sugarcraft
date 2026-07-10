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

    /**
     * Regression: the CSI-followed-by-'m' bypass.
     *
     * `\x1b[2J` is Erase-in-Display (screen clear). The previous pass-2 regex
     * used `\x1b\[[^\x1b]*m`, so ANY CSI sequence followed (eventually) by a
     * literal 'm' before the next ESC was misclassified as a preservable SGR
     * and reached view() with its ESC intact. The tightened `\x1b\[[0-9;]*m`
     * only matches real SGR params, so `\x1b[2Jm` no longer matches alt-1;
     * alt-2 strips the ESC, leaving the harmless printable `[2Jm`.
     *
     * @dataProvider csiBypassProvider
     */
    public function testCleanClosesCsiFollowedByMBypass(string $payload): void
    {
        $out = RenderSafe::clean($payload);
        $this->assertStringNotContainsString(chr(0x1B), $out, 'ESC byte must not survive');
    }

    /** @return array<string, array{0: string}> */
    public static function csiBypassProvider(): array
    {
        return [
            'erase-display + m'            => ["\x1b[2Jm"],
            'text then erase then m'       => ["clear\x1b[2Jform"],
            'erase then word containing m' => ["\x1b[2Jword with m"],
            'cursor-home + m'              => ["\x1b[Hm"],
            'erase-line + m'               => ["\x1b[Km"],
        ];
    }

    public function testCleanErasePayloadRemainderIsPrintable(): void
    {
        // The ESC is dropped; the printable remainder stays (harmless text).
        $this->assertSame('[2Jm', RenderSafe::clean("\x1b[2Jm"));
        $this->assertSame('clear[2Jform', RenderSafe::clean("clear\x1b[2Jform"));
        $this->assertSame('[2Jword with m', RenderSafe::clean("\x1b[2Jword with m"));
    }

    /**
     * True SGR (ESC '[' + digits/';' + 'm') must be preserved byte-exact.
     *
     * @dataProvider sgrProvider
     */
    public function testCleanPreservesTrueSgrByteExact(string $sgr): void
    {
        $this->assertSame($sgr, RenderSafe::clean($sgr));
        $this->assertStringContainsString(chr(0x1B), RenderSafe::clean($sgr));
    }

    /** @return array<string, array{0: string}> */
    public static function sgrProvider(): array
    {
        return [
            'bold + reset'      => ["\x1b[1mBold\x1b[0m"],
            'bold red + reset'  => ["\x1b[1;31mX\x1b[0m"],
            '256-colour + reset'=> ["\x1b[38;5;200mY\x1b[0m"],
            'empty-param reset' => ["\x1b[mZ"],
        ];
    }

    public function testCleanIsIdempotent(): void
    {
        $inputs = [
            "\x1b[2Jm",
            "clear\x1b[2Jform",
            "\x1b[1;31mBold\x1b[0m",
            'a' . chr(0x00) . 'b' . chr(0x1B) . 'X',
            "tab\tnewline\nkept",
        ];
        foreach ($inputs as $in) {
            $once = RenderSafe::clean($in);
            $this->assertSame($once, RenderSafe::clean($once), 'clean(clean(x)) must equal clean(x)');
        }
    }
}
