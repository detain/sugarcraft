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
        // A lone 0x80 with an ASCII predecessor is a malformed C1 byte.
        $this->assertSame('ab', Sanitize::untrusted("a\x80b"));
        $this->assertSame('ab', Sanitize::untrusted("a\x9fb"));
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
