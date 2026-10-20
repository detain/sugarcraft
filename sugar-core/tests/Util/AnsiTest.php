<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class AnsiTest extends TestCase
{
    public function testSgrEmitsCsi(): void
    {
        $this->assertSame("\x1b[1;31m", Ansi::sgr(1, 31));
        $this->assertSame("\x1b[m", Ansi::sgr());
        $this->assertSame("\x1b[0m", Ansi::reset());
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
        $this->assertSame("\x1b[1A", Ansi::cursorUp());
        $this->assertSame("\x1b[5B", Ansi::cursorDown(5));
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
        $this->assertSame("\x1b[?1049h", Ansi::altScreenEnter());
        $this->assertSame("\x1b[?1049l", Ansi::altScreenLeave());
        $this->assertSame("\x1b[?2004h", Ansi::bracketedPasteOn());
        $this->assertSame("\x1b[?1000h\x1b[?1006h", Ansi::mouseAllOn());
    }

    public function testEraseHelpers(): void
    {
        $this->assertSame("\x1b[1K", Ansi::eraseToLineStart());
        $this->assertSame("\x1b[0K", Ansi::eraseToLineEnd());
        $this->assertSame("\x1b[1J", Ansi::eraseToScreenStart());
    }

    public function testScrollRegion(): void
    {
        $this->assertSame("\x1b[2;10r", Ansi::setScrollRegion(2, 10));
        $this->assertSame("\x1b[r", Ansi::setScrollRegion(2, 0));
        $this->assertSame("\x1b[r", Ansi::resetScrollRegion());
        $this->assertSame("\x1b[1;5r", Ansi::setScrollRegion(0, 5));
    }

    public function testScrollUpDown(): void
    {
        $this->assertSame("\x1b[1S", Ansi::scrollUp());
        $this->assertSame("\x1b[3S", Ansi::scrollUp(3));
        $this->assertSame("\x1b[1T", Ansi::scrollDown(0));
        $this->assertSame("\x1b[5T", Ansi::scrollDown(5));
    }

    public function testInsertDelete(): void
    {
        $this->assertSame("\x1b[1L", Ansi::insertLine());
        $this->assertSame("\x1b[3L", Ansi::insertLine(3));
        $this->assertSame("\x1b[2M", Ansi::deleteLine(2));
        $this->assertSame("\x1b[1@", Ansi::insertChar());
        $this->assertSame("\x1b[5@", Ansi::insertChar(5));
        $this->assertSame("\x1b[3P", Ansi::deleteChar(3));
        $this->assertSame("\x1b[4b", Ansi::repeatChar(4));
    }

    public function testHyperlinkOpenClose(): void
    {
        // Just the open sequence
        $open = Ansi::hyperlinkOpen('https://example.com');
        $this->assertSame("\x1b]8;;https://example.com\x1b\\", $open);

        // Open with id
        $openWithId = Ansi::hyperlinkOpen('https://example.com', 'my-id');
        $this->assertSame("\x1b]8;id=my-id;https://example.com\x1b\\", $openWithId);

        // Close sequence
        $this->assertSame("\x1b]8;;\x1b\\", Ansi::hyperlinkClose());

        // Composed equals hyperlink()
        $url = 'https://example.com';
        $text = 'click me';
        $id = '42';
        $this->assertSame(
            Ansi::hyperlink($url, $text, $id),
            Ansi::hyperlinkOpen($url, $id) . $text . Ansi::hyperlinkClose(),
        );
        $this->assertSame(
            Ansi::hyperlink($url, $text),
            Ansi::hyperlinkOpen($url) . $text . Ansi::hyperlinkClose(),
        );
    }

    public function testHyperlink(): void
    {
        $expect = "\x1b]8;;https://example.com\x1b\\click me\x1b]8;;\x1b\\";
        $this->assertSame($expect, Ansi::hyperlink('https://example.com', 'click me'));

        $withId = "\x1b]8;id=42;https://example.com\x1b\\click me\x1b]8;;\x1b\\";
        $this->assertSame($withId, Ansi::hyperlink('https://example.com', 'click me', '42'));
    }

    public function testTabStops(): void
    {
        $this->assertSame("\x1b[1I", Ansi::tabForward());
        $this->assertSame("\x1b[3I", Ansi::tabForward(3));
        $this->assertSame("\x1b[2Z", Ansi::tabBackward(2));
        $this->assertSame("\x1bH", Ansi::setTabStop());
        $this->assertSame("\x1b[0g", Ansi::clearTabStop());
        $this->assertSame("\x1b[3g", Ansi::clearAllTabStops());
    }

    public function testScoCursorSave(): void
    {
        $this->assertSame("\x1b[s", Ansi::scoSave());
        $this->assertSame("\x1b[u", Ansi::scoRestore());
        $this->assertSame("\x1b7", Ansi::decsc());
        $this->assertSame("\x1b8", Ansi::decrc());
    }

    public function testDecPrivateModeConstants(): void
    {
        $this->assertSame(1, Ansi::DECCKM);
        $this->assertSame(7, Ansi::DECAWM);
        $this->assertSame(1000, Ansi::MOUSE_NORMAL);
        $this->assertSame(1002, Ansi::MOUSE_BUTTON);
        $this->assertSame(1003, Ansi::MOUSE_ANY);
        $this->assertSame(1006, Ansi::MOUSE_SGR);
        $this->assertSame(1049, Ansi::ALT_SCREEN_BUFFER);
        $this->assertSame(2004, Ansi::BRACKETED_PASTE);
        $this->assertSame(2026, Ansi::SYNCHRONIZED_OUTPUT);
    }

    public function testDecSetDecReset(): void
    {
        $this->assertSame("\x1b[?1h", Ansi::decSet(Ansi::DECCKM));
        $this->assertSame("\x1b[?1l", Ansi::decReset(Ansi::DECCKM));
        $this->assertSame("\x1b[?1049h", Ansi::decSet(Ansi::ALT_SCREEN_BUFFER));
        $this->assertSame("\x1b[?1049l", Ansi::decReset(Ansi::ALT_SCREEN_BUFFER));
        $this->assertSame("\x1b[?1006h", Ansi::decSet(Ansi::MOUSE_SGR));
        $this->assertSame("\x1b[?1006l", Ansi::decReset(Ansi::MOUSE_SGR));
        $this->assertSame("\x1b[?2026h", Ansi::decSet(Ansi::SYNCHRONIZED_OUTPUT));
        $this->assertSame("\x1b[?2026l", Ansi::decReset(Ansi::SYNCHRONIZED_OUTPUT));
    }

    public function testDcsApcPmWrappers(): void
    {
        // DCS: ESC P … ESC \
        $this->assertSame("\x1bPpayload\x1b\\", Ansi::dcs('payload'));
        // APC: ESC _ … ESC \
        $this->assertSame("\x1b_payload\x1b\\", Ansi::apc('payload'));
        // PM:  ESC ^ … ESC \
        $this->assertSame("\x1b^payload\x1b\\", Ansi::pm('payload'));
    }

    public function testDcsWrappersWithEmptyPayload(): void
    {
        $this->assertSame("\x1bP\x1b\\", Ansi::dcs(''));
        $this->assertSame("\x1b_\x1b\\", Ansi::apc(''));
        $this->assertSame("\x1b^\x1b\\", Ansi::pm(''));
    }

    /**
     * F-09: repeat counts on sixel pixel data are capped like chafa's
     * format_schar_reps (chafa/internal/chafa-sixel-renderer.c:218-251) —
     * 255+ splits into `!255` chunks, 4..254 counts, 1..3 bare literals.
     */
    public function testSixelPixelDataSplitsOversizedRepeatCounts(): void
    {
        // Palette index 1 maps to byte '@' (chr(1 + 63)).
        $this->assertSame('@', Ansi::sixelPixelData([1]));
        $this->assertSame('@@', Ansi::sixelPixelData([1], 2), 'runs under 4 repeat as bare bytes');
        $this->assertSame('@@@', Ansi::sixelPixelData([1], 3));
        $this->assertSame('!4@', Ansi::sixelPixelData([1], 4));
        $this->assertSame('!255@!45@', Ansi::sixelPixelData([1], 300), 'a 300-run splits into one !255 chunk plus !45');
        $this->assertSame('!255@!255@', Ansi::sixelPixelData([1], 510), 'an exact multiple leaves no remainder');

        // Roadmap bound pin: every emitted repeat token is a legal 1..255.
        $matches = [];
        $count = preg_match_all('/!\d+/', Ansi::sixelPixelData([0], 1024), $matches);
        $this->assertGreaterThan(0, $count);
        foreach ($matches[0] as $repeat) {
            $this->assertSame(
                1,
                preg_match('/^!([1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/', $repeat),
                'illegal sixel repeat count in ' . $repeat,
            );
        }
    }

    /**
     * F-09 shared home: sixelRle() reproduces the pre-fold inline algorithm
     * for EVERY run length 0..3000 (literal < 4, `!N` for 4..254, `!255`
     * chunks plus remainder above), so routing both wire homes through it is
     * byte-frozen.
     */
    public function testSixelRleMatchesThePreFoldInlineAlgorithmForEveryRunLength(): void
    {
        $reference = static function (int $bits, int $count): string {
            $char = chr(($bits & 0x3F) + 63);
            if ($count < 4) {
                return str_repeat($char, $count);
            }
            $out = '';
            while ($count >= 255) {
                $out .= '!255' . $char;
                $count -= 255;
            }
            if ($count === 0) {
                return $out;
            }
            if ($count < 4) {
                return $out . str_repeat($char, $count);
            }

            return $out . '!' . $count . $char;
        };

        foreach ([0, 1, 0x15, 0x3F] as $bits) {
            $expected = '';
            $actual = '';
            for ($count = 0; $count <= 3000; $count++) {
                $expected .= $reference($bits, $count) . '|';
                $actual .= Ansi::sixelRle($bits, $count) . '|';
            }

            if ($expected !== $actual) {
                for ($count = 0; $count <= 3000; $count++) {
                    $this->assertSame(
                        $reference($bits, $count),
                        Ansi::sixelRle($bits, $count),
                        "sixelRle({$bits}, {$count}) drifted from the pre-fold algorithm",
                    );
                }
            }

            $this->assertSame($expected, $actual, "sixelRle mask {$bits} drifted across runs 0..3000");
        }
    }

    /** F-09: a negative run length is a caller bug — fail loud, never emit. */
    public function testSixelRleRejectsNegativeCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ansi::sixelRle(0, -1);
    }

    /**
     * A-01 dedup pass: the SGR parameter spelling of a pen has one home.
     * The full-sequence helpers, sugar-cellbuffer's SgrEmitter and
     * sugar-image's OutputOptimizer all compose these parameter builders,
     * so the spellings below are the monorepo-wide contract.
     */
    public function testPenParameterSpellingHasOneHome(): void
    {
        $this->assertSame('38;2;1;2;3', Ansi::fgRgbParams(1, 2, 3));
        $this->assertSame('48;2;1;2;3', Ansi::bgRgbParams(1, 2, 3));
        $this->assertSame('38;5;196', Ansi::fg256Params(196));
        $this->assertSame('48;5;196', Ansi::bg256Params(196));

        // Full sequences stay byte-identical to the pre-dedup spellings.
        $this->assertSame("\x1b[38;2;1;2;3m", Ansi::fgRgb(1, 2, 3));
        $this->assertSame("\x1b[48;2;1;2;3m", Ansi::bgRgb(1, 2, 3));
        $this->assertSame("\x1b[38;5;196m", Ansi::fg256(196));
        $this->assertSame("\x1b[48;5;196m", Ansi::bg256(196));

        // Background re-spelling: colour selectors flip 38;→48;, everything
        // else (bare attribute codes) passes through untouched.
        $this->assertSame('48;2;1;2;3', Ansi::backgroundPenParams('38;2;1;2;3'));
        $this->assertSame('48;5;196', Ansi::backgroundPenParams('38;5;196'));
        $this->assertSame('1', Ansi::backgroundPenParams('1'));
        $this->assertSame('39', Ansi::backgroundPenParams('39'));
    }

    /** A-01: the parameter builders keep the byte-range guard of their parents. */
    public function testPenParameterBuildersKeepTheByteRangeGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ansi::fgRgbParams(256, 0, 0);
    }
}
