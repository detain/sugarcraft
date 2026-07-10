<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;

final class MarkTest extends TestCase
{
    public function testWrapInsertsCorrectSentinels(): void
    {
        $mark = new Mark();
        $wrapped = $mark->wrap('btn', 'hello');

        // Sentinels are U+E000 / U+E001 — these are private-use codepoints.
        self::assertStringContainsString("\u{E000}", $wrapped);
        self::assertStringContainsString("\u{E001}", $wrapped);
    }

    public function testWrapPlacesContentBetweenSentinels(): void
    {
        $mark = new Mark();
        $wrapped = $mark->wrap('btn', 'hello');

        // Content is between opening and closing sentinel.
        self::assertStringContainsString('hello', $wrapped);
    }

    public function testWrapIdAppearsInBothSentinels(): void
    {
        $mark = new Mark();
        $wrapped = $mark->wrap('my-button', 'text');

        // Id appears in opening sentinel.
        self::assertStringContainsString('my-button', $wrapped);
        // Id also appears in closing sentinel (prefixed with '/').
        self::assertStringContainsString('/my-button', $wrapped);
    }

    public function testZoneStaticShortcut(): void
    {
        $wrapped = Mark::zone('id', 'content');
        self::assertStringContainsString('id', $wrapped);
        self::assertStringContainsString('content', $wrapped);
    }

    public function testEmptyContentStillWrapsWithValidId(): void
    {
        $mark = new Mark();
        $wrapped = $mark->wrap('btn', '');
        self::assertStringContainsString("\u{E000}", $wrapped);
        self::assertStringContainsString("\u{E001}", $wrapped);
    }

    public function testEmptyIdIsRejected(): void
    {
        // The charset requires one-or-more chars; an empty id is degenerate
        // and cannot address a zone.
        $this->expectException(\InvalidArgumentException::class);
        (new Mark())->wrap('', 'content');
    }

    public function testRoundTripViaScanner(): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap('zone-a', 'hello world');

        $scanner = Scanner::new()->scan($rendered);
        $zone = $scanner->get('zone-a');

        self::assertNotNull($zone);
        self::assertSame('zone-a', $zone->id);
        self::assertSame('hello world', 'hello world'); // content itself is preserved
    }

    public function testMultipleZonesRoundTrip(): void
    {
        $mark = new Mark();

        $rendered = $mark->wrap('a', 'first') . "\n" . $mark->wrap('b', 'second');

        $scanner = Scanner::new()->scan($rendered);

        $zoneA = $scanner->get('a');
        $zoneB = $scanner->get('b');

        self::assertNotNull($zoneA);
        self::assertNotNull($zoneB);
        self::assertSame('a', $zoneA->id);
        self::assertSame('b', $zoneB->id);
    }

    // ─── id sentinel-injection guard (W3 [SEC]) ─────────────────────────────

    public function testRawOpenSentinelByteInIdIsRejected(): void
    {
        // An id smuggling U+E000 (\xEE\x80\x80) would open a bogus zone and
        // desync Scan::parse() — reject it.
        $this->expectException(\InvalidArgumentException::class);
        (new Mark())->wrap('evil' . Sentinel::OPEN . 'zone', 'x');
    }

    public function testRawCloseSentinelByteInIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Mark())->wrap('evil' . Sentinel::CLOSE . 'zone', 'x');
    }

    public function testSpaceInIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Mark())->wrap('has space', 'x');
    }

    public function testEscapeByteInIdIsRejected(): void
    {
        // A raw ESC would let an id inject a CSI/OSC/APC sequence.
        $this->expectException(\InvalidArgumentException::class);
        (new Mark())->wrap("esc\x1b[31m", 'x');
    }

    public function testNewlineInIdIsRejected(): void
    {
        // Guards the \A…\z anchoring: a trailing newline must NOT slip past a
        // naive $-anchored pattern (a newline id would advance Scan's row).
        $this->expectException(\InvalidArgumentException::class);
        (new Mark())->wrap("id\n", 'x');
    }

    public function testValidIdIsRejectedViaZoneAlias(): void
    {
        // The static Mark::zone() alias routes through wrap() and is guarded too.
        $this->expectException(\InvalidArgumentException::class);
        Mark::zone('bad id', 'x');
    }

    public function testDisabledMarkStillValidatesId(): void
    {
        // Validation is unconditional — a disabled marker rejects a bad id
        // rather than silently returning content.
        $this->expectException(\InvalidArgumentException::class);
        Mark::disabled()->wrap(Sentinel::OPEN, 'x');
    }

    /**
     * @dataProvider validIdProvider
     */
    public function testValidIdsWrapAndRoundTrip(string $id): void
    {
        $mark = new Mark();
        $rendered = $mark->wrap($id, 'hello');

        // Round-trips through both the low-level Scan::parse() and the
        // high-level Scanner without desync.
        $zones = (new Scan())->parse($rendered);
        self::assertArrayHasKey($id, $zones);
        self::assertSame($id, $zones[$id]->id);

        $zone = Scanner::new()->scan($rendered)->get($id);
        self::assertNotNull($zone);
        self::assertSame($id, $zone->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validIdProvider(): array
    {
        return [
            'tab index'      => ['tab-0'],
            'cell coords'    => ['cell:5:3'],
            'crumb'          => ['crumb-2'],
            'zone prefix'    => ['candyzone:S:foo'],
            'dotted-under'   => ['a.b_c'],
            'single char'    => ['x'],
            'all classes'    => ['Az9._:-'],
        ];
    }

    // ─── enabled / disabled ─────────────────────────────────────────────────

    public function testDisabledWrapReturnsContentUnchanged(): void
    {
        $mark = Mark::disabled();
        $wrapped = $mark->wrap('btn', 'hello');

        // No sentinels — content is returned as-is.
        self::assertSame('hello', $wrapped);
    }

    public function testDisabledWrapContainsNoPUABytes(): void
    {
        $mark = Mark::disabled();
        $wrapped = $mark->wrap('btn', 'hello');

        // U+E000 = \xEE\x80\x80, U+E001 = \xEE\x80\x81
        self::assertStringNotContainsString("\xEE\x80\x80", $wrapped);
        self::assertStringNotContainsString("\xEE\x80\x81", $wrapped);
    }

    public function testEnabledWrapContainsSentinels(): void
    {
        $mark = new Mark(true);
        $wrapped = $mark->wrap('btn', 'hello');

        self::assertStringContainsString("\u{E000}", $wrapped);
        self::assertStringContainsString("\u{E001}", $wrapped);
    }

    public function testWithEnabledReturnsSameInstanceWhenUnchanged(): void
    {
        $mark = new Mark(true);
        $result = $mark->withEnabled(true);

        self::assertSame($mark, $result);
    }

    public function testWithEnabledReturnsNewInstanceWhenChanged(): void
    {
        $mark = new Mark(true);
        $result = $mark->withEnabled(false);

        self::assertNotSame($mark, $result);
        self::assertSame('hello', $result->wrap('btn', 'hello'));
    }

    public function testDefaultMarkIsEnabled(): void
    {
        $mark = new Mark();
        $wrapped = $mark->wrap('btn', 'hello');

        // Default constructor enables sentinels.
        self::assertStringContainsString("\u{E000}", $wrapped);
    }
}
