<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Windows\WideString;

/**
 * {@see WideString} — the UTF-8 / UTF-16LE boundary every `…W` call sits behind.
 *
 * ## What this file is willing to assert, and why not more
 *
 * The interesting failure of a transcoder is a WRONG ANSWER, and the only fully
 * independent oracle for "is this the UTF-16LE of that UTF-8" is a second
 * implementation. There is one in this file: `expect()` below spells the expected
 * code-unit sequence as the hex of it, hand-derived from the Unicode code points,
 * so the assertions compare `mb_convert_encoding` against arithmetic rather than
 * against another `mb_convert_encoding`. That is what makes the astral-character
 * row (a code point above U+FFFF becoming TWO units, `D83C DF89`) a real check —
 * it is also the single most common way this conversion is botched, by a build
 * whose "UTF-16" emits a BOM or whose unit order is the other way round.
 *
 * ## The pair the tests are actually about
 *
 * {@see WideString::codeUnits()} is the reason this class is worth its own type.
 * It counts UTF-16 code units, and `mb_strlen()` counts characters, and the two
 * differ by exactly the number of astral characters in the string. A caller that
 * sizes a wide buffer with the character count is one unit short per emoji, which
 * is a heap overflow by one `wchar_t` and not a wrong string — hence the
 * deliberately-two-emoji row asserting four units, and its sibling asserting the
 * byte count derived from it.
 */
final class WideStringTest extends TestCase
{
    public function testEmptyStringEncodesToEmptyAndNotToATerminator(): void
    {
        self::assertSame('', WideString::fromUtf8(''));
        // The empty string is the case where "terminated" and "empty" are one
        // unit apart, and a caller that skips the call on '' gets a non-terminated
        // buffer. terminate() must still emit the NUL.
        self::assertSame("\0\0", WideString::terminate(''));
    }

    public function testBasicMultilingualPlaneEncodesLittleEndian(): void
    {
        // 'a' U+0061, 'é' U+00E9, 'ß' U+00DF — low byte first, no BOM.
        self::expect('aéß', '6100' . 'e900' . 'df00');
    }

    public function testCjkEncodesToItsCodePointNotItsUtf8Bytes(): void
    {
        // 文 U+6587 -> 87 65 in little-endian order. A UTF-8 passthrough would
        // have produced e6 96 87.
        self::expect('文', '8765');
    }

    public function testAstralCharacterBecomesASurrogatePair(): void
    {
        // U+1F389 -> D83C DF89; little-endian: 3cd8 89df.
        self::expect('🎉', '3cd8' . '89df');
    }

    public function testCodeUnitsCountsSurrogatesAsTwoAndCharactersAsOne(): void
    {
        self::assertSame(1, WideString::codeUnits('a'));
        self::assertSame(1, WideString::codeUnits('é'));
        self::assertSame(1, WideString::codeUnits('文'));
        // The load-bearing row: 2 characters, 4 units. mb_strlen() would answer
        // 2 and under-size every buffer built from it.
        self::assertSame(4, WideString::codeUnits('🎉🎉'));
        self::assertSame(8, WideString::byteLength('🎉🎉'));
        // 2 units for the emoji + 3 for the ASCII = 5 units = 10 bytes: the row a
        // character-count-based buffer size would get wrong by two bytes.
        self::assertSame(5, WideString::codeUnits('🎉abc'));
        self::assertSame(10, WideString::byteLength('🎉abc'));
    }

    public function testTerminateAppendsExactlyOneNulUnit(): void
    {
        $terminated = WideString::terminate('a');

        self::assertSame('6100' . '0000', bin2hex($terminated));
        // And only ONE unit: three bytes of 'a' plus terminator is 4 bytes, and a
        // doubled terminator is how a caller accidentally starts building an
        // environment block instead of a path.
        self::assertSame(4, strlen($terminated));
        self::assertSame(6, strlen(WideString::terminate('ab')));
    }

    public function testDecodeStripsOneTrailingUnitAndKeepsInteriorNuls(): void
    {
        self::assertSame('aé', WideString::toUtf8(WideString::fromUtf8('aé') . WideString::NUL_UNIT));
        self::assertSame('', WideString::toUtf8(WideString::NUL_UNIT));
        self::assertSame('', WideString::toUtf8(''));
        // An interior NUL unit is data. Two units at the END are the block's
        // terminator plus the entry's, and only the last one is removed.
        self::assertSame("a\0b", WideString::toUtf8(WideString::fromUtf8("a\0b")));
    }

    public function testRoundTripOverTheWholePlaneSample(): void
    {
        foreach (['', 'a', 'é', 'ß', '文', '🎉', 'aé文🎉z', "tab\tsep", 'nl' . "\n"] as $case) {
            self::assertSame(
                $case,
                WideString::toUtf8(WideString::fromUtf8($case)),
                'round-trip failed for ' . json_encode($case),
            );
        }
    }

    public function testMalformedUtf8IsRefusedNotReplaced(): void
    {
        // 0xFF is not a legal UTF-8 lead byte. mbstring's own behaviour here is to
        // produce something, and "something" reaching CreateProcessW is a path the
        // caller never named.
        try {
            WideString::fromUtf8("\xff\xfe");
            self::fail('invalid UTF-8 was converted instead of refused');
        } catch (PtyException $e) {
            self::assertMatchesRegularExpression('/not valid UTF-8/', $e->getMessage());
        }
    }

    public function testOddLengthWideInputIsRefused(): void
    {
        // Half a code unit: a buffer trimmed by one byte by a length bug upstream.
        try {
            WideString::toUtf8("\x41");
            self::fail('an odd-length UTF-16LE buffer was decoded instead of refused');
        } catch (PtyException $e) {
            self::assertMatchesRegularExpression('/whole number of 2-byte code units/', $e->getMessage());
        }
    }

    public function testLoneSurrogateIsRefusedInBothShapes(): void
    {
        $lead = hex2bin('3cd8');   // D83C with no trailing unit
        $trail = hex2bin('00dc');  // DC00 with no leading unit

        foreach ([$lead, $trail, $lead . $lead] as $case) {
            try {
                WideString::toUtf8($case);
                self::fail('a lone surrogate was decoded instead of refused');
            } catch (PtyException $e) {
                self::assertStringContainsString('surrogate', $e->getMessage());
            }
        }
    }

    /**
     * Assert the UTF-16LE of `$utf8` is exactly `$expectedHex`.
     *
     * The expected value is written as hex rather than as a PHP string literal
     * because `"\x61\x00"` is one miscount away from the bug under test, and
     * because the point of the row is the code-unit arithmetic.
     */
    private static function expect(string $utf8, string $expectedHex): void
    {
        self::assertSame(
            $expectedHex,
            bin2hex(WideString::fromUtf8($utf8)),
            'UTF-16LE of ' . json_encode($utf8),
        );
    }
}
