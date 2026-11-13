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
            "carriage\rreturn\r\nkept\n",
            "eight-bit\x9b[2Jname",
            "unicode é and C1 continuation byte",
        ];
        foreach ($inputs as $in) {
            $once = RenderSafe::clean($in);
            $this->assertSame($once, RenderSafe::clean($once), 'clean(clean(x)) must equal clean(x)');
        }
    }

    /**
     * SF-033: CR (0x0D) sat in the pass-1 class gap between \x0C and
     * \x0E — it overwrites the rendered line back to column 0, forging
     * display content on every cleaned surface. It must be stripped while
     * TAB/LF keep their documented exemption.
     */
    public function testCleanStripsCarriageReturn(): void
    {
        $this->assertSame('abb', RenderSafe::clean("a\rbb"));
        $this->assertSame("a\nb", RenderSafe::clean("a\r\nb"), 'LF survives, CR goes');
        $this->assertSame('', RenderSafe::clean("\r"));
        $this->assertSame(
            "\x1b[1mXY\x1b[0m",
            RenderSafe::clean("\x1b[1mX\rY\x1b[0m"),
            'CR goes even between preserved SGR sequences'
        );
    }

    /**
     * SF-034: scandir delivers raw bytes, so a crafted name can carry a
     * lone 0x9B — 8-bit CSI on terminals that parse it — while the C1
     * exemption let it through untouched. Pass 0 normalization must leave
     * zero raw 0x80-0x9F bytes for non-UTF-8 input.
     */
    public function testCleanRemovesLoneEightBitCsiByte(): void
    {
        $out = RenderSafe::clean("evil\x9b[2Jname");
        $this->assertStringNotContainsString(chr(0x9B), $out, 'raw 8-bit CSI must not survive');

        // C1-range bytes as trailing/leading raw bytes of a non-UTF-8 name.
        foreach ([0x80, 0x85, 0x9B, 0x9D] as $c1) {
            $out = RenderSafe::clean("dir-" . chr($c1) . "-end");
            $this->assertStringNotContainsString(chr($c1), $out, 'byte 0x' . dechex($c1) . ' must not survive raw');
        }
    }

    /**
     * The exemption's good half: inside VALID UTF-8, a 0x80-0xBF byte is a
     * continuation byte of a legal sequence and must never be stripped.
     */
    public function testCleanKeepsC1RangeBytesInsideValidUtf8(): void
    {
        $this->assertSame('ś', RenderSafe::clean('ś'), 'U+015B is C5 9B — 0x9B is a continuation byte here');
        $this->assertSame('é', RenderSafe::clean('é'));
        $wide = '中文字';
        $this->assertSame($wide, RenderSafe::clean($wide));
    }
}
