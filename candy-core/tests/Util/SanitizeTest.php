<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Sanitize;

/**
 * Byte-exact coverage for the canonical text sanitizer.
 *
 * Every assertion pins the raw output bytes so the three policies stay
 * distinguishable: {@see Sanitize::controlChars()} (C0 strip, ESC dropped),
 * {@see Sanitize::cellValue()} (glyph replacement + UTF-8 repair), and
 * {@see Sanitize::untrusted()} (full ANSI strip + lone-C1 byte scan).
 */
final class SanitizeTest extends TestCase
{
    /** · U+00B7 MIDDLE DOT — cellValue's control-byte stand-in. */
    private const DOT = "\xC2\xB7";
    /** ↵ U+21B5 — cellValue's collapsed-newline glyph. */
    private const GLYPH = "\xE2\x86\xB5";
    /** � U+FFFD REPLACEMENT CHARACTER — cellValue's invalid-UTF-8 marker. */
    private const FFFD = "\xEF\xBF\xBD";

    // ---- controlChars -----------------------------------------------------

    public function testControlCharsReplacesNewlinesAndTabWithSpace(): void
    {
        $this->assertSame('a b c d', Sanitize::controlChars("a\nb\rc\td"));
    }

    public function testControlCharsStripsC0AndEscInsteadOfPreservingIt(): void
    {
        // The \x0e-\x1f range in the regex includes ESC (0x1b), so the ESC
        // control byte AND BEL (0x07) are removed; only the printable "[31m"
        // parameter text survives. Guards the method's ACTUAL behavior.
        $this->assertSame('a[31mb', Sanitize::controlChars("a\x1b[31mb\x07"));
    }

    public function testControlCharsLeavesCleanTextUnchanged(): void
    {
        $this->assertSame('hello world', Sanitize::controlChars('hello world'));
    }

    public function testControlCharsEmptyString(): void
    {
        $this->assertSame('', Sanitize::controlChars(''));
    }

    public function testControlCharsRemovesEscapeAndKeepsInertParameterTextByteForByte(): void
    {
        // Hex-level pin so a regex edit cannot drift silently: input
        // "a ESC [ 3 1 m b BEL c 0x80 d" -> ESC (0x1b) and BEL (0x07) gone, the
        // printable "[31m" survives, and the raw C1 byte 0x80 is left alone.
        // This assertion is the one that contradicts the historical docblock
        // claim that "ESC is preserved for SGR sequences" — it is NOT.
        $input = "\x61\x1b\x5b\x33\x31\x6d\x62\x07\x63\x80\x64";
        $this->assertSame(
            '615b33316d62638064',
            bin2hex(Sanitize::controlChars($input)),
            'controlChars() must delete ESC, not preserve it for SGR.',
        );
    }

    public function testControlCharsStripsEscapeSoNoSequenceSurvives(): void
    {
        // Every escape-bearing hostile input must come back ESC-free: with the
        // introducer gone, no 7-bit (ESC-introduced) CSI/OSC/DCS/SGR sequence
        // can be reassembled by the terminal, whatever the printable residue
        // looks like. 8-bit C1 forms have no introducer to lose — see
        // testControlCharsLeavesC1ControlsUntouchedPinningTheCurrentScopeBoundary.
        $hostile = [
            'CSI colour'      => "a\x1b[31mb",
            'SGR reset'       => "a\x1b[1;31;40mb\x1b[0m",
            'OSC title'       => "a\x1b]0;pwned\x07b",
            'DCS payload'     => "a\x1bPq;evil\x1b\\b",
            'CSI private mode' => "a\x1b[?2004hb",
            'lone ESC'        => "\x1b",
            'ESC + [ only'    => "\x1b[",
        ];

        foreach ($hostile as $label => $input) {
            $out = Sanitize::controlChars($input);
            $this->assertStringNotContainsString(
                "\x1b",
                $out,
                'escape introducer survived for ' . $label . ': ' . bin2hex($input),
            );
            $this->assertSame(
                Sanitize::controlChars($out),
                $out,
                'controlChars() is not idempotent for ' . $label,
            );
        }

        // Byte-exact expectations for the two shapes a TUI label most often meets.
        $this->assertSame('615b33316d62', bin2hex(Sanitize::controlChars("a\x1b[31mb")));
        $this->assertSame('a[1;31;40mb[0m', Sanitize::controlChars("a\x1b[1;31;40mb\x1b[0m"));
        $this->assertSame('', Sanitize::controlChars("\x1b"));
    }

