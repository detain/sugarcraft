<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\StatusBar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\Hermit\StatusBar
 */
final class StatusBarTest extends TestCase
{
    public function testNewWithMessage(): void
    {
        $bar = new StatusBar('Searching...');

        self::assertSame('Searching...', $bar->message());
    }

    public function testNewDefaultsToVisible(): void
    {
        $bar = new StatusBar();

        self::assertTrue($bar->isVisible());
    }

    public function testNewWithVisibleFalse(): void
    {
        $bar = new StatusBar('Done', false);

        self::assertFalse($bar->isVisible());
    }

    public function testWithMessageReturnsNewInstance(): void
    {
        $bar = new StatusBar('Searching...');
        $bar2 = $bar->withMessage('Found');

        self::assertNotSame($bar, $bar2);
        self::assertSame('Searching...', $bar->message());
        self::assertSame('Found', $bar2->message());
    }

    public function testWithNoMessageClearsMessage(): void
    {
        $bar = new StatusBar('Searching...');
        $bar2 = $bar->withNoMessage();

        self::assertNotSame($bar, $bar2);
        self::assertSame('', $bar2->message());
    }

    public function testShowReturnsNewVisibleInstance(): void
    {
        $bar = new StatusBar('Done', false);
        $bar2 = $bar->show();

        self::assertNotSame($bar, $bar2);
        self::assertFalse($bar->isVisible());
        self::assertTrue($bar2->isVisible());
    }

    public function testHideReturnsNewHiddenInstance(): void
    {
        $bar = new StatusBar();
        $bar2 = $bar->hide();

        self::assertNotSame($bar, $bar2);
        self::assertTrue($bar->isVisible());
        self::assertFalse($bar2->isVisible());
    }

    public function testWithSegmentAddsNamedSegment(): void
    {
        $bar = new StatusBar('Searching...');
        $bar2 = $bar->withSegment('count', '7');

        self::assertNotSame($bar, $bar2);
        self::assertSame([], $bar->segments());
        self::assertSame(['count' => '7'], $bar2->segments());
    }

    public function testWithSegmentUpdatesExistingSegment(): void
    {
        $tmp = new StatusBar();
        $bar = $tmp->withSegment('count', '7');
        $bar2 = $bar->withSegment('count', '42');

        self::assertSame(['count' => '42'], $bar2->segments());
    }

    public function testWithoutSegmentRemovesSegment(): void
    {
        $tmp1 = new StatusBar();
        $tmp2 = $tmp1->withSegment('count', '7');
        $bar = $tmp2->withSegment('name', 'test');
        $bar2 = $bar->withoutSegment('count');

        self::assertNotSame($bar, $bar2);
        self::assertArrayHasKey('count', $bar->segments());
        self::assertArrayNotHasKey('count', $bar2->segments());
        self::assertArrayHasKey('name', $bar2->segments());
    }

    public function testWithoutSegmentOnNonexistentReturnsClone(): void
    {
        $tmp = new StatusBar();
        $bar = $tmp->withSegment('count', '7');
        $bar2 = $bar->withoutSegment('nonExistent');

        self::assertNotSame($bar, $bar2);
        self::assertSame($bar->segments(), $bar2->segments());
    }

    public function testRenderFormatsSegmentsAndMessage(): void
    {
        $tmp = new StatusBar('Searching...');
        $bar = $tmp->withSegment('count', '7');
        $rendered = $bar->render();

        self::assertStringContainsString('[count: 7]', $rendered);
        self::assertStringContainsString('Searching...', $rendered);
    }

    public function testRenderFormatIsSegmentFirstThenMessage(): void
    {
        $tmp = new StatusBar('Done');
        $bar = $tmp->withSegment('items', '5');
        $rendered = $bar->render();

        // Format: "[segment1: value] message"
        self::assertSame('[items: 5] Done', $rendered);
    }

    public function testRenderSegmentsOnly(): void
    {
        $tmp = new StatusBar();
        $bar = $tmp->withSegment('count', '7');
        $rendered = $bar->render();

        self::assertSame('[count: 7]', $rendered);
    }

    public function testRenderMessageOnly(): void
    {
        $bar = new StatusBar('Done');
        $rendered = $bar->render();

        self::assertSame('Done', $rendered);
    }

    public function testRenderReturnsEmptyWhenHidden(): void
    {
        $bar = new StatusBar('Done', false);

        self::assertSame('', $bar->render());
    }

    public function testRenderReturnsEmptyWhenNoMessageAndNoSegments(): void
    {
        $bar = new StatusBar();

        self::assertSame('', $bar->render());
    }

    public function testMultipleSegments(): void
    {
        $tmp = new StatusBar('Done');
        $bar = $tmp->withSegment('items', '5');
        $bar = $bar->withSegment('pages', '10');

        $rendered = $bar->render();

        self::assertStringContainsString('[items: 5]', $rendered);
        self::assertStringContainsString('[pages: 10]', $rendered);
        self::assertStringContainsString('Done', $rendered);
    }

    public function testImmutableSegmentsAccessor(): void
    {
        $tmp = new StatusBar();
        $bar = $tmp->withSegment('count', '7');
        $segments = $bar->segments();
        $segments['new'] = 'value';

        // Original unchanged
        self::assertArrayNotHasKey('new', $bar->segments());
    }
}
