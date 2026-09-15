<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Gallery\AnsiGuard;
use SugarCraft\Gallery\PosterCard;
use SugarCraft\Sprinkles\Style;

/**
 * The SGR-only rule behind the {@see PosterCard::withStyledTitle()} trust boundary.
 *
 * Everything here is about what the guard lets through and what it stops. The
 * dangerous cases are the ones that must be exhaustive: a styled title that
 * reaches the terminal with a cursor move, an erase, or an OSC payload is a
 * terminal-injection bug in whatever app embeds this widget, so each escape form
 * ECMA-48 defines gets its own fixture rather than being assumed covered.
 */
final class AnsiGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function safeProvider(): array
    {
        return [
            'empty' => [''],
            'plain ascii' => ['Blade Runner'],
            'plain utf-8' => ['因果の物語 — Ångström'],
            'bold + reset' => ["\e[1mBlade\e[0m Runner"],
            'many params' => ["\e[1;4;31;48;5;208mX\e[0m"],
            'sgr sub-params' => ["\e[4:3mundercurl\e[59m"],
            'sgr only' => ["\e[0m"],
            'styled then plain' => ["\e[32mok\e[39m done"],
            'percent and brackets' => ['100% [not-an-escape] (x)'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}> payload + why it is unsafe
     */
    public static function unsafeProvider(): array
    {
        return [
            'cursor up (CSI A)' => ["\e[2Ahi", 'a CSI sequence with a non-SGR final byte'],
            'cursor home' => ["\e[Hhi", 'same'],
            'erase display' => ["hi\e[2J", 'clears the screen from a title cell'],
            'erase line' => ["hi\e[K", 'clears the rest of the row'],
            'set scroll region (private DECSTBM)' => ["\e[?1;2r", 'private-parameter CSI'],
            'hyperlink OSC BEL' => ["\e]8;;https://evil\x07click", 'an OSC 8 hyperlink'],
            'hyperlink OSC ST' => ["\e]8;;https://evil\e\\click", 'the same payload using ST'],
            'window title OSC' => ["\e]0;pwned\e\\", 'OSC rewrites the terminal tab'],
            'sixel DCS' => ["\eP0;1;2q#0;2;0;0;0\e\\", 'a DCS pixel payload'],
            'iterm2 OSC' => ["\e]1337;File=inline=1:AAAA\e\\", 'an iTerm2 inline image'],
            'apc' => ["\e+_never_seen_\e\\", 'APC'],
            'charset designator' => ["\e(Bplain", 'ESC ( selects a character set'],
            'Fe reverse index' => ["\eMscroll-up", 'a two-byte Fe escape'],
            'bell' => ["ring\x07", 'a bare C0 control'],
            'carriage return' => ["line1\rline2", 'would overwrite the row'],
            'newline' => ["row1\nrow2", 'would break the fixed-height cell'],
            'tab' => ["a\tb", 'would move the cursor mid-title'],
            'nul' => ["nu\x00l", 'a bare C0 control'],
            'shift-in' => ["si\x0Ehere", 'C0'],
            'del' => ["de\x7Fl", 'DEL'],
            'lone trailing escape' => ["tail\e", 'an unterminated sequence'],
            'truncated csi' => ["\e[3", 'a CSI with no final byte'],
            'escape before sgr' => ["\e[\e[32mok", 'a malformed prefix smuggling a valid SGR'],
            'C1 CSI (raw 0x9B)' => ["\x9b2J", '8-bit CSI — a terminal acts on this without any ESC'],
            'C1 OSC (raw 0x9D)' => ["\x9d8;;https://evil\x9c", '8-bit OSC string'],
            'C1 DCS (raw 0x90)' => ["\x90q#0;2;0;0;0\x9c", '8-bit DCS — a sixel payload with no ESC'],
            'C1 ST (raw 0x9C)' => ["text\x9c", '8-bit string terminator on its own'],
            'C1 CSI (UTF-8 C2 9B)' => ["\xc2\x9b2J", 'U+009B re-encoded — decodes to 8-bit CSI'],
            'C1 OSC (UTF-8 C2 9D)' => ["\xc2\x9d8;;https://evil\xc2\x9c", 'the OSC-8 hyperlink in UTF-8 form'],
            'C1 APC (UTF-8 C2 9F)' => ["\xc2\x9fsecret", 'U+009F, the last C1 codepoint'],
            'C1 PM (raw 0x9E)' => ["\x9e8;;https://evil\x9c", '8-bit privacy message — ESC ^'],
            'C1 APC (raw 0x9F)' => ["\x9f8;;https://evil\x9c", '8-bit application control — ESC _'],
            'C1 SCI is not a string form' => ["a\x9ab", '0x9A is SCI: it goes, the text behind it stays'],
            'over-long ESC (E0 81 9B)' => ["\xe0\x81\x9b2J", 'a lenient decoder sees ESC here'],
            'surrogate-encoded ESC' => ["\xed\xa0\x9b2J", 'U+D800 cannot be encoded; do not read it as text'],
            'encoded above U+10FFFF' => ["\xf4\x90\x80\x80", 'invalid lead/continuation pairing'],
        ];
    }

    /**
     * Text that must NEVER be mistaken for a control byte, whatever the scanner
     * does to the C1 range: every one of these contains bytes in 0x80–0x9F as
     * ordinary UTF-8 continuation bytes.
     *
     * @return array<string, array{0: string}>
     */
    public static function wideTextProvider(): array
    {
        return [
            'CJK' => ['因果の物語'],
            'accented' => ['Björk Ω≈ç «quota»'],
            'emoji' => ['🎬 Dune: Part Two'],
            'cyrillic' => ['Солярис'],
            'no-break space' => ["Blade\xc2\xa0Runner"],
            'hebrew' => ['מטריקס'],
            'replacement char' => ["\xef\xbf\xbd"],
            'last non-surrogate 3-byte' => ["\xed\x9f\xbf"],
            'last valid 4-byte' => ["\xf4\x8f\xbf\xbf"],
        ];
    }

    /**
     * @dataProvider wideTextProvider
     */
    public function testWideUtf8IsNotMistakenForC1Controls(string $text): void
    {
        // The C1 range overlaps UTF-8 continuation bytes (果 is E6 9E 9C), so a
        // guard that flags raw 0x80–0x9F without parsing sequences would reject
        // half the world's film titles. Whole sequences must be consumed atomically.
        self::assertTrue(AnsiGuard::isSafe($text), json_encode($text) . ' is plain text');
        self::assertSame($text, AnsiGuard::assertSafe($text));
        self::assertSame($text, AnsiGuard::sanitize($text), 'byte-preserving, even where the bytes look like C1');
        self::assertSame($text, AnsiGuard::stripControls($text));
    }

    /**
     * @dataProvider wideTextProvider
     */
    public function testWideUtf8SurvivesBeingStyled(string $text): void
    {
        $styled = "\e[1m" . $text . "\e[0m";

        self::assertTrue(AnsiGuard::isSafe($styled));
        self::assertSame($styled, AnsiGuard::sanitize($styled));
    }

    /**
     * @dataProvider safeProvider
     */
    public function testAcceptsStylingAndText(string $ansi): void
    {
        self::assertTrue(AnsiGuard::isSafe($ansi), 'a safe title must be accepted');
        self::assertSame($ansi, AnsiGuard::assertSafe($ansi), 'assertSafe returns its input unchanged');
        self::assertSame($ansi, PosterCard::assertSafeAnsi($ansi), 'the card delegate behaves identically');
        self::assertSame($ansi, AnsiGuard::sanitize($ansi), 'sanitising a safe string is a byte-preserving no-op');
    }

    /**
     * @dataProvider unsafeProvider
     */
    public function testRejectsEveryOtherEscapeForm(string $ansi, string $why): void
    {
        self::assertFalse(AnsiGuard::isSafe($ansi), $why);

        $thrown = null;
        try {
            AnsiGuard::assertSafe($ansi);
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'assertSafe must fail loud, not silently accept ' . json_encode($ansi));
        self::assertStringContainsString('Unsafe escape sequence at offset', $thrown->getMessage());
        self::assertMatchesRegularExpression('/offset \d+: \[[0-9A-F ]+/', $thrown->getMessage(), 'the message pinpoints the offending bytes');
    }

    /**
     * @dataProvider unsafeProvider
     */
    public function testSanitizeStripsThePayloadAndKeepsTheRest(string $ansi, string $why): void
    {
        $clean = AnsiGuard::sanitize($ansi);

        self::assertTrue(AnsiGuard::isSafe($clean), 'sanitize always yields something acceptable');
        self::assertSame($clean, AnsiGuard::sanitize($clean), 'sanitize is idempotent');
        self::assertLessThanOrEqual(strlen($ansi), strlen($clean), 'sanitize never invents bytes');
    }

    public function testSanitizeKeepsAdjacentStylingAndText(): void
    {
        // The escape is dropped in place; the styling around it survives, so a
        // highlight that merely *contains* one bad sequence is not thrown away.
        $dirty = "\e[1mBlade" . "\e[2J" . "\e[0m Runner";

        self::assertSame("\e[1mBlade\e[0m Runner", AnsiGuard::sanitize($dirty));
    }

    public function testAnUnterminatedOscLosesItsPayloadButNotTheRestOfTheTitle(): void
    {
        // A terminal really does consume up to the next ST/BEL, but the guard's job
        // is to remove the bytes that *act on* the terminal, not to emulate one:
        // the payload is dropped where it provably ends, and the tail is judged on
        // its own bytes instead of disappearing with it.
        self::assertSame('', AnsiGuard::sanitize("\e]8;;https://evil"));
        self::assertSame("\e[32mBlade Runner", AnsiGuard::sanitize("\e]0;evil\e[32mBlade Runner"));
        self::assertSame(
            'host=x',
            AnsiGuard::sanitize("\e]0;evil\e]2;other\x07host=x"),
            'two unterminated payloads in a row, neither leaking its text'
        );
    }

    public function testOffsetReportedPointsAtTheOffendingSequence(): void
    {
        $ansi = "\e[1msafe\e[2J";

        try {
            AnsiGuard::assertSafe($ansi);
            self::fail('expected the erase sequence to be rejected');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('at offset 8:', $e->getMessage(), 'ESC[1m + "safe" = 8 bytes in');
            self::assertStringContainsString('1B 5B 32 4A', $e->getMessage(), 'the offending bytes are hex-dumped');
        }
    }

    public function testStylingProducedByCandySprinklesPassesTheGuard(): void
    {
        // The guard exists to stop untrusted bytes, not to punish this ecosystem's
        // own renderer: whatever candy-sprinkles emits must always be acceptable,
        // across the colour spaces it supports.
        $titles = [
            Style::new()->bold()->render('Blade Runner'),
            Style::new()->fg('#123456')->bg('#fedcba')->render('Dune'),
            Style::new()->underline()->italic()->faint()->reverse()->render('Alien'),
            Style::new()->fg(Color::ansi256(208))->bg(Color::ansi(4))->strikethrough()->blink()->render('Heat'),
            Style::new()->fg(Color::rgb(3, 4, 5))->invisible()->render('Secret'),
        ];

        foreach ($titles as $i => $styled) {
            self::assertTrue(AnsiGuard::isSafe($styled), 'sprinkles output #' . $i . ' must pass: ' . json_encode($styled));
            self::assertSame($styled, AnsiGuard::sanitize($styled));
        }
    }

    public function testSanitizeNeverTakesTheTextAroundASelfTerminatedPayload(): void
    {
        // The provider rows only prove the output is acceptable, idempotent and no
        // longer than the input — a sanitizer that deleted the entire title would
        // satisfy all three. Every payload below is self-terminated, so the text on
        // either side of it must survive verbatim through both entry points.
        $hostile = [
            'erase' => "\e[2J",
            'cursor home then erase' => "\e[H\e[2J",
            'hyperlink' => "\e]8;;https://evil\e\\",
            'window title' => "\e]2;evil\x07",
            'dcs' => "\eP0;1;q1#0\x9c",
            'charset designator' => "\e(B",
            '8-bit csi' => "\xc2\x9b2J",
            '8-bit osc' => "\x9d8;;x\x9c",
            '8-bit apc' => "\x9fprivate\x9c",
            '8-bit pm' => "\x9eprivate\x9c",
            'over-long esc' => "\xe0\x81\x9b2J",
            'bell' => "\x07",
            'nul' => "\x00",
            'del' => "\x7f",
            // Terminated spellings whose "tail survives" guarantee nothing else pins:
            'raw dcs terminated' => "\x90secret\x9c",
            'encoded osc terminated' => "\xc2\x9d8;;x\xc2\x9c",
            'reverse index (Fe)' => "\eM",
            '8-bit SCI single shot' => "\x9a",
            'malformed csi prefix' => "\e[\e[32m",
        ];

        foreach ($hostile as $what => $payload) {
            $dirty = 'before' . $payload . 'after';

            foreach ([AnsiGuard::sanitize($dirty), AnsiGuard::stripControls($dirty)] as $i => $clean) {
                $entry = $i === 0 ? 'sanitize' : 'stripControls';
                self::assertStringContainsString('before', $clean, $entry . ' ate the text before ' . $what);
                self::assertStringContainsString('after', $clean, $entry . ' ate the text after ' . $what);
            }
        }
    }

    /**
     * An invalid 4-byte lead is opaque text and stays; what eats the tail behind it
     * is the stray 0x90 that follows, exactly as a raw DCS control would anywhere
     * else. Pinning the mechanism, so nobody "fixes" the lead handling by making a
     * rejected sequence consume its own continuation bytes — that would turn inert
     * mojibake into a payload eater.
     */
    public function testAnInvalidLongLeadStaysButAControlByteBehindItStillEatsItsPayload(): void
    {
        $dirty = "before\xf4\x90secret\x9cafter";

        self::assertFalse(AnsiGuard::isSafe($dirty));
        self::assertSame("before\xf4after", AnsiGuard::sanitize($dirty), 'the lead survives, the DCS payload does not');

        // A well-formed sequence of the same length — and a lead that is not a lead
        // at all — lose nothing that is not itself a control.
        self::assertSame("before\xf4\x80\x80\x80after", AnsiGuard::sanitize("before\xf4\x80\x80\x80after"), 'a valid 4-byte sequence is text');
        self::assertSame("before\xf5after", AnsiGuard::sanitize("before\xf5\x80\x80\x80after"), 'F5 is no lead; each 0x80 is a stray PAD and only removes itself');
    }

    public function testStripControlsRemovesStylingTooBecauseAPlainTitleCarriesNone(): void
    {
        // sanitize() keeps colour for a styled title; stripControls() is the other
        // boundary — the plain title that promises no escape bytes at all.
        self::assertSame('Blade Runner', AnsiGuard::stripControls("\e[1mBlade Runner\e[0m"));
        self::assertSame('danger', AnsiGuard::stripControls("\x1b[2Jdanger"));
        self::assertSame('Blade', AnsiGuard::stripControls("Blade\xc2\x9b2J"), 'the UTF-8-encoded C1 form');
        self::assertSame('Blade', AnsiGuard::stripControls("Blade\x9b2J"), 'the raw 8-bit C1 form');
        self::assertSame('twowords', AnsiGuard::stripControls("two\twor\x01ds\n"), 'TAB/CR/LF would break the fixed-height cell');
    }

    public function testStripControlsDoesNotFailOpenOnInvalidUtf8(): void
    {
        // The regex this replaced used /u, so a single non-UTF-8 byte made the
        // whole strip no-op and the escape reached the terminal. An opaque byte is
        // not a control: keep it, remove what is.
        $stripped = AnsiGuard::stripControls("\xff\x1b[2J\x07ok");

        self::assertStringNotContainsString("\x1b", $stripped);
        self::assertStringNotContainsString("\x07", $stripped);
        self::assertStringContainsString('ok', $stripped);
        self::assertStringContainsString("\xff", $stripped, 'the unknown byte is mojibake, not a control');
    }

    /**
     * @dataProvider unsafeProvider
     */
    public function testStripControlsAlwaysProducesSomethingTheGuardAccepts(string $ansi, string $why): void
    {
        $plain = AnsiGuard::stripControls($ansi);

        self::assertTrue(AnsiGuard::isSafe($plain), $why . ' left ' . json_encode($plain));
        self::assertSame($plain, AnsiGuard::stripControls($plain), 'idempotent');
        self::assertLessThanOrEqual(strlen($ansi), strlen($plain), 'a sanitizer that grows its input is inventing bytes');
    }
}
