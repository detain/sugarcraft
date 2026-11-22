<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\LineWidthCache;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Zones\Manager;

/**
 * P2-8 (e), audit rank 62: re-scanning a frame re-measures only the lines
 * that changed. Inert lines (no zone markup, no lone CR, no DCS/SOS/PM/APC)
 * have their widths cached; the parse result must be identical with or
 * without the cache.
 */
final class LineWidthCacheTest extends TestCase
{
    /** @return iterable<string, array{0:string}> */
    public static function frames(): iterable
    {
        yield 'plain and zoned lines' => ["plain line\n" . Mark::zone('a', 'hello') . " world\n日本語 text\n"];
        yield 'styled lines, CRLF' => ["\e[31mred\e[0m line\r\n" . Mark::zone('b', "multi\nrow") . "\r\ntail"];
        yield 'clusters' => ["e\u{0301}x\n" . Mark::zone('c', '👨‍👩‍👧 fam') . "\n🇯🇵 " . Mark::zone('d', 'z')];
        yield 'osc and lone cr' => ["\e]0;title\x07after " . Mark::zone('e', 'q') . "\nabc\rXY" . Mark::zone('f', 'k')];
        yield 'dcs across lines' => ["\ePq#0\npayload\e\\" . Mark::zone('g', 'g') . "\nnext"];
        yield 'zone opened on an earlier line' => [Mark::zone('h', "first\nplain middle\nlast") . "\nafter"];
        yield 'leading newlines' => ["\n\n" . Mark::zone('i', "\nabc") . "\n\e[1;2"];
        yield 'unterminated csi at eol' => ["ab\e[3" . "\n" . Mark::zone('j', 'x')];
    }

    #[DataProvider('frames')]
    public function testCachedParseEqualsUncachedParse(string $frame): void
    {
        $cache = LineWidthCache::new();
        $expected = Scan::parse($frame);

        self::assertEquals($expected, Scan::parse($frame, null, $cache), 'first (cold) pass');
        self::assertEquals($expected, Scan::parse($frame, null, $cache), 'second (warm) pass');
        self::assertEquals(Scan::parse($frame, 5), Scan::parse($frame, 5, $cache), 'with a width clamp');
    }

    public function testUnchangedInertLinesAreServedFromTheCache(): void
    {
        $body = implode("\n", array_map(static fn (int $n): string => "\e[2mline {$n} 日本\e[0m", range(1, 20)));
        $cache = LineWidthCache::new();

        Scan::parse($body . "\n" . Mark::zone('btn', 'OK'), null, $cache);
        self::assertSame(0, $cache->hits());
        self::assertSame(20, $cache->misses(), 'every inert line measured once; the zoned line is never looked up');
        self::assertSame(20, $cache->size());

        $zones = Scan::parse($body . "\n" . Mark::zone('btn', 'OK!'), null, $cache);
        self::assertSame(20, $cache->hits(), 'the unchanged lines are not re-measured');
        self::assertSame(20, $cache->misses());
        self::assertSame([1, 21, 3, 21], [$zones['btn']->startCol, $zones['btn']->startRow, $zones['btn']->endCol, $zones['btn']->endRow]);
    }

    public function testLinesThatAreNotInertAreNeverCached(): void
    {
        $cache = LineWidthCache::new();
        Scan::parse(Mark::zone('a', 'x') . "\nab\rcd\n\ePdcs\e\\\n\u{E000}stray", null, $cache);

        self::assertSame(0, $cache->size());
        self::assertSame(0, $cache->misses() + $cache->hits());
    }

    public function testACrlfLineIsCachedWithoutItsCarriageReturn(): void
    {
        $cache = LineWidthCache::new();
        Scan::parse("abc\r\n" . Mark::zone('z', 'Z'), null, $cache);

        self::assertSame(3, $cache->lookup('abc'));
        self::assertNull($cache->lookup("abc\r"));
    }

    public function testGenerationsRetireLinesNotSeenForAFrame(): void
    {
        $cache = LineWidthCache::new();
        $cache->store('old', 3);
        $cache->beginFrame();
        self::assertSame(3, $cache->lookup('old'), 'carried into the new generation on lookup');

        $cache->store('gone', 4);
        $cache->beginFrame();
        $cache->beginFrame();
        self::assertNull($cache->lookup('gone'), 'unseen for a whole frame: dropped');
        self::assertNull($cache->lookup('old'));
        self::assertSame(0, $cache->size());
    }

    public function testOversizedLinesAndClear(): void
    {
        $cache = LineWidthCache::new();
        $cache->store(str_repeat('x', LineWidthCache::MAX_LINE_BYTES + 1), 1);
        self::assertSame(0, $cache->size());

        $cache->store('kept', 4);
        self::assertSame(1, $cache->size());
        $cache->clear();
        self::assertSame(0, $cache->size());
        self::assertNull($cache->lookup('kept'));
    }

    public function testScannerAndManagerCarryTheirCacheAcrossScans(): void
    {
        $frame = "header line\n" . Mark::zone('btn', 'OK');

        $scanner = Scanner::new();
        $scanner->scan($frame)->scan($frame);
        self::assertSame(1, $scanner->lineWidths()->hits());
        self::assertSame('btn', $scanner->hit(1, 2)?->id);

        $manager = Manager::newUnprefixed();
        $manager->scan($frame);
        $manager->scan($frame);
        self::assertSame(1, $manager->lineWidths()->hits());
        self::assertSame('btn', $manager->zoneAt(2, 2)?->id);
    }
}
