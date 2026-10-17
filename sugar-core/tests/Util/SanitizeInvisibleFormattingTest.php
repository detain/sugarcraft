<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Sanitize;

/**
 * Audit 15b-28: bidi overrides and zero-width characters are well-formed text,
 * so every strip let them through, yet U+202E makes the terminal paint the
 * rest of a row reversed ("Trojan Source") and a zero-width space makes two
 * different names look identical. The display policies now render them as
 * `<U+XXXX>` markers; `untrusted()` keeps them for paste fidelity.
 */
final class SanitizeInvisibleFormattingTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function alwaysMarked(): iterable
    {
        foreach ([0x200B, 0x202A, 0x202B, 0x202C, 0x202D, 0x202E, 0x2060, 0x2066, 0x2067, 0x2068, 0x2069, 0xFEFF] as $cp) {
            yield \sprintf('U+%04X', $cp) => [$cp];
        }
    }

    /** @return iterable<string, array{int}> */
    public static function joiners(): iterable
    {
        foreach ([0x061C, 0x200C, 0x200D, 0x200E, 0x200F] as $cp) {
            yield \sprintf('U+%04X', $cp) => [$cp];
        }
    }

    public function testVisibleControlsShowsARightToLeftOverride(): void
    {
        $this->assertSame('rm <U+202E>txt.sh', Sanitize::visibleControls("rm \u{202E}txt.sh"));
        $this->assertSame('rm <U+202E>txt.sh', Sanitize::visibleControls("rm \u{202E}txt.sh", false));
    }

    public function testDisplayPolicyLeavesNoRawOverride(): void
    {
        $out = Sanitize::untrustedForDisplay("rm \u{202E}hs.txt\u{202C} -rf");

        $this->assertSame('rm <U+202E>hs.txt<U+202C> -rf', $out);
        $this->assertStringNotContainsString("\u{202E}", $out);
    }

    #[DataProvider('alwaysMarked')]
    public function testAlwaysMarkedTierIsMarkedEverywhere(int $cp): void
    {
        $ch = mb_chr($cp, 'UTF-8');
        $marker = \sprintf('<U+%04X>', $cp);

        foreach (["a{$ch}b", "{$ch}x", "文{$ch}字", "👍{$ch}"] as $in) {
            $this->assertSame(str_replace($ch, $marker, $in), Sanitize::untrustedForDisplay($in));
            $this->assertSame(str_replace($ch, $marker, $in), Sanitize::visibleControls($in));
        }
    }

    #[DataProvider('joiners')]
    public function testJoinersAreMarkedAfterAsciiOrAtTheStart(int $cp): void
    {
        $ch = mb_chr($cp, 'UTF-8');
        $marker = \sprintf('<U+%04X>', $cp);

        $this->assertSame("pay{$marker}pal", Sanitize::untrustedForDisplay("pay{$ch}pal"));
        $this->assertSame("{$marker}x", Sanitize::untrustedForDisplay("{$ch}x"));
        $this->assertSame("é{$ch}{$marker}f", Sanitize::untrustedForDisplay("é{$ch}{$ch}f"), 'a run is never spelling');
    }

    #[DataProvider('joiners')]
    public function testJoinersSurviveAfterNonAsciiInTheDisplayPolicyOnly(int $cp): void
    {
        $ch = mb_chr($cp, 'UTF-8');

        $this->assertSame("👩{$ch}💻", Sanitize::untrustedForDisplay("👩{$ch}💻"));
        $this->assertSame(
            \sprintf('👩<U+%04X>💻', $cp),
            Sanitize::visibleControls("👩{$ch}💻"),
            'a gate shows every byte, even a joiner inside an emoji',
        );
    }

    public function testAJoinerAfterAnAlwaysMarkedCharacterIsMarked(): void
    {
        $this->assertSame('é<U+200B><U+200D>', Sanitize::untrustedForDisplay("é\u{200B}\u{200D}"));
    }

    public function testUntrustedKeepsThemForPasteFidelity(): void
    {
        $in = "pay\u{200D}pal \u{202E}txt \u{FEFF}\u{2066}x\u{2069}";

        $this->assertSame($in, Sanitize::untrusted($in));
        $this->assertSame($in, Sanitize::untrustedForMarkedFrames($in));
    }

    /**
     * Callers (sugar-crush's input box) sanitise a draft and its caret prefix
     * in two passes and index one with the other's length, so the result of a
     * prefix must be a prefix of the result.
     */
    public function testThePrefixOfTheResultIsTheResultOfThePrefix(): void
    {
        $in = "ab\u{200D}c 👩\u{200D}💻 \u{202E}d\u{200C}\u{200C}é\u{200F}";
        $whole = Sanitize::untrustedForDisplay($in);

        for ($n = 1, $len = mb_strlen($in); $n <= $len; $n++) {
            $prefix = Sanitize::untrustedForDisplay(mb_substr($in, 0, $n));
            $this->assertStringStartsWith($prefix, $whole, "prefix of {$n} codepoints");
        }
    }

    public function testInvalidUtf8DoesNotSwitchTheMarkingOff(): void
    {
        $out = Sanitize::untrustedForDisplay("\xff a\u{200D}b \u{202E}c");

        $this->assertStringContainsString('a<U+200D>b', $out);
        $this->assertStringContainsString('<U+202E>c', $out);
    }
}