    public function testControlCharsFoldsEachNewlineCarriageReturnAndTabToExactlyOneSpace(): void
    {
        // One input control byte = one 0x20 output byte (they are substituted,
        // not collapsed): "a\nb\rc\td" -> "a b c d", "a\n\nb" -> two spaces.
        $this->assertSame('61206220632064', bin2hex(Sanitize::controlChars("a\nb\rc\td")));
        $this->assertSame('61202062', bin2hex(Sanitize::controlChars("a\n\nb")));
        $this->assertSame('61202062', bin2hex(Sanitize::controlChars("a\r\rb")));
        $this->assertSame('61202062', bin2hex(Sanitize::controlChars("a\t\tb")));
        // CRLF is two bytes, so it folds to two spaces — a caller wanting one
        // line break per end-of-line must normalize before calling.
        $this->assertSame('61202062', bin2hex(Sanitize::controlChars("a\r\nb")));
    }

    public function testControlCharsRemovesEveryRemainingC0Control(): void
    {
        // Full sweep of the deleted range: \x00-\x08, \x0b, \x0c, \x0e-\x1f
        // (NUL, BS, BEL, VT, FF, SO .. US included) vanish with no stand-in.
        $removed = [0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x0b, 0x0c];
        for ($byte = 0x0e; $byte <= 0x1f; ++$byte) {
            $removed[] = $byte;
        }

        $input = '';
        foreach ($removed as $byte) {
            $input .= chr($byte) . 'x';
        }
        $this->assertSame(str_repeat('x', count($removed)), Sanitize::controlChars($input));

        // Named spot-checks for the bytes that historically caused the noise.
        $this->assertSame('ab', Sanitize::controlChars("a\x00b"));
        $this->assertSame('ab', Sanitize::controlChars("a\x07b"));
        $this->assertSame('ab', Sanitize::controlChars("a\x08b"));
        $this->assertSame('ab', Sanitize::controlChars("a\x0bb"));
        $this->assertSame('ab', Sanitize::controlChars("a\x0cb"));
        $this->assertSame('ab', Sanitize::controlChars("a\x1fb"));
    }

    public function testControlCharsLeavesDelUntouchedPinningTheCurrentScopeBoundary(): void
    {
        // Pinned AS-IS: 0x7f is outside the C0 class, so it survives. DEL is
        // erased by the terminal on some emulations rather than by us, which
        // is exactly why callers needing it gone use untrusted() or
        // cellValue(). Do not "fix" this assertion — fix the caller's policy.
        $this->assertSame('617f62', bin2hex(Sanitize::controlChars("a\x7fb")));
    }

    public function testControlCharsLeavesC1ControlsUntouchedPinningTheCurrentScopeBoundary(): void
    {
        // Pinned AS-IS: raw 0x80-0x9f bytes are outside this method's range, so
        // both the 8-bit C1 controls and the UTF-8 encodings of the same code
        // points pass through byte-for-byte. A caller receiving C1 from an
        // external process must use untrusted() (lone-C1 aware) or cellValue().
        $this->assertSame('618062', bin2hex(Sanitize::controlChars("a\x80b")));
        $this->assertSame('619b62', bin2hex(Sanitize::controlChars("a\x9bb")));
        $this->assertSame('619f62', bin2hex(Sanitize::controlChars("a\x9fb")));
        $this->assertSame('61c28062', bin2hex(Sanitize::controlChars("a\xC2\x80b")));
        $this->assertSame('61c29b62', bin2hex(Sanitize::controlChars("a\xC2\x9bb")));
    }

    public function testControlCharsReducesPureControlInputToEmptyStringOrSpaces(): void
    {
        $this->assertSame('', Sanitize::controlChars("\x01\x02\x07\x1b\x1f"));
        $this->assertSame('', Sanitize::controlChars("\x00\x0b\x0c"));
        // Contrast: \n \r \t are SUBSTITUTED rather than deleted, so control-only
        // input of that kind yields spaces, not an empty string.
        $this->assertSame('   ', Sanitize::controlChars("\n\r\t"));
    }

