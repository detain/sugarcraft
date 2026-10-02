<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\Util\Sanitize;

/**
 * Byte-exact coverage for the canonical text sanitizer.
 *
 * Every assertion pins the raw output bytes so the three policies stay
 * distinguishable: {@see Sanitize::controlChars()} (C0 strip, ESC dropped),
 * {@see Sanitize::cellValue()} (glyph replacement + UTF-8 repair), and
 * {@see Sanitize::untrusted()} (full ANSI strip + lone-C1 byte scan + UTF-8 C1
 * codepoint sweep).
 */
final class SanitizeTest extends TestCase
{
    /** · U+00B7 MIDDLE DOT — cellValue's control-byte stand-in. */
    private const DOT = "\xC2\xB7";
    /** ↵ U+21B5 — cellValue's collapsed-newline glyph. */
    private const GLYPH = "\xE2\x86\xB5";
    /** � U+FFFD REPLACEMENT CHARACTER — cellValue's invalid-UTF-8 marker. */
    private const FFFD = "\xEF\xBF\xBD";

    /** U+E000 — candy-mouse's zone-OPEN sentinel (ImageOverlay starts at U+E002, disjoint). */
    private const SENTINEL_OPEN = "\xEE\x80\x80";
    /** U+E001 — candy-mouse's zone-CLOSE sentinel (never an image marker). */
    private const SENTINEL_CLOSE = "\xEE\x80\x81";
    /** U+E002 — the first ImageOverlay marker (id 0); NOT a zone sentinel. */
    private const IMAGE_MARKER = "\xEE\x80\x82";
    /** U+F8FF — top of the BMP Private Use Area. */
    private const PUA_LAST = "\xEF\xA3\xBF";
    /** U+F0000 — supplementary-plane private use, where Nerd Fonts live. */
    private const ASTRAL_PUA = "\xF3\xB0\x80\x80";

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

