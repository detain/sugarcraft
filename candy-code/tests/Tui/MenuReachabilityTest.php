<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Message;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Menu\ShellMenu;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;

/**
 * Every leaf of the shell menu tree must be reachable from F10 at every
 * plausible terminal width — by keyboard, and by clicking through the painted
 * titles (or the `»` fold the bar drops overflowing menus into).
 *
 * The narrow end of the sweep is the interesting one: below the width where
 * the pane-tab strip leaves room, titles stop being painted, and the doors
 * left are the keyboard plus the panel zones (which anchor at the fold's
 * column instead of somewhere off-screen). At those widths the fold records
 * itself in MenuBar's render state while {@see MenuBar::closeMenu()} erases
 * it again — so every leaf iteration re-paints a closed bar before walking,
 * which is exactly what the shell does between keypresses in a live frame.
 *
 * The click legs drive the same {@see MenuBar::selectItem()} door
 * {@see App}'s chrome-click arm uses, over the zones a REAL
 * {@see TuiRenderer::renderView()} chrome pass registers (the component-level
 * render only marks a string), so a registered zone with a dead dispatch — or
 * a live dispatch on an unpainted row — goes red.
 */
final class MenuReachabilityTest extends TestCase
{
    /** Widths walked by both sweeps. */
    public const WIDTHS = [30, 40, 60, 80, 120, 200];

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        MenuBar::closeMenu();
        TuiRenderer::chromeScanner()->clear();
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        TuiRenderer::chromeScanner()->clear();
    }

    private function barApp(): App
    {
        // A hosted chat with one line of history gives the band real height:
        // scanChrome marks dropdown rows only within the painted frame, and
        // an empty band would clip the marks (paint ⇔ mark, to the row).
        return App::new($this->provider, 'test-model')
            ->withChat(new Chat(history: [Message::user('a line under the menu')]));
    }

    /**
     * One chrome pass of a CLOSED bar at $cols: records the fold in MenuBar's
     * render state (paint never opens anything, so the cursor is untouched).
     */
    private function repaint(int $cols): void
    {
        TuiRenderer::renderView($this->barApp(), $cols, 30);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function widths(): array
    {
        $out = [];
        foreach (self::WIDTHS as $w) {
            $out['' . $w . ' cols'] = [$w];
        }

        return $out;
    }

    #[DataProvider('widths')]
    public function testEveryLeafIsReachableByKeysFromTheBar(int $cols): void
    {
        $this->repaint($cols);
        $tree = MenuBar::tree();

        $registryNames = [];
        foreach (CommandRegistry::all() as $spec) {
            $registryNames[$spec->name] = true;
        }

        foreach (ShellMenu::tree(null)->leaves() as $leaf) {
            $path = $tree->pathOf($leaf->id);
            self::assertNotNull($path, "leaf {$leaf->id} vanished from the effective tree at {$cols} cols");

            // closeMenu() erases the fold; re-paint so tree(), pathOf() and
            // openMenu() all speak the SAME effective shape this width paints.
            MenuBar::closeMenu();
            $this->repaint($cols);
            MenuBar::openMenu($path[0] + 1);
            self::assertSame($path[0] + 1, MenuBar::getActiveMenu(), "opening root #{$path[0]} at {$cols} cols");

            for ($d = 1; $d < count($path); $d++) {
                $guard = 0;
                while (MenuBar::getActiveItem() !== $path[$d]) {
                    self::assertLessThan(25, $guard++, "the cursor walk for {$leaf->id} at {$cols} cols ran away");
                    $before = MenuBar::getActiveItem();
                    MenuBar::handleKey('down');
                    self::assertNotSame($before, MenuBar::getActiveItem(), "the menu cursor froze at depth {$d} walking to {$leaf->id} at {$cols} cols");
                }

                if ($d === count($path) - 1) {
                    $step = MenuBar::handleKey('enter');
                    self::assertNotNull($step->chosen, "Enter on {$leaf->id} at {$cols} cols must choose it");
                    self::assertSame($leaf->id, $step->chosen->id);
                    self::assertSame(0, MenuBar::getActiveMenu(), 'choosing closes the menu');
                } else {
                    MenuBar::handleKey('right');
                    self::assertCount($d + 1, MenuBar::cursor()->rows, "entering the submenu at depth {$d} for {$leaf->id}");
                }
            }

            // The selection names a real door: a registry command or a pane.
            if (str_starts_with($leaf->id, 'cmd:')) {
                self::assertArrayHasKey(substr($leaf->id, 4), $registryNames, "{$leaf->id} names no registry command");
            } elseif (str_starts_with($leaf->id, 'pane:')) {
                self::assertNotNull(Pane::tryFrom(substr($leaf->id, 5)), "{$leaf->id} names no pane");
            } else {
                self::fail("unexpected leaf id {$leaf->id}");
            }
        }
    }

    #[DataProvider('widths')]
    public function testEveryPaintedRowCarriesAClickableZoneThatDispatches(int $cols): void
    {
        $this->repaint($cols);
        $tree = MenuBar::tree();
        $app = $this->barApp();

        foreach (ShellMenu::tree(null)->leaves() as $leaf) {
            $path = $tree->pathOf($leaf->id);
            self::assertNotNull($path);

            MenuBar::closeMenu();
            $this->repaint($cols);
            MenuBar::openMenu($path[0] + 1);

            // Open every panel the leaf lives in, the way a click trail would:
            // one selectItem() per INTERMEDIATE submenu row, each ENTERING
            // (never choosing). The leaf itself is clicked by the leg below.
            $last = count($path) - 1;
            for ($d = 1; $d < $last; $d++) {
                $prefix = implode('.', array_map(static fn (int $r): int => $r + 1, array_slice($path, 1, $d)));
                self::assertNull(
                    MenuBar::selectItem($prefix),
                    "clicking the submenu row {$prefix} for {$leaf->id} at {$cols} cols must open it, not fire",
                );
            }

            $clickPath = implode('.', array_map(static fn (int $r): int => $r + 1, array_slice($path, 1)));

            // The zone census needs the REAL chrome pass — the component
            // render marks a string; the scanner registry belongs to
            // TuiRenderer::renderView(), which also splices the open panels.
            TuiRenderer::renderView($app, $cols, 30);
            $zones = TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_ITEM_ZONE_PREFIX);
            self::assertArrayHasKey(
                MenuBar::MENU_ITEM_ZONE_PREFIX . $clickPath,
                $zones,
                "{$leaf->id} has no clickable zone (menuitem:{$clickPath}) at {$cols} cols",
            );

            $msg = MenuBar::selectItem($clickPath);
            self::assertNotNull($msg, "the click door on menuitem:{$clickPath} does not dispatch at {$cols} cols");
            self::assertSame($leaf->id, $msg->action, 'a clicked row raises exactly the id it paints');
            self::assertSame(0, MenuBar::getActiveMenu(), 'a click that chooses closes the menu');
        }
    }

    public function testThePaintedTitleSetIsContiguousFromTheLeftAtEveryWidth(): void
    {
        // The fold law: titles drop from the RIGHT. Whatever is painted is the
        // contiguous left block menu:1..menu:n, n never exceeds the effective
        // root count, every effective root is painted once any fold engages
        // (the `»` slot IS a root), and at full width all five titles answer
        // the mouse. At the narrow end the tab strip eats the whole budget and
        // nothing is painted — the keyboard sweeps above still reach every
        // leaf there.
        foreach (self::WIDTHS as $cols) {
            MenuBar::closeMenu();
            $this->repaint($cols);
            $roots = count(MenuBar::tree()->menus);
            $keys = array_keys(TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_TITLE_ZONE_PREFIX));
            $nums = array_map(static fn (string $z): int => (int) substr($z, strlen(MenuBar::MENU_TITLE_ZONE_PREFIX)), $keys);
            sort($nums);

            if ($nums !== []) {
                self::assertSame(range(1, max($nums)), $nums, "painted menu titles at {$cols} cols must be the contiguous left block");
                self::assertLessThanOrEqual($roots, max($nums), "no title zone beyond the effective roots at {$cols} cols");
            }

            if ($cols >= 200) {
                self::assertSame([1, 2, 3, 4, 5], $nums, "all five shell titles are clickable at full width");
            }

            if ($cols <= 40) {
                self::assertSame([], $nums, "the tab strip leaves no bar budget at {$cols} cols — nothing painted");
            }
        }
    }

    public function testTheMediaFormIsReachableThroughTheFold(): void
    {
        // R-MENU-1: the bare /generate command is hidden from the palette; the
        // menu row IS its door. The README advertises F10 → → ENTER — and AT
        // 80 COLUMNS THAT MEANS THE FOLD: the chain repaints 80 first, so
        // compose() records the fold and the title ring the keys walk is the
        // painted one, "Session Agent »" — the third root is the `»` slot,
        // not Tools. The advertised keystroke therefore needs TWO Enters at
        // this width: the first ENTERS the fold's shed Tools row (a submenu —
        // chosen stays null, the cursor path grows one level), the second
        // CHOOSES cmd:generate-form. The choice raises exactly the command
        // the palette row and Ctrl+D raise: a closure emitting
        // OpenMediaFormMsg, which the update loop delivers one hop later
        // (AppModelTest's law).
        $this->repaint(80);
        $app = $this->barApp();

        $titles = array_keys(TuiRenderer::chromeScanner()->prefixed(MenuBar::MENU_TITLE_ZONE_PREFIX));
        sort($titles);
        self::assertSame(
            ['menu:1', 'menu:2', 'menu:3'],
            $titles,
            'the 80-col chrome pass paints Session, Agent and the » fold — three titles, not five',
        );
        $folded = MenuBar::tree();
        self::assertCount(3, $folded->menus, 'the fold replaced the five-title ring at 80 cols');
        self::assertSame(MenuBar::MORE_MENU_ID, $folded->menus[2]->id, 'the third painted title IS the » fold');

        [$app] = $app->update(new KeyMsg(KeyType::F10));
        self::assertSame(1, MenuBar::getActiveMenu(), 'F10 opened the first shell menu');

        [$app] = $app->update(new KeyMsg(KeyType::Right));
        self::assertSame(2, MenuBar::getActiveMenu());

        [$app] = $app->update(new KeyMsg(KeyType::Right));
        self::assertSame(3, MenuBar::getActiveMenu(), 'two rights land on the » fold, not on Tools directly');
        $under = MenuBar::tree()->node(MenuBar::cursor()->absolutePath());
        self::assertNotNull($under, 'the fold root has a highlighted row');
        self::assertSame('menu:tools', $under->id, 'the fold row under the cursor is the shed Tools submenu');

        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd, 'Enter on a submenu row enters it — it must not choose or dispatch');
        self::assertSame(3, MenuBar::getActiveMenu(), 'the menu stays open one level deeper');
        self::assertCount(2, MenuBar::cursor()->rows, 'Tools is now an entered level of the cursor path');
        $under = MenuBar::tree()->node(MenuBar::cursor()->absolutePath());
        self::assertNotNull($under, 'the entered Tools menu has a highlighted row');
        self::assertSame('cmd:generate-form', $under->id, 'Tools row 0 is Generate image');

        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        self::assertSame(0, MenuBar::getActiveMenu(), 'choosing closes the menu');
        self::assertInstanceOf(\Closure::class, $cmd, 'the row raises its command as the palette door does');

        [$app] = $app->update($cmd());

        self::assertNotNull($app->mediaForm, 'the 80-col fold walk F10 → → ENTER ENTER must open the interactive generation form');
    }
}
