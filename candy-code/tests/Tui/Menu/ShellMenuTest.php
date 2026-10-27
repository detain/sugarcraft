<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Menu;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Menu\MenuNode;
use SugarCraft\Code\Tui\Menu\MenuNodeKind;
use SugarCraft\Code\Tui\Menu\MenuTree;
use SugarCraft\Code\Tui\Menu\ShellMenu;
use SugarCraft\Code\Tui\Pane;

/**
 * The honesty of {@see ShellMenu} is this file: the tree is a SECOND list
 * beside the registry's categories, and only the check in both directions
 * keeps it true. PLACEMENT names the registry lacks never paint as ghost
 * rows; registry rows PLACEMENT lacks still reach the operator through
 * App ▸ More ▸ — and EITHER drift reddens a test here, so a command cannot
 * join (or leave) the registry without its author placing it in a menu.
 *
 * The pane rows are pinned against `Pane::tabCycle()` itself, never against
 * a copy of it, and the checks against a real App's `isDocked` — the same
 * source the bar's tab strip paints from.
 */
final class ShellMenuTest extends TestCase
{
    /** The command-name shape every spec file answers to. */
    private const NAME_SHAPE = '/^[a-z][a-z-]*$/';

    private string $locale;

    protected function setUp(): void
    {
        // Labels are Lang::t()/paletteLabel text; a developer's LANG must not
        // decide what these pins see.
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    // ── the two-directional registry↔PLACEMENT law ─────────────────────

    public function testEveryRegistryRowIsPlacedExactlyOnceAndNoPlacementIsABluff(): void
    {
        $placement = self::placementNames();
        sort($placement);

        $unique = array_values(array_unique($placement));
        self::assertSame($unique, $placement, 'a command placed twice would paint two rows dispatching the same thing');

        $registry = array_map(static fn(CommandSpec $s): string => $s->name, CommandRegistry::all());
        $expect = array_values(array_filter(
            $registry,
            static fn(string $name): bool => !in_array($name, ShellMenu::HIDDEN, true),
        ));
        sort($expect);

        self::assertSame(
            $expect,
            $placement,
            'every registry row is placed exactly once; every placement names a live row; HIDDEN rows are not placed',
        );
    }

    public function testTheHiddenSetIsBareGenerateAlone(): void
    {
        self::assertSame(['generate'], ShellMenu::HIDDEN);
        self::assertNull(ShellMenu::tree()->pathOf('cmd:generate'), 'a hidden row must not reach any menu, not even More');
        self::assertNotNull(ShellMenu::tree()->pathOf('cmd:generate-form'));
    }

    public function testAnInjectedUnplacedSpecLandsInAppMore(): void
    {
        $rows = CommandRegistry::all();
        $rows[] = new CommandSpec(
            name: 'zzz-unplaced',
            description: 'Placed nowhere',
            category: 'Session',
        );

        $tree = ShellMenu::tree(rows: $rows);
        $path = $tree->pathOf('cmd:zzz-unplaced');

        self::assertIsArray($path, 'an unplaced row must still be reachable — App ▸ More ▸ is its runtime home');
        self::assertCount(3, $path);

        $appMenu = $tree->rows([])[4];
        self::assertSame('menu:app', $appMenu->id);
        self::assertSame('sub:more', $appMenu->children[$path[1]]->id, 'More sits after Help, before the separator and Exit');
        self::assertSame('cmd:zzz-unplaced', $tree->node($path)?->id);
    }

    public function testTheShippedTreeHasNoMoreSubmenuBecauseNothingIsUnplaced(): void
    {
        self::assertNull(ShellMenu::tree()->pathOf('sub:more'));
    }

    // ── the bar itself ──────────────────────────────────────────────────

    public function testTheBarIsFiveMenusAndTheirTitlesFitTheBudget(): void
    {
        $menus = ShellMenu::tree()->rows([]);

        self::assertGreaterThanOrEqual(4, count($menus));
        self::assertLessThanOrEqual(6, count($menus));

        $cells = 0;
        foreach ($menus as $menu) {
            $cells += Width::of($menu->label);
        }

        self::assertLessThanOrEqual(40, $cells, 'the five titles beside the Currently indicator fit 80 cols');
    }

    public function testGenerateImageIsRowZeroOfTools(): void
    {
        $tree = ShellMenu::tree();
        $tools = $tree->rows([])[2];

        self::assertSame('menu:tools', $tools->id);
        self::assertSame('cmd:generate-form', $tools->children[0]->id);
        self::assertSame('Generate image…', $tools->children[0]->label);

        // Menu-only is exactly that: the spec's own palette label is untouched.
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->name === 'generate-form') {
                self::assertSame('Generate image', $spec->label(), 'the palette still says what MediaFormKeysTest drives');
            }
        }
    }

    // ── View ▸ Panes ▸ ──────────────────────────────────────────────────

    public function testPanesRowsAreTheTabCycleItselfInItsOwnOrder(): void
    {
        $tree = ShellMenu::tree();
        $path = $tree->pathOf('sub:panes');
        self::assertIsArray($path, 'View ▸ Panes ▸ is always present — tabCycle is never empty');

        $panes = $tree->node($path);
        self::assertNotNull($panes);

        $expected = array_map(static fn(Pane $p): string => 'pane:' . $p->value, Pane::tabCycle());
        self::assertSame($expected, array_map(static fn(MenuNode $n): string => $n->id, $panes->children));

        foreach (Pane::tabCycle() as $index => $pane) {
            self::assertSame($pane->label(), $panes->children[$index]->label);
            self::assertSame($pane->icon(), $panes->children[$index]->icon);
            self::assertSame(MenuNodeKind::Toggle, $panes->children[$index]->kind);
        }
    }

    public function testPaneChecksFollowIsDockedAndChatIsAlwaysCheckedWithAnApp(): void
    {
        // App's default dock already holds Files in a side slot, so a plain
        // App exercises the checked polarity without any dock mutation.
        $app = App::new($this->createMock(ProviderInterface::class), 'test-model')
            ->withChat(new Chat());

        self::assertTrue($app->isDocked(Pane::Files), 'the precondition: Files really is docked here');

        $tree = ShellMenu::tree($app);
        $panes = $tree->node($tree->pathOf('sub:panes'));
        self::assertNotNull($panes);

        foreach (Pane::tabCycle() as $index => $pane) {
            $expected = $pane === Pane::Chat
                ? true // focus-only, the frame's center column: always on
                : $app->isDocked($pane);

            self::assertSame($expected, $panes->children[$index]->checked, $pane->name);
        }

        self::assertTrue($panes->children[1]->checked, 'a docked Files lights its check');
    }

    public function testNullAppKeepsTheStructureAndClearsEveryCheck(): void
    {
        $bare = ShellMenu::tree();
        $docked = ShellMenu::tree(App::new($this->createMock(ProviderInterface::class), 'test-model')->withChat(new Chat()));

        self::assertSame(self::projection($bare), self::projection($docked), 'the same ids, labels, icons and shapes — keyboard navigation needs no App');

        $panes = $bare->node($bare->pathOf('sub:panes'));
        self::assertNotNull($panes);

        foreach ($panes->children as $row) {
            self::assertFalse($row->checked, 'with no App to ask, every check is false');
        }

        $dockedPanes = $docked->node($docked->pathOf('sub:panes'));
        self::assertNotNull($dockedPanes);

        $anyChecked = false;
        foreach ($dockedPanes->children as $row) {
            $anyChecked = $anyChecked || $row->checked;
        }

        self::assertTrue($anyChecked, 'the projection ignores checked; at least Chat is on with an App — the pair proves both files moved');
    }

    // ── geometry and shape laws ─────────────────────────────────────────

    public function testEveryLabelFitsTheMenuBudget(): void
    {
        $budget = CommandRegistry::MAX_MENU_LABEL_CELLS + 2; // +2 for '…'/'▸'

        foreach (ShellMenu::tree()->rows([]) as $menu) {
            self::assertLessThanOrEqual($budget, Width::of($menu->label), $menu->id);

            foreach (self::walk($menu) as $node) {
                self::assertLessThanOrEqual($budget, Width::of($node->label), $node->id);
            }
        }
    }

    public function testSubmenusAreAtMostTwoDeepAndNoneIsEmpty(): void
    {
        foreach (ShellMenu::tree()->rows([]) as $menu) {
            self::assertNotEmpty($menu->children, $menu->id);

            foreach ($menu->children as $row) {
                if ($row->kind !== MenuNodeKind::Submenu) {
                    continue;
                }

                self::assertNotEmpty($row->children, $row->id . ' is an empty submenu');

                foreach ($row->children as $deeper) {
                    self::assertNotSame(MenuNodeKind::Submenu, $deeper->kind, $deeper->id . ' would make the tree three deep');
                }
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Every command name PLACEMENT mentions, read off the const itself — the
     * guard checks the declaration, not a second copy of it. Markers (the
     * separator and the '@'-prefixed derivations) answer to no command-name
     * shape and drop out.
     *
     * @return list<string>
     */
    private static function placementNames(): array
    {
        $placement = (new ReflectionClass(ShellMenu::class))
            ->getReflectionConstant('PLACEMENT')
            ->getValue();

        $names = [];

        foreach ($placement as $entries) {
            foreach ($entries as $entry) {
                if (is_string($entry)) {
                    if (preg_match(self::NAME_SHAPE, $entry) === 1) {
                        $names[] = $entry;
                    }

                    continue;
                }

                $declared = $entry[array_key_first($entry)];

                if (is_array($declared)) {
                    foreach ($declared as $name) {
                        self::assertMatchesRegularExpression(self::NAME_SHAPE, $name);
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * The tree minus every check: the shape a null-App frame and a docked
     * frame must agree on.
     *
     * @return list<array{kind:string,id:string,label:string,icon:string}>
     */
    private static function projection(MenuTree $tree): array
    {
        $out = [];

        foreach ($tree->rows([]) as $menu) {
            $out[] = ['menu', $menu->kind->name, $menu->id, $menu->label, $menu->icon];

            foreach (self::walk($menu) as $node) {
                $out[] = ['node', $node->kind->name, $node->id, $node->label, $node->icon];
            }
        }

        return $out;
    }

    /**
     * @return iterable<MenuNode>
     */
    private static function walk(MenuNode $node): iterable
    {
        foreach ($node->children as $child) {
            yield $child;

            yield from self::walk($child);
        }
    }
}
