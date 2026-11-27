<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Disclosure;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Disclosure\CollapsibleList;
use SugarCraft\Widgets\Disclosure\Disclosure;
use SugarCraft\Widgets\Disclosure\DisclosureToggledMsg;

/**
 * P2-3a: CollapsibleList selection (the cursor), per-item open state, the
 * toggled message with its index, and immutability.
 */
final class CollapsibleListTest extends TestCase
{
    private static function list(): CollapsibleList
    {
        return CollapsibleList::new([
            Disclosure::new('One', 'first'),
            Disclosure::new('Two', 'second'),
            Disclosure::new('Three', 'third'),
        ]);
    }

    private static function key(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    public function testNewStartsBlurredWithTheCursorOnTheFirstItem(): void
    {
        $l = self::list();
        self::assertSame(3, $l->count());
        self::assertSame(0, $l->cursor());
        self::assertFalse($l->isFocused());
        self::assertSame('One', $l->selected()?->title());
        self::assertSame([], $l->openIndices());
        self::assertNull($l->init());
        self::assertNull($l->subscriptions());
        self::assertTrue((new \ReflectionClass(CollapsibleList::class))->getConstructor()?->isPrivate());
    }

    public function testNewDropsKeysAndBlursItems(): void
    {
        $l = CollapsibleList::new(['a' => Disclosure::new('A')->focus(), 'b' => Disclosure::new('B')]);
        self::assertSame(['A', 'B'], array_map(static fn (Disclosure $d): string => $d->title(), $l->items()));
        self::assertFalse($l->item(0)?->isFocused());
    }

    public function testNewRejectsANonDisclosureItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CollapsibleList::new([Disclosure::new('A'), 'not a disclosure']);
    }

    public function testEmptyList(): void
    {
        $l = CollapsibleList::new()->focus();
        self::assertSame(0, $l->count());
        self::assertNull($l->selected());
        self::assertSame('', $l->view());
        self::assertSame($l, $l->toggle());
        [$next, $cmd] = $l->update(self::key(KeyType::Down));
        self::assertSame($l, $next);
        self::assertNull($cmd);
    }

    public function testWithCursorClampsAndIsImmutable(): void
    {
        $l = self::list();
        $moved = $l->withCursor(2);
        self::assertNotSame($l, $moved);
        self::assertSame(2, $moved->cursor());
        self::assertSame(0, $l->cursor());
        self::assertSame('Three', $moved->selected()?->title());
        self::assertSame(2, $l->withCursor(99)->cursor());
        self::assertSame(0, $l->withCursor(-5)->cursor());
    }

    public function testWithItemsClampsTheCursor(): void
    {
        $l = self::list()->withCursor(2)->withItems([Disclosure::new('Only')]);
        self::assertSame(1, $l->count());
        self::assertSame(0, $l->cursor());
        self::assertSame('Only', $l->selected()?->title());
    }

    public function testWithOpenSetsOneItemAndIsImmutable(): void
    {
        $l = self::list();
        $opened = $l->withOpen(1);
        self::assertNotSame($l, $opened);
        self::assertTrue($opened->isOpen(1));
        self::assertFalse($l->isOpen(1));
        self::assertSame([1], $opened->openIndices());
        self::assertFalse($opened->withOpen(1, false)->isOpen(1));
        self::assertFalse($l->isOpen(99), 'out of range reads as closed');
        self::assertNull($l->item(99));
    }

    public function testWithOpenRejectsAMissingIndex(): void
    {
        $this->expectException(\OutOfRangeException::class);
        self::list()->withOpen(3);
    }

    public function testWithAllOpen(): void
    {
        $l = self::list()->withAllOpen();
        self::assertSame([0, 1, 2], $l->openIndices());
        self::assertSame([], $l->withAllOpen(false)->openIndices());
    }

    public function testToggleActsOnTheCursorOrAnExplicitIndex(): void
    {
        $l = self::list()->withCursor(1);
        self::assertSame([1], $l->toggle()->openIndices());
        self::assertSame([], $l->toggle()->toggle()->openIndices());
        self::assertSame([2], $l->toggle(2)->openIndices());
        self::assertSame([], $l->openIndices(), 'toggle leaves the original alone');
    }

    public function testToggleRejectsAMissingExplicitIndex(): void
    {
        $this->expectException(\OutOfRangeException::class);
        self::list()->toggle(7);
    }

    public function testFocusAndBlur(): void
    {
        self::assertTrue(self::list()->focus()->isFocused());
        self::assertFalse(self::list()->focus()->blur()->isFocused());
        self::assertTrue(self::list()->withFocused()->isFocused());
    }