    public function testControlCharsPassesPrintableAsciiAndUtf8MultibyteTextThroughUnchanged(): void
    {
        // Nothing outside the C0 block is rewritten: no width maths, no
        // transliteration, no mangling of multibyte sequences.
        $this->assertSame(
            'hello world',
            Sanitize::controlChars('hello world'),
        );
        $printable = '';
        for ($byte = 0x20; $byte <= 0x7e; ++$byte) {
            $printable .= chr($byte);
        }
        $this->assertSame($printable, Sanitize::controlChars($printable));

        $multibyte = "h\xC3\xA9llo \xE2\x80\x94 \xE6\x97\xA5\xE6\x9C\xAC\xE8\xAA\x9E \xE2\x9C\x93 \xF0\x9F\x8E\x81";
        $this->assertSame(bin2hex($multibyte), bin2hex(Sanitize::controlChars($multibyte)));
    }

    // ---- cellValue --------------------------------------------------------

    public function testCellValuePassesCleanAsciiThrough(): void
    {
        $this->assertSame('hello', Sanitize::cellValue('hello'));
    }

    public function testCellValueCollapsesEveryNewlineVariantToGlyphSingleLine(): void
    {
        $this->assertSame(
            'a' . self::GLYPH . 'b' . self::GLYPH . 'c' . self::GLYPH . 'd',
            Sanitize::cellValue("a\nb\r\nc\rd"),
        );
    }

    public function testCellValuePreserveNewlinesNormalizesCrlfAndCrToLf(): void
    {
        $this->assertSame("a\nb\nc\nd", Sanitize::cellValue("a\nb\r\nc\rd", true));
    }

    public function testCellValueReplacesTabWithDotInBothModes(): void
    {
        // TAB (0x09) is neutralized — a raw tab misaligns grid columns.
        $this->assertSame('a' . self::DOT . 'b', Sanitize::cellValue("a\tb"));
        $this->assertSame('a' . self::DOT . 'b', Sanitize::cellValue("a\tb", true));
    }

    public function testCellValueReplacesDelWithDot(): void
    {
        $this->assertSame('a' . self::DOT . 'b', Sanitize::cellValue("a\x7fb"));
    }

    public function testCellValueReplacesMixedC0WithDots(): void
    {
        // NUL, ESC (0x1b) and BEL (0x07) each become one dot.
        $this->assertSame(
            'a' . self::DOT . self::DOT . self::DOT . 'b',
            Sanitize::cellValue("a\x00\x1b\x07b"),
        );
    }

    public function testCellValueReplacesValidC1CodePointsWithDot(): void
    {
        // U+0080 and U+009F are well-formed UTF-8 (\xC2\x80 / \xC2\x9F) and
        // survive the C0 sweep, so the dedicated /u C1 sweep neutralizes them.
        $this->assertSame('a' . self::DOT . 'b', Sanitize::cellValue("a\xC2\x80b"));
        $this->assertSame('a' . self::DOT . 'b', Sanitize::cellValue("a\xC2\x9Fb"));
    }

    public function testCellValueRepairsInvalidUtf8WithReplacementCharNotDropping(): void
    {
        // cellValue uses the mb-substitution path (U+FFFD marker), NOT the
        // iconv//IGNORE drop path — so invalid bytes leave a visible marker.
        $this->assertSame('a' . self::FFFD . 'b', Sanitize::cellValue("a\xFFb"));
        // A lone 0x80 is invalid UTF-8, so it is repaired to U+FFFD in step 1
        // and never reaches the C1 sweep.
        $this->assertSame('a' . self::FFFD . 'b', Sanitize::cellValue("a\x80b"));
        // Prove it is substitution, not the iconv-drop that would yield "ab".
        $this->assertNotSame('ab', Sanitize::cellValue("a\xFFb"));
    }

    public function testCellValueEmptyString(): void
    {
        $this->assertSame('', Sanitize::cellValue(''));
        $this->assertSame('', Sanitize::cellValue('', true));
    }

