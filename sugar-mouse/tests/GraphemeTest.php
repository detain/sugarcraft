<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Grapheme;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;

/**
 * P2-8 (g), audit rank 110: the scanner measures one grapheme CLUSTER per
 * step, never one code point — including on hosts without ext-intl, where
 * the fallback now asks PCRE's UAX #29 `\X` instead of consuming a single
 * scalar (which split ZWJ emoji, flags and combining runs and shifted every
 * zone to their right).
 */
final class GraphemeTest extends TestCase
{
    /** @return iterable<string, array{0:string, 1:string}> subject, expected first cluster */
    public static function clusters(): iterable
    {
        yield 'ascii' => ['ab', 'a'];
        yield 'combining accent' => ["e\u{0301}x", "e\u{0301}"];
        yield 'zwj family' => ["👨\u{200D}👩\u{200D}👧x", "👨\u{200D}👩\u{200D}👧"];
        yield 'flag pair' => ['🇯🇵🇺🇸', '🇯🇵'];
        yield 'keycap' => ["1\u{FE0F}\u{20E3}!", "1\u{FE0F}\u{20E3}"];
        yield 'tag sequence flag' => ["\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}a", "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}"];
        yield 'hangul syllable' => ['한글', '한'];
        yield 'skin tone' => ["👍\u{1F3FD} ok", "👍\u{1F3FD}"];
        yield 'crlf' => ["\r\nx", "\r\n"];
        yield 'cjk' => ['日本', '日'];
        // GB9b: a Prepend code point joins the ASCII character after it.
        yield 'prepend before ascii letter' => ["\u{0600}a", "\u{0600}a"];
        yield 'prepend before ascii digit' => ["\u{06DD}1x", "\u{06DD}1"];
        yield 'non-ascii before ascii' => ['éa', 'é'];
    }

    #[DataProvider('clusters')]
    public function testFallbackExtractsWholeClustersWithoutIntl(string $subject, string $expected): void
    {
        self::assertSame($expected, Grapheme::nextWithoutIntl($subject, 0));
    }

    #[DataProvider('clusters')]
    public function testNextExtractsWholeClusters(string $subject, string $expected): void
    {
        self::assertSame($expected, Grapheme::next($subject, 0));
    }

    #[DataProvider('clusters')]
    public function testFallbackAgreesWithIntl(string $subject, string $expected): void
    {
        if (!function_exists('grapheme_extract')) {
            self::markTestSkipped('ext-intl is not loaded; the absolute pins above still run');
        }
        self::assertSame(
            grapheme_extract($subject, 1, GRAPHEME_EXTR_COUNT, 0),
            Grapheme::nextWithoutIntl($subject, 0),
        );
    }

    public function testOffsetsAndEndOfString(): void
    {
        $s = "ab\u{0301}c";
        self::assertSame("b\u{0301}", Grapheme::nextWithoutIntl($s, 1));
        self::assertSame('c', Grapheme::nextWithoutIntl($s, 4));
        self::assertSame('', Grapheme::nextWithoutIntl($s, 5));
        self::assertSame('', Grapheme::next($s, 5));
    }

    public function testInvalidUtf8DegradesToOneLeadUnit(): void
    {
        self::assertSame("\xff", Grapheme::nextWithoutIntl("\xff\xfe", 0));
        self::assertSame("\xff", Grapheme::next("\xff\xfe", 0));
        // A truncated 3-byte sequence at the end of the string.
        self::assertSame("\xe6\x97", Grapheme::nextWithoutIntl("\xe6\x97", 0));
    }

    /**
     * A zone right of each cluster lands exactly where a terminal paints it:
     * the cluster advances by its own display width, once.
     */
    public function testZonesRightOfClustersRegisterAtTheirPaintedColumn(): void
    {
        $zones = Scan::parse(
            "e\u{0301}" . Mark::zone('a', 'x')         // 1 cell, then a
            . "\n🇯🇵" . Mark::zone('b', 'x')            // 2 cells, then b
            . "\n👨\u{200D}👩\u{200D}👧" . Mark::zone('c', 'x'), // 2 cells, then c
        );

        self::assertSame(2, $zones['a']->startCol);
        self::assertSame(3, $zones['b']->startCol);
        self::assertSame(3, $zones['c']->startCol);
    }

    /**
     * GB9b: a Prepend code point and the ASCII letter after it are one
     * cluster, so the zone after them starts one cell in, not two. The
     * fallback must keep that ASCII byte in the Prepend's window.
     */
    public function testPrependClusterBeforeAsciiAdvancesOnce(): void
    {
        self::assertSame("\u{0600}a", Grapheme::nextWithoutIntl("\u{0600}a" . Mark::zone('x', 'y'), 0));

        $zones = Scan::parse("\u{0600}a" . Mark::zone('x', 'y'));

        self::assertSame(2, $zones['x']->startCol);
    }

    /**
     * The printable-ASCII fast path must leave a run's last byte to the
     * cluster path when a combining mark follows it.
     */
    public function testAsciiRunBeforeACombiningMarkStaysOneCell(): void
    {
        $zones = Scan::parse("cafe\u{0301} " . Mark::zone('z', 'Z'));

        self::assertSame(6, $zones['z']->startCol);
    }
}
