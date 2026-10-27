<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Window;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\Window\WindowViewport;
use SugarCraft\Code\Tui\Window\WindowViewports;

/**
 * Viewport stash/restore of the window kit (P-TABS.a): leaving a window
 * keeps its scroll offset and draft, arriving at an unknown window starts
 * from the bottom with an empty draft — never another window's residue.
 */
final class WindowViewportsTest extends TestCase
{
    public function testStashRestoreRoundTrip(): void
    {
        $store = WindowViewports::empty()->stash('s-1', new WindowViewport(7, 'half-typed', 4));

        $back = $store->restore('s-1');
        self::assertSame(7, $back->scrollOffset);
        self::assertSame('half-typed', $back->draft);
        self::assertSame(4, $back->cursor);
        self::assertTrue($store->has('s-1'));
        self::assertSame(1, $store->count());
    }

    public function testStashReplacesInPlaceAndKeepsOtherWindows(): void
    {
        $store = WindowViewports::empty()
            ->stash('s-1', new WindowViewport(1, 'a', 1))
            ->stash('s-2', new WindowViewport(2, 'b', 1))
            ->stash('s-1', new WindowViewport(9, 'a2', 2));

        self::assertSame(2, $store->count(), 'a re-stash replaces, it does not duplicate');
        self::assertSame('a2', $store->restore('s-1')->draft);
        self::assertSame('b', $store->restore('s-2')->draft, 'the untouched window must not move');
    }

    public function testUnknownKeyRestoresTheBottomOfNothing(): void
    {
        $store = WindowViewports::empty()->stash('other', new WindowViewport(5, 'residue', 3));

        $fresh = $store->restore('never-seen');
        self::assertSame(0, $fresh->scrollOffset, 'an unknown window must not inherit scroll state');
        self::assertSame('', $fresh->draft, 'an unknown window must not inherit a draft');
        self::assertSame(0, $fresh->cursor);
        self::assertFalse($store->has('never-seen'));
    }

    public function testViewportNormalizesOnConstruction(): void
    {
        $clamped = new WindowViewport(-4, 'abc', 99);
        self::assertSame(0, $clamped->scrollOffset, 'scroll cannot go above the top');
        self::assertSame(3, $clamped->cursor, 'cursor clamps to the end of the draft');

        $emptyDraft = new WindowViewport(0, '', 5);
        self::assertSame(0, $emptyDraft->cursor, 'a cursor cannot sit in a draft that is not there');
    }

    public function testWithersDeriveAndNeverMutate(): void
    {
        $base = new WindowViewport(1, 'abc', 1);

        $scrolled = $base->withScrollOffset(5);
        self::assertSame(1, $base->scrollOffset, 'the original stays');
        self::assertSame(5, $scrolled->scrollOffset);
        self::assertSame('abc', $scrolled->draft);
        self::assertSame(1, $scrolled->cursor, 'scrolling alone never moves the caret');

        $typed = $base->withDraft('abcdef', 6);
        self::assertSame(1, $base->cursor);
        self::assertSame('abcdef', $typed->draft);
        self::assertSame(6, $typed->cursor);

        self::assertSame(0, $typed->withScrollOffset(-2)->scrollOffset, 'the same normalization on the wither');
    }
}
