<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Menu;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Widgets\Menu\Menu;
use SugarCraft\Widgets\Menu\MenuCursor;
use SugarCraft\Widgets\Menu\MenuItem;
use SugarCraft\Widgets\Menu\MenuSelectedMsg;

/**
 * P2-3b: the Menu model, its immutable cursor, cascades, the dropdown
 * layout, leaf selection through MenuSelectedMsg, and the immutability of
 * every with*() setter.
 */
final class MenuTest extends TestCase
{
    /** @return list<MenuItem> */
    private static function tree(): array
    {
        return [
            MenuItem::new('File', 'file', [
                MenuItem::new('New', 'new'),
                MenuItem::new('Open', 'open', [
                    MenuItem::new('Recent', 'recent'),
                    MenuItem::new('Browse', 'browse'),
                ]),
                MenuItem::new('Quit', 'quit'),
            ]),
            MenuItem::new('Edit', 'edit', [
                MenuItem::new('Copy', 'copy'),
                MenuItem::new('Paste'),
            ]),
            MenuItem::new('About', 'about'),
        ];
    }

    private static function menu(): Menu
    {
        return Menu::new(self::tree());
    }

    private static function key(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    /**
     * Feed keys in order and return the final model plus every Cmd returned.
     *
     * @param list<KeyType> $keys
     * @return array{0: Menu, 1: list<?\Closure>}
     */
    private static function feed(Menu $m, array $keys): array
    {
        $cmds = [];
        foreach ($keys as $key) {
            [$m, $cmd] = $m->update(self::key($key));
            $cmds[] = $cmd;
        }
        return [$m, $cmds];
    }

    private static function selectedMsg(?\Closure $cmd): MenuSelectedMsg
    {
        self::assertNotNull($cmd);
        $msg = $cmd();
        self::assertInstanceOf(MenuSelectedMsg::class, $msg);
        return $msg;
    }

    public function testNewStartsOnTheFirstRootItemWithNoCascadeOpen(): void
    {
        $m = self::menu();
        self::assertSame([0], $m->cursor()->path());
        self::assertSame(0, $m->depth());
        self::assertFalse($m->isOpen());
        self::assertFalse($m->isDropdown());
        self::assertSame('File', $m->current()?->label());
        self::assertSame(['File', 'Edit', 'About'], array_map(static fn (MenuItem $i): string => $i->label(), $m->items()));
        self::assertSame($m->items(), $m->level());
    }

    public function testInitAndSubscriptionsAreNull(): void
    {
        $m = self::menu();
        self::assertNull($m->init());
        self::assertNull($m->subscriptions());
    }

    public function testDownAndUpMoveWithinTheRootLevel(): void
    {
        [$m, $cmds] = self::feed(self::menu(), [KeyType::Down, KeyType::Down]);
        self::assertSame([2], $m->cursor()->path());
        self::assertSame('About', $m->current()?->label());
        self::assertSame([null, null], $cmds);

        [$m] = $m->update(self::key(KeyType::Up));
        self::assertSame([1], $m->cursor()->path());
    }

    public function testDownWrapsFromTheLastItemToTheFirst(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Down, KeyType::Down, KeyType::Down]);
        self::assertSame([0], $m->cursor()->path());
    }

    public function testUpWrapsFromTheFirstItemToTheLast(): void
    {
        [$m, $cmd] = self::menu()->update(self::key(KeyType::Up));
        self::assertSame([2], $m->cursor()->path());
        self::assertNull($cmd);
    }

    public function testUpAndDownWrapInsideACascade(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Right, KeyType::Up]);
        self::assertSame([0, 2], $m->cursor()->path());
        [$m] = $m->update(self::key(KeyType::Down));
        self::assertSame([0, 0], $m->cursor()->path());
    }

    public function testMovingOnASingleItemLevelReturnsTheSameInstance(): void
    {
        $m = Menu::new([MenuItem::new('Only', 'only')]);
        [$next, $cmd] = $m->update(self::key(KeyType::Down));
        self::assertSame($m, $next);
        self::assertNull($cmd);
    }

    public function testEnterOnALeafSendsMenuSelectedMsgWithIdAndPath(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Down, KeyType::Down]);
        [$next, $cmd] = $m->update(self::key(KeyType::Enter));
        self::assertSame($m, $next, 'selecting a leaf leaves the cursor where it is');

        $msg = self::selectedMsg($cmd);
        self::assertSame('about', $msg->id);
        self::assertSame('About', $msg->label);
        self::assertSame([2], $msg->path);
    }

    public function testEnterOnANestedLeafCarriesTheFullPath(): void
    {
        [$m, $cmds] = self::feed(self::menu(), [
            KeyType::Enter, KeyType::Down, KeyType::Right, KeyType::Down, KeyType::Enter,
        ]);
        self::assertSame([null, null, null, null], array_slice($cmds, 0, 4), 'only the selecting key returns a Cmd');

        $msg = self::selectedMsg($cmds[4]);
        self::assertSame('browse', $msg->id);
        self::assertSame('Browse', $msg->label);
        self::assertSame([0, 1, 1], $msg->path);
        self::assertSame([0, 1, 1], $m->cursor()->path());
    }

    public function testEnterOnALeafWithoutAnIdSendsANullId(): void
    {
        [, $cmds] = self::feed(self::menu(), [KeyType::Down, KeyType::Right, KeyType::Down, KeyType::Enter]);
        $msg = self::selectedMsg($cmds[3]);
        self::assertNull($msg->id);
        self::assertSame('Paste', $msg->label);
        self::assertSame([1, 1], $msg->path);
    }

    /** @return iterable<string, array{KeyType}> */
    public static function openKeys(): iterable
    {
        yield 'right' => [KeyType::Right];
        yield 'enter' => [KeyType::Enter];
    }

    #[DataProvider('openKeys')]
    public function testOpenKeysOpenTheCascadeOfABranch(KeyType $key): void
    {
        $m = self::menu();
        [$next, $cmd] = $m->update(self::key($key));
        self::assertNull($cmd, 'opening a cascade selects nothing');
        self::assertSame([0, 0], $next->cursor()->path());
        self::assertSame(1, $next->depth());
        self::assertTrue($next->isOpen());
        self::assertSame(['New', 'Open', 'Quit'], array_map(static fn (MenuItem $i): string => $i->label(), $next->level()));
        self::assertSame('New', $next->current()?->label());
    }

    public function testRightOnALeafDoesNothing(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Up]);
        [$next, $cmd] = $m->update(self::key(KeyType::Right));
        self::assertSame($m, $next);
        self::assertNull($cmd);
    }

    /** @return iterable<string, array{KeyType}> */
    public static function closeKeys(): iterable
    {
        yield 'left' => [KeyType::Left];
        yield 'escape' => [KeyType::Escape];
    }

    #[DataProvider('closeKeys')]
    public function testCloseKeysCloseTheActiveCascadeBackToItsParent(KeyType $key): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Right, KeyType::Down, KeyType::Right, KeyType::Down]);
        self::assertSame([0, 1, 1], $m->cursor()->path());

        [$m, $cmd] = $m->update(self::key($key));
        self::assertNull($cmd);
        self::assertSame([0, 1], $m->cursor()->path(), 'the parent item stays under the cursor');
        self::assertSame('Open', $m->current()?->label());

        [$m, $cmd] = $m->update(self::key($key));
        self::assertNull($cmd);
        self::assertSame([0], $m->cursor()->path());
        self::assertFalse($m->isOpen());
    }

    #[DataProvider('closeKeys')]
    public function testCloseKeysAtTheRootAreANoOpWithNoCmd(KeyType $key): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Down]);
        [$next, $cmd] = $m->update(self::key($key));
        self::assertSame($m, $next);
        self::assertNull($cmd);
        self::assertSame([1], $next->cursor()->path());
    }

    public function testReopeningACascadeStartsOnItsFirstItem(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Right, KeyType::Down, KeyType::Down, KeyType::Left, KeyType::Right]);
        self::assertSame([0, 0], $m->cursor()->path());
    }

    public function testUpdateNeverChangesTheReceiver(): void
    {
        $m = self::menu();
        $before = $m->cursor();
        foreach ([KeyType::Down, KeyType::Right, KeyType::Enter, KeyType::Up, KeyType::Left, KeyType::Escape] as $key) {
            $m->update(self::key($key));
            self::assertSame($before, $m->cursor());
            self::assertSame([0], $m->cursor()->path());
        }
    }

    public function testKeyReleaseAndModifierChordsAndOtherMessagesAreIgnored(): void
    {
        $m = self::menu();
        foreach ([
            new KeyReleaseMsg(KeyType::Down),
            new KeyMsg(KeyType::Down, ctrl: true),
            new KeyMsg(KeyType::Enter, alt: true),
            new KeyMsg(KeyType::Char, 'j'),
            new KeyMsg(KeyType::Tab),
            new WindowSizeMsg(80, 24),
        ] as $msg) {
            [$next, $cmd] = $m->update($msg);
            self::assertSame($m, $next);
            self::assertNull($cmd);
        }
    }

    public function testAnEmptyMenuIgnoresEveryKeyAndRendersNothing(): void
    {
        $m = Menu::new([]);
        self::assertNull($m->current());
        self::assertSame([], $m->level());
        foreach ([KeyType::Up, KeyType::Down, KeyType::Right, KeyType::Enter, KeyType::Left, KeyType::Escape] as $key) {
            [$next, $cmd] = $m->update(self::key($key));
            self::assertSame($m, $next);
            self::assertNull($cmd);
        }
        self::assertSame('', $m->view());
        self::assertSame('', $m->withDropdown()->view());
    }

    public function testNewRejectsAnEntryThatIsNotAMenuItem(): void
    {
        $this->expectException(\TypeError::class);
        Menu::new([MenuItem::new('ok'), 'not an item']);
    }

    public function testNewReindexesAKeyedTree(): void
    {
        $m = Menu::new(['a' => MenuItem::new('A'), 'b' => MenuItem::new('B')]);
        self::assertSame([0, 1], array_keys($m->items()));
    }

    public function testWithDropdownLeavesTheOriginalUnchanged(): void
    {
        $m = self::menu();
        $d = $m->withDropdown();
        self::assertNotSame($m, $d);
        self::assertFalse($m->isDropdown());
        self::assertTrue($d->isDropdown());
        self::assertFalse($d->withDropdown(false)->isDropdown());
        self::assertTrue($d->isDropdown());
    }

    public function testWithItemsLeavesTheOriginalUnchangedAndResetsTheCursor(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Right, KeyType::Down]);
        $replaced = $m->withItems([MenuItem::new('Solo', 'solo')]);

        self::assertNotSame($m, $replaced);
        self::assertSame([0], $replaced->cursor()->path());
        self::assertSame(['Solo'], array_map(static fn (MenuItem $i): string => $i->label(), $replaced->items()));

        self::assertSame([0, 1], $m->cursor()->path());
        self::assertSame(['File', 'Edit', 'About'], array_map(static fn (MenuItem $i): string => $i->label(), $m->items()));
    }

    public function testWithItemsKeepsTheLayoutFlag(): void
    {
        self::assertTrue(self::menu()->withDropdown()->withItems(self::tree())->isDropdown());
    }

    public function testWithItemsRejectsAnEntryThatIsNotAMenuItem(): void
    {
        $this->expectException(\TypeError::class);
        self::menu()->withItems([new \stdClass()]);
    }

    public function testNoStaticStateIsSharedBetweenMenus(): void
    {
        [$moved] = self::feed(self::menu(), [KeyType::Down, KeyType::Right]);
        $fresh = self::menu();
        self::assertSame([1, 0], $moved->cursor()->path());
        self::assertSame([0], $fresh->cursor()->path());
        self::assertEquals(MenuCursor::new(), $fresh->cursor());
    }

    public function testViewOfTheRootLevel(): void
    {
        self::assertSame(
            "> File ▸\n"
            . "  Edit ▸\n"
            . '  About',
            self::menu()->view(),
        );
    }

    public function testViewDrawsAnOpenCascadeBesideItsParentRow(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Right, KeyType::Down, KeyType::Right]);
        self::assertSame(
            "> File ▸   New\n"
            . "  Edit ▸ > Open ▸ > Recent\n"
            . '  About    Quit     Browse',
            $m->view(),
        );
    }

    public function testViewOfACascadeOpenedFromALaterRow(): void
    {
        [$m] = self::feed(self::menu(), [KeyType::Down, KeyType::Enter, KeyType::Down]);
        self::assertSame(
            "  File ▸\n"
            . "> Edit ▸   Copy\n"
            . "  About  > Paste",
            $m->view(),
        );
    }

    public function testDropdownViewOfTheBarAlone(): void
    {
        self::assertSame('[File] Edit  About', self::menu()->withDropdown()->view());
    }

    public function testDropdownViewDrawsTheCascadeBelowItsBarItem(): void
    {
        [$m] = self::feed(self::menu()->withDropdown(), [KeyType::Down, KeyType::Enter, KeyType::Down]);
        self::assertSame(
            " File [Edit] About\n"
            . "        Copy\n"
            . '      > Paste',
            $m->view(),
        );
    }

    public function testDropdownViewDrawsDeeperCascadesToTheRight(): void
    {
        [$m] = self::feed(self::menu()->withDropdown(), [KeyType::Enter, KeyType::Down, KeyType::Right]);
        self::assertSame(
            "[File] Edit  About\n"
            . "  New\n"
            . "> Open ▸ > Recent\n"
            . '  Quit     Browse',
            $m->view(),
        );
    }

    public function testDropdownUsesTheSameKeyMapAsTheDefaultLayout(): void
    {
        $keys = [KeyType::Down, KeyType::Right, KeyType::Down, KeyType::Up, KeyType::Left, KeyType::Up];
        [$plain] = self::feed(self::menu(), $keys);
        [$dropdown] = self::feed(self::menu()->withDropdown(), $keys);
        self::assertSame($plain->cursor()->path(), $dropdown->cursor()->path());
    }

    public function testViewIsStableForTheSameState(): void
    {
        $keys = [KeyType::Right, KeyType::Down, KeyType::Right];
        [$a] = self::feed(self::menu(), $keys);
        [$b] = self::feed(self::menu(), $keys);

        self::assertSame($a->view(), $a->view());
        self::assertSame($a->view(), $b->view());
        self::assertSame($a->view(), (string) $a);
        self::assertSame($a->withDropdown()->view(), $b->withDropdown()->view());
    }

    public function testViewSanitizesLabels(): void
    {
        $m = Menu::new([MenuItem::new("Bad\x1b[31m\nLabel", 'bad')]);
        self::assertSame('> Bad Label', $m->view());
    }
}