    public function testUntrustedStripsUtf8EncodedC1CodePointsButKeepsValidText(): void
    {
        // Audit 15b-08: a well-formed U+0080 (\xC2\x80) is still a C1 control —
        // xterm decodes it to the codepoint and executes it — so untrusted()
        // drops it like its raw 8-bit spelling. Valid text whose bytes merely
        // overlap the C1 numeric range must survive: the 3-byte arrow (\x86
        // continuation), U+00A0 NBSP (\xC2 lead, first non-C1 codepoint) and a
        // 4-byte emoji (\x9F / \x98 continuations).
        $this->assertSame('ab', Sanitize::untrusted("a\xC2\x80b"));
        $this->assertSame("a\xE2\x86\x92b", Sanitize::untrusted("a\xE2\x86\x92b"));
        $this->assertSame("a\xC2\xA0b", Sanitize::untrusted("a\xC2\xA0b"));
        $this->assertSame("a\xF0\x9F\x98\x80b", Sanitize::untrusted("a\xF0\x9F\x98\x80b"));
        $this->assertSame(
            "\xE2\x86\x92\xC2\xA0\xF0\x9F\x98\x80",
            Sanitize::untrusted("\xE2\x86\x92\xC2\x9B\xC2\xA0\xC2\x9D\xF0\x9F\x98\x80"),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function utf8C1CodePoints(): array
    {
        return [
            'U+009B CSI' => ["\u{9b}"],
            'U+009D OSC' => ["\u{9d}"],
            'U+0090 DCS' => ["\u{90}"],
            'U+0085 NEL' => ["\u{85}"],
            'U+0080 PAD' => ["\u{80}"],
            'U+009F APC' => ["\u{9f}"],
        ];
    }

    #[DataProvider('utf8C1CodePoints')]
    public function testUntrustedStripsUtf8EncodedC1Introducer(string $c1): void
    {
        // Only the introducer goes: without it the `2J` tail is inert text.
        $this->assertSame(2, strlen($c1));
        $this->assertSame('a2Jb', Sanitize::untrusted("a{$c1}2Jb"));
    }

    #[DataProvider('utf8C1CodePoints')]
    public function testUntrustedForMarkedFramesStripsUtf8EncodedC1Introducer(string $c1): void
    {
        $this->assertSame('a2Jb', Sanitize::untrustedForMarkedFrames("a{$c1}2Jb"));
    }

    public function testUntrustedC1SweepSurvivesInvalidUtf8ElsewhereInTheString(): void
    {
        // A /u sweep would fail outright on the stray \xFF / truncated \xE2
        // and hand the C1 codepoint back; the byte sweep must not.
        $out = Sanitize::untrusted("\xFFa\u{9b}2J\xE2b\u{9d}0;x\u{9c}c");
        $this->assertStringNotContainsString("\xC2\x9B", $out);
        $this->assertStringNotContainsString("\xC2\x9D", $out);
        $this->assertStringNotContainsString("\xC2\x9C", $out);
        $this->assertSame("\xFFa2J\xE2b0;xc", $out);
    }

    public function testUntrustedC1SweepCannotSpliceANewC1Pair(): void
    {
        // A stray \xC2 lead in front of a removed C1 pair must not join a
        // following byte into a fresh \xC2[\x80-\x9F] control.
        $out = Sanitize::untrusted("\xC2\xC2\x9B\x9B2J");
        $this->assertDoesNotMatchRegularExpression('/\xC2[\x80-\x9F]/', $out);
        $out = Sanitize::untrusted("\xC2\x07\x9B2J");
        $this->assertDoesNotMatchRegularExpression('/\xC2[\x80-\x9F]/', $out);
        $this->assertSame($out, Sanitize::untrusted($out));
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

    // ---- Private Use Area / zone sentinels --------------------------------

    public function testUntrustedLeavesZoneSentinelsAlone(): void
    {
        // Not an oversight, a declared boundary: a sentinel is well-formed text
        // with no control meaning, so widening untrusted() would also eat image
        // markers and icon-font glyphs for every consumer. Zone-bound text uses
        // untrustedForMarkedFrames() instead — this pin reddens if the default is
        // ever quietly widened and the sibling story becomes a lie.
        $forged = 'a' . self::SENTINEL_OPEN . 'pane:tools' . self::SENTINEL_CLOSE . 'b';

        $this->assertSame($forged, Sanitize::untrusted($forged));
    }

    public function testStripZoneSentinelsRemovesOnlyTheSentinelPair(): void
    {
        $input = 'a' . self::SENTINEL_OPEN . 'x' . self::SENTINEL_CLOSE . 'b'
            . self::IMAGE_MARKER . self::PUA_LAST . self::ASTRAL_PUA;

        // The id text between them survives as inert characters; everything above
        // U+E001 (image markers, patched-font glyphs) must keep flowing.
        $this->assertSame(
            'axb' . self::IMAGE_MARKER . self::PUA_LAST . self::ASTRAL_PUA,
            Sanitize::stripZoneSentinels($input),
        );
    }

    public function testStripZoneSentinelsNeutralisesAForgedZone(): void
    {
        $hostile = "here is a screenshot\x0A"
            . self::SENTINEL_OPEN . 'pane:tools' . self::SENTINEL_CLOSE
            . 'click here'
            . self::SENTINEL_OPEN . '/' . 'pane:tools' . self::SENTINEL_CLOSE;

        $clean = Sanitize::stripZoneSentinels($hostile);

        // A zone needs a sentinel at BOTH ends; with neither able to survive, the
        // input can neither register a click target nor break the zone parse.
        $this->assertStringNotContainsString(self::SENTINEL_OPEN, $clean);
        $this->assertStringNotContainsString(self::SENTINEL_CLOSE, $clean);
        $this->assertStringContainsString('pane:tools', $clean);
        $this->assertStringContainsString('click here', $clean);
    }

    public function testStripZoneSentinelsIsFailClosedOnBrokenUtf8(): void
    {
        // str_replace on literal bytes: a malformed sequence elsewhere in the
        // string cannot turn the sweep into a no-op the way a /u regex would.
        $input = "\xF4\xFF\xFF\xFF" . self::SENTINEL_OPEN . 'tail';

        $this->assertSame("\xF4\xFF\xFF\xFF" . 'tail', Sanitize::stripZoneSentinels($input));
    }

    public function testStripPrivateUseEmptiesTheBasicMultilingualBlock(): void
    {
        $input = 'a' . self::SENTINEL_OPEN . self::SENTINEL_CLOSE . self::IMAGE_MARKER
            . self::PUA_LAST . '€' . self::ASTRAL_PUA . 'z';

        // Sentinels, image markers and the block's top go; a normal BMP glyph and
        // the supplementary-plane private use area (most Nerd Font territory) do
        // not — they cannot form zone markup, so stripping them costs glyphs for
        // nothing.
        $this->assertSame('a€' . self::ASTRAL_PUA . 'z', Sanitize::stripPrivateUse($input));
    }

    public function testStripPrivateUseKeepsTheBlockNeighbours(): void
    {
        // U+D7FF (last codepoint before the block) and U+F900 (first after it)
        // bound the pattern; either one being swallowed is a real regression.
        $below = "\xED\x9F\xBF";
        $above = "\xEF\xA4\x80";

        $this->assertSame(
            $below . $above,
            Sanitize::stripPrivateUse($below . self::SENTINEL_OPEN . $above),
        );
    }

    public function testStripPrivateUseIsFailClosedOnBrokenUtf8(): void
    {
        // A /u pattern would return null here and the `?? ''` fallback would
        // hand back the hostile bytes; the byte scan only strips.
        $input = "\xC3\x28" . self::SENTINEL_OPEN . self::IMAGE_MARKER;

        $this->assertSame("\xC3\x28", Sanitize::stripPrivateUse($input));
    }

    public function testUntrustedForMarkedFramesComposesBothPolicies(): void
    {
        $input = "model says:\x1b[31mred\x07"
            . self::SENTINEL_OPEN . 'pane:tools' . self::SENTINEL_CLOSE
            . "\ttab\nline";

        // Whole escape sequences (not just the introducer) and BEL are gone, the
        // forged zone loses both sentinels while its id text stays inert, and the
        // whitespace untrusted() promises to preserve still survives.
        $this->assertSame(
            "model says:redpane:tools" . "\ttab\nline",
            Sanitize::untrustedForMarkedFrames($input),
        );
    }

    // ---- untrustedForDisplay() ---------------------------------------------

    public function testUntrustedKeepsCarriageReturnSoTheDisplayPolicyIsASeparateMethod(): void
    {
        // Backward-compatibility pin: the two existing policies still pass CR.
        $this->assertSame("a\rb\r\nc", Sanitize::untrusted("a\rb\r\nc"));
        $this->assertSame("a\rb\r\nc", Sanitize::untrustedForMarkedFrames("a\rb\r\nc"));
    }

    public function testUntrustedForDisplayMapsCrlfToOneLineFeed(): void
    {
        $this->assertSame("x\ny", Sanitize::untrustedForDisplay("x\r\ny"));
        $this->assertSame("a\nb\nc", Sanitize::untrustedForDisplay("a\r\nb\r\nc"));
    }

    public function testUntrustedForDisplayMapsLoneCarriageReturnToLineFeedKeepingBothHalves(): void
    {
        // Mapped, never dropped: the text a CR would have painted over stays on screen.
        $this->assertSame("visible\nHIDDEN", Sanitize::untrustedForDisplay("visible\rHIDDEN"));
        $this->assertSame("a\nb", Sanitize::untrustedForDisplay("a\rb"));
    }

    public function testUntrustedForDisplayMapsCarriageReturnsAtTheEnds(): void
    {
        $this->assertSame("\nabc\n", Sanitize::untrustedForDisplay("\rabc\r"));
        $this->assertSame("\n", Sanitize::untrustedForDisplay("\r"));
        $this->assertSame("\n", Sanitize::untrustedForDisplay("\r\n"));
        $this->assertSame("\n\n", Sanitize::untrustedForDisplay("\n\r"));
    }

    public function testUntrustedForDisplayMapsMixedLineEndingsAndAProgressBar(): void
    {
        $this->assertSame("a\nb\nc\nd\n\ne", Sanitize::untrustedForDisplay("a\r\nb\rc\nd\r\re"));
        $this->assertSame(
            " 10%\n 50%\n100%\ndone\n",
            Sanitize::untrustedForDisplay(" 10%\r 50%\r100%\r\ndone\n"),
        );
    }

    public function testUntrustedForDisplayStillStripsEscapesC0C1AndKeepsTab(): void
    {
        $this->assertSame(
            "red\ttab\nbeltitlecsi2Jline",
            Sanitize::untrustedForDisplay("\x1b[31mred\x1b[0m\ttab\r\x07bel\x1b]0;x\x07title\x00csi\u{9b}2J\x7fline"),
        );
        $this->assertSame("a\nb", Sanitize::untrustedForDisplay("a\r\x07\nb"), 'a CR exposed by a removed control still collapses');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0b-\x1f\x7f]|\xC2[\x80-\x9F]/', Sanitize::untrustedForDisplay("\u{9d}0;x\u{9c}\r\x1b[2J"));
    }

    public function testUntrustedForDisplayIsFailClosedOnInvalidUtf8(): void
    {
        $out = Sanitize::untrustedForDisplay("\xFFa\rb\xE2\r\nc\u{9b}2J");
        $this->assertStringNotContainsString("\r", $out);
        $this->assertStringNotContainsString("\xC2\x9B", $out);
        $this->assertSame("\xFFa\nb\xE2\nc2J", $out);
    }

    public function testUntrustedForDisplayLeavesCarriageReturnFreeTextIdenticalToUntrusted(): void
    {
        foreach (['', 'plain', "a\tb\nc", "漢字 👩‍👩‍👧 \x1b[1mbold", self::SENTINEL_OPEN . 'z' . self::SENTINEL_CLOSE] as $s) {
            $this->assertSame(Sanitize::untrusted($s), Sanitize::untrustedForDisplay($s));
        }
    }

    public function testSentinelConstantsAgreeWithTheReservedArena(): void
    {
        $this->assertSame(Sanitize::PUA_BMP_FIRST, mb_ord(Sanitize::ZONE_SENTINEL_OPEN));
        $this->assertSame(Sanitize::PUA_BMP_FIRST + 1, mb_ord(Sanitize::ZONE_SENTINEL_CLOSE));
        $this->assertSame(0xF8FF, Sanitize::PUA_BMP_LAST);
        // The arena's own byte spellings are what the strips match on.
        $this->assertSame("\xEE\x80\x80", Sanitize::ZONE_SENTINEL_OPEN);
        $this->assertSame("\xEE\x80\x81", Sanitize::ZONE_SENTINEL_CLOSE);
    }

    public function testImageOverlayMarkersAreDisjointFromTheZoneSentinels(): void
    {
        // The tripwire pinned at the collision era fired as designed: the
        // allocator moved to U+E002 + id (a32c4faae ruling, follow-up 2 of 2),
        // so image ids 0/1 are no longer the zone sentinels and a rendered
        // screenshot can never speak the zone language. The whole PUA sweep in
        // sugar-crush's maskImageMarkers() stays as defense-in-depth, but the
        // byte-identity premise it documented is hereby retired.
        $this->assertNotSame(Sanitize::ZONE_SENTINEL_OPEN, ImageOverlay::marker(0));
        $this->assertNotSame(Sanitize::ZONE_SENTINEL_CLOSE, ImageOverlay::marker(1));
        $this->assertNotSame(Sanitize::ZONE_SENTINEL_OPEN, ImageOverlay::marker(1));
        $this->assertNotSame(Sanitize::ZONE_SENTINEL_CLOSE, ImageOverlay::marker(0));
        $this->assertSame(self::IMAGE_MARKER, ImageOverlay::marker(0));
        $this->assertSame(mb_ord(Sanitize::ZONE_SENTINEL_OPEN) + 2, mb_ord(ImageOverlay::marker(0)));
        // Disjoint across the full arena: no image id ever spells a sentinel.
        foreach ([2, 100, ImageOverlay::MAX_IMAGES - 1] as $id) {
            $marker = ImageOverlay::marker($id);
            $this->assertNotSame(Sanitize::ZONE_SENTINEL_OPEN, $marker);
            $this->assertNotSame(Sanitize::ZONE_SENTINEL_CLOSE, $marker);
        }
    }
}
