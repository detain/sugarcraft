<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Sanitize;

/**
 * C-SEC-8.c (audit GAP-UI-N5): the strip twin of audit 15b-28. Where
 * {@see Sanitize::markInvisibleFormatting()} renders the invisible bidi and
 * zero-width format characters visible for a display surface, a caller that
 * must simply be free of them (a submitted prompt, a stored name) needs them
 * DELETED — and the deletion must agree with the marking, character for
 * character, or the two policies drift into different definitions of "spoof".
 * The generated battery below pins that agreement instead of restating it.
 */
final class SanitizeStripInvisibleFormattingTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function alwaysTier(): iterable
    {
        foreach ([0x200B, 0x202A, 0x202B, 0x202C, 0x202D, 0x202E, 0x2060, 0x2066, 0x2067, 0x2068, 0x2069, 0xFEFF] as $cp) {
            yield \sprintf('U+%04X', $cp) => [$cp];
        }
    }

    /** @return iterable<string, array{int}> */
    public static function joinerTier(): iterable
    {
        foreach ([0x061C, 0x200C, 0x200D, 0x200E, 0x200F] as $cp) {
            yield \sprintf('U+%04X', $cp) => [$cp];
        }
    }

    #[DataProvider('alwaysTier')]
    public function testAlwaysTierIsRemovedWhateverSurroundsIt(int $cp): void
    {
        $ch = mb_chr($cp, 'UTF-8');

        foreach (["a{$ch}b" => 'ab', "{$ch}x" => 'x', "文{$ch}字" => '文字', "👍{$ch}" => '👍', $ch => ''] as $in => $want) {
            $removed = null;
            $this->assertSame($want, Sanitize::stripInvisibleFormatting($in, $removed), "input {$in}");
            $this->assertSame(1, $removed, "count for {$in}");
        }
    }

    #[DataProvider('joinerTier')]
    public function testAJoinerInsideRealTextIsKept(int $cp): void
    {
        $ch = mb_chr($cp, 'UTF-8');
        $removed = null;

        $this->assertSame("👩{$ch}💻", Sanitize::stripInvisibleFormatting("👩{$ch}💻", $removed), 'family emoji spelling survives');
        $this->assertSame(0, $removed);
    }

    #[DataProvider('joinerTier')]
    public function testAPalPalSpoofIsStripped(int $cp): void
    {
        $ch = mb_chr($cp, 'UTF-8');

        $removed = null;
        $this->assertSame('paypal', Sanitize::stripInvisibleFormatting("pay{$ch}pal", $removed));
        $this->assertSame(1, $removed);

        $removed = null;
        $this->assertSame('x', Sanitize::stripInvisibleFormatting("{$ch}x", $removed), 'at the start of the text there is nothing to be spelling');
        $this->assertSame(1, $removed);
    }

    #[DataProvider('joinerTier')]
    public function testAJoinerRunIsNeverSpelling(int $cp): void
    {
        $ch = mb_chr($cp, 'UTF-8');
        $removed = null;

        $this->assertSame("é{$ch}f", Sanitize::stripInvisibleFormatting("é{$ch}{$ch}f", $removed));
        $this->assertSame(1, $removed, 'the joiner right after a non-ASCII letter stays, the one after it goes');
    }

    public function testAJoinerAfterARemovedAlwaysCharacterIsRemovedToo(): void
    {
        $removed = null;

        $this->assertSame('éf', Sanitize::stripInvisibleFormatting("é\u{200B}\u{200D}f", $removed));
        $this->assertSame(2, $removed, 'the display policy marks this joiner (it sits after the U+200B marker); the strip policy must not keep it');
    }

    public function testEmptyInput(): void
    {
        $removed = null;

        $this->assertSame('', Sanitize::stripInvisibleFormatting('', $removed));
        $this->assertSame(0, $removed);
    }

    public function testTheCountIsZeroWhenNothingIsRemoved(): void
    {
        $removed = null;
        $family = "👩\u{200D}💻";

        $this->assertSame("paypal {$family} text", Sanitize::stripInvisibleFormatting("paypal {$family} text", $removed));
        $this->assertSame(0, $removed);
    }

    public function testPlainAsciiMarkerTextIsNotStripped(): void
    {
        $removed = null;

        $this->assertSame('a<U+200B>b', Sanitize::stripInvisibleFormatting('a<U+200B>b', $removed), 'a literal marker is printable ASCII text, not a format character');
        $this->assertSame(0, $removed);
    }

    public function testInvalidUtf8DoesNotSwitchTheStripOff(): void
    {
        $removed = null;

        $this->assertSame("\xff ab c", Sanitize::stripInvisibleFormatting("\xff a\u{200D}b \u{202E}c", $removed));
        $this->assertSame(2, $removed);
    }

    /**
     * The agreement battery: for every short mix of plain, non-ASCII,
     * always-tier and joiner tokens, the stripped text is exactly the marked
     * text with its `<U+XXXX>` markers deleted, and the removed count is the
     * marker count. This pins "the two can never disagree" behaviourally,
     * not by restating the rule.
     */
    public function testTheStripAndTheMarkAgreeOnEveryShortSequence(): void
    {
        $tokens = ['a', 'é', '👩', "\u{200B}", "\u{202E}", "\u{FEFF}", "\u{061C}", "\u{200C}", "\u{200D}", "\u{200F}"];
        $flip = [];
        foreach ($tokens as $t) {
            $flip[\sprintf('<U+%04X>', mb_ord($t, 'UTF-8'))] = '';
        }

        $cases = 0;
        foreach ($tokens as $t1) {
            foreach ($tokens as $t2) {
                foreach ($tokens as $t3) {
                    $in = $t1 . $t2 . $t3;
                    $marked = Sanitize::markInvisibleFormatting($in);
                    $want = \strtr($marked, $flip);

                    $removed = null;
                    $this->assertSame($want, Sanitize::stripInvisibleFormatting($in, $removed), "sequence {$in}");
                    $this->assertSame(substr_count($marked, '<U+'), $removed, "count for {$in}");
                    $cases++;
                }
            }
        }

        $this->assertSame(1000, $cases);
    }

    /**
     * Same prefix law the display path honours (`SanitizeInvisibleFormattingTest`):
     * the rule looks only backward, so the result of a prefix is the result
     * of the prefix, and a caller sanitising a draft and its caret prefix in
     * two passes still agrees on the column.
     */
    public function testThePrefixOfTheResultIsTheResultOfThePrefix(): void
    {
        $in = "ab\u{200D}c 👩\u{200D}💻 \u{202E}d\u{200C}\u{200C}é\u{200F}";
        $whole = Sanitize::stripInvisibleFormatting($in);

        for ($n = 1, $len = mb_strlen($in); $n <= $len; $n++) {
            $prefix = Sanitize::stripInvisibleFormatting(mb_substr($in, 0, $n));
            $this->assertStringStartsWith($prefix, $whole, "prefix of {$n} codepoints");
        }
    }

    public function testTheDefaultCallNeedsNoOutParam(): void
    {
        $this->assertSame('paypal', Sanitize::stripInvisibleFormatting("pay\u{200D}pal"));
    }
}
