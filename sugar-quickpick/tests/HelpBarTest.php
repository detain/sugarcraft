<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use SugarCraft\QuickPick\HelpBar;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\QuickPick\HelpBar
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

    // ------------------------------------------------------------------
    // -039 fail-fast ingress / -064 documented collision collapse
    // ------------------------------------------------------------------

    public function testConstructorRefusesNonStringKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('shortcut key must be a string, int given');

        new HelpBar([0 => 'navigate']);
    }

    public function testConstructorRefusesNonStringDescriptionNamingTheEntry(): void
    {
        try {
            new HelpBar(['Esc' => null, 'Enter' => 'select']);
            self::fail('expected InvalidArgumentException for the null description');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('for key "Esc"', $e->getMessage());
            self::assertStringContainsString('null given', $e->getMessage());
        }
    }

    public function testGuardedKeyCollisionIsLastWriteWins(): void
    {
        // -064: "a\x1b[2J" and "a" guard to the same visible key. The
        // documented default collapses them, keeping the LATER description
        // — never two rows that render identically from the user's side.
        $bar = new HelpBar(["a\x1b[2J" => 'first', 'a' => 'second']);

        self::assertSame(['a' => 'second'], $bar->shortcuts());

        // Keys that differ visibly stay distinct (control bytes are the
        // only way to collide).
        $distinct = new HelpBar(['a' => 'first', 'b' => 'second']);
        self::assertSame(['a' => 'first', 'b' => 'second'], $distinct->shortcuts());
    }

    /** S-29/-051: house factory forwards shortcuts AND the visibility seed. */
    public function testNewFactoryMatchesConstructor(): void
    {
        $bar = HelpBar::new(['x' => 'why'], false);
        self::assertSame(['x' => 'why'], $bar->shortcuts());
        self::assertFalse($bar->isVisible());
    }
}
