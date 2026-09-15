<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;

/**
 * Exact-byte tests for the ESC-level reset / alignment / SCS emitters.
 *
 * These sequences are pure wire format, so the contract IS the byte string:
 * every test compares against hand-derived hex rather than a constant of this
 * library, which would make the assertion tautological. The paired consumer
 * side — does a real parser read these bytes back as the intended effect? —
 * lives in candy-vcr/tests/CoreEmitterRoundTripTest.php, because candy-vt
 * depends on this lib and cannot be required back here.
 *
 * @see https://vt100.net/docs/vt510-rm/chapter4.html
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html
 */
final class AnsiResetScsTest extends TestCase
{
    public function testRisEmitsEscC(): void
    {
        // ESC c — ansicode.txt:307. Hex 1b 63, not the SGR reset.
        $this->assertSame("\x1bc", Ansi::ris());
        $this->assertSame('1b63', bin2hex(Ansi::ris()));
    }

    public function testRisIsNotTheSgrReset(): void
    {
        // BC guard: `reset()` keeps its long-standing SGR-0 meaning. RIS is a
        // distinct method precisely because this name was already taken.
        $this->assertSame("\x1b[0m", Ansi::reset());
        $this->assertNotSame(Ansi::reset(), Ansi::ris());
    }

    public function testDecalnEmitsEscIntermediateHashFinalEight(): void
    {
        // ESC # 8 — ansicode.txt:217. Deliberately NOT "\x1b[#8": inside a CSI
        // the 0x38 byte is a parameter, so that spelling would leave the
        // receiver mid-sequence and eat the next byte as its final.
        $this->assertSame("\x1b#8", Ansi::decaln());
        $this->assertSame('1b2338', bin2hex(Ansi::decaln()));
        $this->assertStringStartsWith(Ansi::ESC, Ansi::decaln());
        $this->assertStringNotContainsString(Ansi::CSI, Ansi::decaln());
    }

    public function testShiftOutAndShiftInAreSingleC0Bytes(): void
    {
        // LS1 / LS0 — ansicode.txt:133-134.
        $this->assertSame("\x0e", Ansi::shiftOut());
        $this->assertSame("\x0f", Ansi::shiftIn());
        $this->assertSame("\x0e", Ansi::SO);
        $this->assertSame("\x0f", Ansi::SI);
    }

    /**
     * @return array<string, array{0:int, 1:string, 2:string}>
     */
    public static function slotProvider(): array
    {
        return [
            // slot, intermediate byte, expected wire (ESC + intermediate + '0')
            'G0' => [0, '(', "\x1b(0"],
            'G1' => [1, ')', "\x1b)0"],
            'G2' => [2, '*', "\x1b*0"],
            'G3' => [3, '+', "\x1b+0"],
        ];
    }

    #[DataProvider('slotProvider')]
    public function testScsSelectsTheSlotByIntermediateByte(int $slot, string $intermediate, string $expected): void
    {
        $this->assertSame($expected, Ansi::scs($slot, '0'));
        $this->assertSame(Ansi::ESC . $intermediate . '0', Ansi::scs($slot, '0'));
    }

    public function testScsNamedHelpersDelegateToTheGenericEmitter(): void
    {
        $this->assertSame("\x1b(A", Ansi::scsG0('A'));
        $this->assertSame("\x1b)A", Ansi::scsG1('A'));
        $this->assertSame("\x1b*A", Ansi::scsG2('A'));
        $this->assertSame("\x1b+A", Ansi::scsG3('A'));
        $this->assertSame("\x1b(U", Ansi::scsG0(Ansi::CHARSET_NO_BREAK_SPACE));
    }

    public function testDecSpecialGraphicsConvenienceDesignatesG0(): void
    {
        // ESC ( 0 — the VT100 line-drawing idiom (ansicode.txt:223).
        $this->assertSame("\x1b(0", Ansi::decSpecialGraphics());
        $this->assertSame(Ansi::scsG0(Ansi::CHARSET_DEC_SPECIAL), Ansi::decSpecialGraphics());
    }

    public function testAsciiCharsetConvenienceRestoresG0(): void
    {
        // ESC ( B — ansicode.txt:232 ("(B * USASCII").
        $this->assertSame("\x1b(B", Ansi::asciiCharset());
        $this->assertSame(Ansi::scsG0(Ansi::CHARSET_ASCII), Ansi::asciiCharset());
    }

    public function testDesignatorConstantsSpellTheSetsTheParserModels(): void
    {
        $this->assertSame('B', Ansi::CHARSET_ASCII);
        $this->assertSame('0', Ansi::CHARSET_DEC_SPECIAL);
        $this->assertSame('A', Ansi::CHARSET_UK);
        $this->assertSame('U', Ansi::CHARSET_NO_BREAK_SPACE);
    }

    /**
     * DEC's own private designators live in the 0x30-0x3F band that ordinary
     * escape finals exclude, so they must pass (ansicode.txt:224-231).
     *
     * @return array<string, array{0:string}>
     */
    public static function legalDesignatorProvider(): array
    {
        return [
            'digit zero' => ['0'],
            'alternate ROM' => ['1'],
            'supplemental graphics' => ['<'],
            'UK' => ['A'],
            'US ASCII' => ['B'],
            'last legal byte' => ['~'],
        ];
    }

    #[DataProvider('legalDesignatorProvider')]
    public function testAcceptsDesignatorsAcrossTheWholeFpRange(string $designator): void
    {
        $this->assertSame("\x1b(" . $designator, Ansi::scsG0($designator));
    }

    /**
     * @return array<string, array{0:int, 1:string}>
     */
    public static function illegalDesignatorProvider(): array
    {
        return [
            'empty' => [0, ''],
            'two bytes' => [0, '0B'],
            'C0 escape (would abort the sequence)' => [0, "\x1b"],
            'space (is an intermediate, not a final)' => [0, ' '],
            'DEL' => [0, "\x7f"],
            '8-bit byte' => [1, "\xc2\xa0"],
        ];
    }

    #[DataProvider('illegalDesignatorProvider')]
    public function testRejectsDesignatorsOutsideFp(int $slot, string $designator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SCS designator/');
        Ansi::scs($slot, $designator);
    }

    /**
     * @return array<string, array{0:int}>
     */
    public static function illegalSlotProvider(): array
    {
        return [
            'four' => [4],
            'negative' => [-1],
            'far out' => [99],
        ];
    }

    #[DataProvider('illegalSlotProvider')]
    public function testRejectsSlotOutsideG0ToG3(int $slot): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SCS slot/');
        Ansi::scs($slot, '0');
    }

    public function testNewEmittersComposeIntoAFrameWithoutLeavingSequenceBytes(): void
    {
        // The emitters must interleave with existing ones and still be
        // recognised as escapes by strip(). strip() is documented to drop the
        // introducer and leave charset/Fe tails as inert text, so the durable
        // invariant is "the graphic that follows survives" — never that a
        // particular tail byte is or isn't removed.
        $frame = Ansi::decSpecialGraphics() . 'lqqqqk' . Ansi::asciiCharset()
            . ' box ' . Ansi::decaln() . 'X' . Ansi::ris() . 'Z';
        $stripped = Ansi::strip($frame);
        $this->assertStringEndsWith('Z', $stripped);
        $this->assertStringContainsString('lqqqqk', $stripped);
        $this->assertStringContainsString(' box ', $stripped);
        $this->assertStringNotContainsString(Ansi::ESC, $stripped);
    }
}