    public function testCellValueIsByteEquivalentToCandyQuerySanitize(): void
    {
        // Reimplements candy-query CellValue::sanitize() verbatim; cellValue(_,
        // false) must match it byte-for-byte so candy-query can later delegate
        // with zero output change.
        $candyQuerySanitize = static function (string $s): string {
            if (!mb_check_encoding($s, 'UTF-8')) {
                $prev = mb_substitute_character();
                mb_substitute_character(0xFFFD);
                $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
                mb_substitute_character($prev);
            }
            $s = str_replace(["\r\n", "\r", "\n"], "\xE2\x86\xB5", $s);
            $s = preg_replace('/[\x00-\x1F\x7F]/', "\xC2\xB7", $s) ?? $s;
            $s = preg_replace('/[\x{0080}-\x{009F}]/u', "\xC2\xB7", $s) ?? $s;

            return $s;
        };

        $inputs = [
            'plain',
            "line1\nline2",
            "crlf\r\nmix\r",
            "tab\tsep",
            "ctrl\x00\x1b\x07here",
            "del\x7fhere",
            "c1\xC2\x85here",
            "binary\xFF\xFEblob",
            "loneC1\x80\x9ftail",
            '',
        ];
        foreach ($inputs as $in) {
            $this->assertSame(
                $candyQuerySanitize($in),
                Sanitize::cellValue($in),
                'cellValue diverged from candy-query sanitize() for: ' . bin2hex($in),
            );
        }
    }

    // ---- untrusted --------------------------------------------------------

    public function testUntrustedStripsSgrSequences(): void
    {
        $this->assertSame('red', Sanitize::untrusted("\x1b[31mred\x1b[0m"));
    }

    public function testUntrustedStripsInlineCsiOscAndLoneEsc(): void
    {
        $this->assertSame('ab', Sanitize::untrusted("a\x1b[31mb"));
        $this->assertSame('ab', Sanitize::untrusted("a\x1b]0;title\x07b"));
        $this->assertSame('ab', Sanitize::untrusted("a\x1bb"));
    }

    public function testUntrustedStripsC0AndDelButPreservesTabAndLf(): void
    {
        $this->assertSame('ab', Sanitize::untrusted("a\x00\x07\x1fb"));
        $this->assertSame('ab', Sanitize::untrusted("a\x7fb"));
        // TAB (0x09) and LF (0x0a) are explicitly preserved by untrusted().
        $this->assertSame("a\tb\nc", Sanitize::untrusted("a\tb\nc"));
    }

    public function testUntrustedStripsLoneC1Bytes(): void
    {
        // A lone 0x80 (PAD) with an ASCII predecessor is a single-byte
        // malformed C1 control: removed, following text survives. A lone
        // 0x9C (ST) likewise. (ANSI audit defect #9: before the fix, ANY
        // 0x80-0x9F byte made the /u preg fail and the `?? $stripped`
        // fallback let every C0 control — BEL included — pass through.)
        $this->assertSame('ab', Sanitize::untrusted("a\x80b"));
        $this->assertSame('ab', Sanitize::untrusted("a\x9cb"));
        // The remaining C1 bytes are 8-bit introducers: ECMA-48 makes them
        // string/sequence openers, and an unterminated one runs to end of
        // input — fail-closed, the trailing text is payload, not survivors.
        $this->assertSame('a', Sanitize::untrusted("a\x9fb"));   // APC
        $this->assertSame('a', Sanitize::untrusted("a\x9db"));   // OSC
        $this->assertSame('a', Sanitize::untrusted("a\x90b"));   // DCS
        $this->assertSame('a', Sanitize::untrusted("a\x9bb"));   // CSI
    }

    public function testUntrustedPreservesValidUtf8IncludingC1CodePoints(): void
    {
        // Unlike cellValue, untrusted only strips LONE C1 bytes: a well-formed
        // U+0080 (\xC2\x80) and a 3-byte arrow (whose continuation bytes fall in
        // the C1 numeric range) are both kept intact.
        $this->assertSame("a\xC2\x80b", Sanitize::untrusted("a\xC2\x80b"));
        $this->assertSame("a\xE2\x86\x92b", Sanitize::untrusted("a\xE2\x86\x92b"));
    }

    public function testUntrustedEmptyString(): void
    {
        $this->assertSame('', Sanitize::untrusted(''));
    }

    // ---- cross-policy contrast -------------------------------------------

    public function testControlCharsAndUntrustedTreatSgrDifferently(): void
    {
        // controlChars drops only the ESC byte (leaving "[31m" text); untrusted
        // removes the entire escape sequence.
        $this->assertSame('a[31mb', Sanitize::controlChars("a\x1b[31mb"));
        $this->assertSame('ab', Sanitize::untrusted("a\x1b[31mb"));
    }
}
