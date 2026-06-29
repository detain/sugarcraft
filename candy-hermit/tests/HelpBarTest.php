<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\HelpBar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\Hermit\HelpBar
 */
final class HelpBarTest extends TestCase
{
    public function testNewWithShortcuts(): void
    {
        $bar = new HelpBar(['Esc' => 'close', 'Enter' => 'select']);

        self::assertSame(['Esc' => 'close', 'Enter' => 'select'], $bar->shortcuts());
    }

    public function testNewDefaultsToVisible(): void
    {
        $bar = new HelpBar();

        self::assertTrue($bar->isVisible());
    }

    public function testNewWithVisibleFalse(): void
    {
        $bar = new HelpBar([], false);

        self::assertFalse($bar->isVisible());
    }

    public function testWithShortcutAddsEntry(): void
    {
        $bar = new HelpBar(['Esc' => 'close']);
        $bar2 = $bar->withShortcut('Enter', 'select');

        self::assertNotSame($bar, $bar2);
        self::assertSame(['Esc' => 'close'], $bar->shortcuts());
        self::assertSame(['Esc' => 'close', 'Enter' => 'select'], $bar2->shortcuts());
    }

    public function testWithShortcutUpdatesExistingEntry(): void
    {
        $bar = new HelpBar(['Esc' => 'close']);
        $bar2 = $bar->withShortcut('Esc', 'quit');

        self::assertSame(['Esc' => 'quit'], $bar2->shortcuts());
    }

    public function testWithoutShortcutRemovesEntry(): void
    {
        $bar = new HelpBar(['Esc' => 'close', 'Enter' => 'select']);
        $bar2 = $bar->withoutShortcut('Esc');

        self::assertNotSame($bar, $bar2);
        self::assertSame(['Esc' => 'close', 'Enter' => 'select'], $bar->shortcuts());
        self::assertSame(['Enter' => 'select'], $bar2->shortcuts());
    }

    public function testWithoutShortcutOnNonexistentKeyReturnsClone(): void
    {
        $bar = new HelpBar(['Esc' => 'close']);
        $bar2 = $bar->withoutShortcut('NonExistent');

        self::assertNotSame($bar, $bar2);
        self::assertSame($bar->shortcuts(), $bar2->shortcuts());
    }

    public function testShowReturnsNewVisibleInstance(): void
    {
        $bar = new HelpBar([], false);
        $bar2 = $bar->show();

        self::assertNotSame($bar, $bar2);
        self::assertFalse($bar->isVisible());
        self::assertTrue($bar2->isVisible());
    }

    public function testHideReturnsNewHiddenInstance(): void
    {
        $bar = new HelpBar();
        $bar2 = $bar->hide();

        self::assertNotSame($bar, $bar2);
        self::assertTrue($bar->isVisible());
        self::assertFalse($bar2->isVisible());
    }

    public function testRenderFormatsShortcuts(): void
    {
        $bar = new HelpBar(['Esc' => 'close', 'Enter' => 'select']);
        $rendered = $bar->render();

        self::assertStringContainsString('Esc: close', $rendered);
        self::assertStringContainsString('Enter: select', $rendered);
        self::assertStringContainsString(' │ ', $rendered);
    }

    public function testRenderReturnsEmptyWhenHidden(): void
    {
        $bar = new HelpBar(['Esc' => 'close'], false);

        self::assertSame('', $bar->render());
    }

    public function testRenderReturnsEmptyWhenNoShortcuts(): void
    {
        $bar = new HelpBar();

        self::assertSame('', $bar->render());
    }

    public function testRenderSingleShortcut(): void
    {
        $bar = new HelpBar(['Esc' => 'close']);

        self::assertSame('Esc: close', $bar->render());
    }

    public function testImmutableShortcutsAccessor(): void
    {
        $bar = new HelpBar(['Esc' => 'close']);
        $shortcuts = $bar->shortcuts();
        $shortcuts['New'] = 'key';

        // Original unchanged
        self::assertArrayNotHasKey('New', $bar->shortcuts());
    }
}