    public function testUpdateIgnoresKeysWhileBlurred(): void
    {
        $l = self::list();
        [$next, $cmd] = $l->update(self::key(KeyType::Down));
        self::assertSame($l, $next);
        self::assertNull($cmd);
    }

    public function testCursorKeysMoveTheSelection(): void
    {
        $l = self::list()->focus();
        [$l, $cmd] = $l->update(self::key(KeyType::Down));
        self::assertSame(1, $l->cursor());
        self::assertNull($cmd);
        [$l] = $l->update(self::key(KeyType::Char, 'j'));
        self::assertSame(2, $l->cursor());
        [$l] = $l->update(self::key(KeyType::Down));
        self::assertSame(2, $l->cursor(), 'no wrap past the last item');
        [$l] = $l->update(self::key(KeyType::Up));
        self::assertSame(1, $l->cursor());
        [$l] = $l->update(self::key(KeyType::Char, 'k'));
        self::assertSame(0, $l->cursor());
        [$l] = $l->update(self::key(KeyType::Up));
        self::assertSame(0, $l->cursor(), 'no wrap before the first item');
        [$l] = $l->update(self::key(KeyType::End));
        self::assertSame(2, $l->cursor());
        [$l] = $l->update(self::key(KeyType::Home));
        self::assertSame(0, $l->cursor());
        [$l] = $l->update(self::key(KeyType::Char, 'G'));
        self::assertSame(2, $l->cursor());
        [$l] = $l->update(self::key(KeyType::Char, 'g'));
        self::assertSame(0, $l->cursor());
    }

    public function testEnterTogglesTheSelectedItemAndSendsItsIndex(): void
    {
        $l = self::list()->focus()->withCursor(1);
        [$opened, $cmd] = $l->update(self::key(KeyType::Enter));
        self::assertSame([1], $opened->openIndices());
        self::assertInstanceOf(\Closure::class, $cmd);
        $msg = $cmd();
        self::assertInstanceOf(DisclosureToggledMsg::class, $msg);
        self::assertSame('Two', $msg->title);
        self::assertTrue($msg->open);
        self::assertSame(1, $msg->index);

        [$closed, $cmd] = $opened->update(self::key(KeyType::Space));
        self::assertSame([], $closed->openIndices());
        $msg = $cmd();
        self::assertInstanceOf(DisclosureToggledMsg::class, $msg);
        self::assertFalse($msg->open);
        self::assertSame(1, $msg->index);
    }

    public function testRightOpensLeftClosesAndRepeatsAreNoOps(): void
    {
        $l = self::list()->focus()->withCursor(2);
        [$l, $cmd] = $l->update(self::key(KeyType::Right));
        self::assertSame([2], $l->openIndices());
        self::assertNotNull($cmd);
        [$same, $cmd] = $l->update(self::key(KeyType::Char, 'l'));
        self::assertSame($l, $same);
        self::assertNull($cmd);
        [$l, $cmd] = $l->update(self::key(KeyType::Char, 'h'));
        self::assertSame([], $l->openIndices());
        self::assertNotNull($cmd);
        [$same, $cmd] = $l->update(self::key(KeyType::Left));
        self::assertSame($l, $same);
        self::assertNull($cmd);
    }

    public function testReleasedAndModifiedKeysAreIgnored(): void
    {
        $l = self::list()->focus();
        foreach ([
            new KeyReleaseMsg(KeyType::Down),
            new KeyReleaseMsg(KeyType::Enter),
            new KeyMsg(KeyType::Down, ctrl: true),
            new KeyMsg(KeyType::Enter, alt: true),
            self::key(KeyType::Char, 'x'),
        ] as $key) {
            [$next, $cmd] = $l->update($key);
            self::assertSame($l, $next, $key->string());
            self::assertNull($cmd, $key->string());
        }
    }

    public function testViewStacksItemsAndShowsOpenBodies(): void
    {
        $l = self::list()->withOpen(1);
        self::assertSame("▸ One\n▾ Two\n  second\n▸ Three", $l->view());
        self::assertSame($l->view(), (string) $l);
    }

    public function testViewPaintsOnlyTheSelectedItemFocusedWhileTheListIsFocused(): void
    {
        $focused = Style::new()->reverse()->render('▸ Two');
        $l = self::list()->withCursor(1);
        self::assertSame("▸ One\n▸ Two\n▸ Three", $l->view(), 'a blurred list paints no focus');
        self::assertSame("▸ One\n{$focused}\n▸ Three", $l->focus()->view());
    }
}
