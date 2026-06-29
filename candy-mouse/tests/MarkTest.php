<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;

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

    public function testEmptyIdAndContent(): void
    {
        $mark = new Mark();
        $wrapped = $mark->wrap('', '');
        self::assertStringContainsString("\u{E000}", $wrapped);
        self::assertStringContainsString("\u{E001}", $wrapped);
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
