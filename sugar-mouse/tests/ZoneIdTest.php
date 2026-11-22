<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\ZoneId;
use SugarCraft\Mouse\Zones\Manager;

/**
 * P2-8 (b): typed zone ids. The wire spelling is ordinary Mark id material,
 * so the string API keeps working; the type replaces prefix tests and
 * `(int) substr(...)` parsing on the reading side.
 */
final class ZoneIdTest extends TestCase
{
    public function testOfBuildsTheWireSpelling(): void
    {
        self::assertSame('toolcall:42', ZoneId::of('toolcall', 42)->wire());
        self::assertSame('tab:settings', ZoneId::of('tab', 'settings')->wire());
        self::assertSame('cell:3:4', ZoneId::of('cell', '3:4')->wire());
        self::assertSame('quit', ZoneId::of('quit')->wire(), 'an empty key is the bare namespace');

        $id = ZoneId::of('toolcall', 42);
        self::assertSame('toolcall', $id->namespace);
        self::assertSame('42', $id->key);
    }

    /** @return iterable<string, array{0:string, 1:string|int}> */
    public static function invalidParts(): iterable
    {
        yield 'empty namespace' => ['', 'x'];
        yield 'colon in namespace' => ['a:b', 'x'];
        yield 'space in namespace' => ['a b', 'x'];
        yield 'space in key' => ['ns', 'a b'];
        yield 'newline in key' => ['ns', "a\n"];
        yield 'sentinel in key' => ['ns', "\u{E000}"];
        yield 'oversized key' => ['ns', str_repeat('k', Mark::MAX_ID_BYTES)];
    }

    #[DataProvider('invalidParts')]
    public function testOfRefusesWhatCouldNotBeAZoneId(string $namespace, string|int $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^sugar-mouse: ZoneId /');
        ZoneId::of($namespace, $key);
    }

    public function testParseRoundTripsOf(): void
    {
        foreach ([ZoneId::of('toolcall', 42), ZoneId::of('cell', '3:4'), ZoneId::of('quit'), ZoneId::of('a.b-c', 'x_y')] as $id) {
            $parsed = ZoneId::parse($id->wire());
            self::assertNotNull($parsed);
            self::assertTrue($parsed->equals($id), $id->wire());
        }
    }

    public function testParseRejectsUnroutableSpellings(): void
    {
        self::assertNull(ZoneId::parse(''));
        self::assertNull(ZoneId::parse(':key'), 'empty namespace');
        self::assertNull(ZoneId::parse('ns:'), 'trailing colon, no key');
        self::assertNull(ZoneId::parse('has space'));
        self::assertNull(ZoneId::parse("ns:\u{E001}"));
    }

    public function testIntKeyIsStrict(): void
    {
        self::assertSame(7, ZoneId::of('row', 7)->intKey());
        self::assertSame(0, ZoneId::of('row', 0)->intKey());
        self::assertSame(-3, ZoneId::of('row', '-3')->intKey());

        // `(int) substr(...)` turned every one of these into a number.
        foreach (['07', '7a', 'abc', '-0', '1.5', '99999999999999999999'] as $key) {
            self::assertNull(ZoneId::of('row', $key)->intKey(), $key);
        }
        self::assertNull(ZoneId::of('quit')->intKey(), 'no key at all');
    }

    public function testEqualsAndNamespace(): void
    {
        $id = ZoneId::of('tab', 2);

        self::assertTrue($id->equals(ZoneId::of('tab', '2')));
        self::assertFalse($id->equals(ZoneId::of('tab', 3)));
        self::assertFalse($id->equals(ZoneId::of('tabs', 2)));
        self::assertTrue($id->inNamespace('tab'));
        self::assertFalse($id->inNamespace('ta'), 'namespace match is equality, not prefix');
    }

    public function testMarkScansLikeAStringIdAndBothLookupsAgree(): void
    {
        $id = ZoneId::of('btn', 'ok');
        $frame = 'xx' . $id->mark('OK');

        self::assertSame('xxOK', Scan::strip($frame));
        self::assertSame($frame, 'xx' . Mark::zone('btn:ok', 'OK'), 'same bytes as the string API');

        $scanner = Scanner::new()->scan($frame);
        self::assertSame($scanner->get('btn:ok'), $scanner->zone($id));
        self::assertSame(3, $scanner->zone($id)?->startCol);
        self::assertNull($scanner->zone(ZoneId::of('btn', 'cancel')));

        $manager = Manager::newPrefix('p-');
        $manager->scan($manager->mark($id->wire(), 'OK'));
        self::assertSame('p-btn:ok', $manager->zone($id)?->id, 'short typed id resolves through the prefix');
    }
}
