<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

use SugarCraft\Code\App\App;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Pane;

/**
 * The shell's five-menu bar (R-MENU-1): `Session · Agent · Tools · View · App`
 * as a {@see MenuTree} built from {@see CommandRegistry::all()} — the same
 * "derived, not declared" law {@see \SugarCraft\Code\Tui\Components\MenuBar}
 * was rebuilt on (its docblock at `MenuBar.php:59-73`), just nested.
 *
 * WHAT IS DECLARED AND WHAT IS NOT. PLACEMENT below declares only the tree
 * SHAPE: which menu and which submenu owns each command NAME. Every row's
 * LABEL is the live `CommandSpec::label()` — the palette's own text — so a
 * renamed row moves in all three surfaces at once, and a repeated label can
 * never run the wrong row because dispatch reads the id (`cmd:<name>`),
 * never the label. View ▸ Panes ▸ is not placed at all: its rows ARE
 * `Pane::tabCycle()`, so a new pane joins the menu the same commit it joins
 * the focus ring.
 *
 * THE TWO FAILURE MODES, BOTH PINNED BY `ShellMenuTest`:
 *  - a PLACEMENT name the registry lacks is dropped here — it never becomes
 *    a ghost row the bar advertises and no dispatcher answers — and the test
 *    goes red, so the pair cannot drift silently;
 *  - a registry row PLACEMENT does not name still lands at runtime in
 *    App ▸ More ▸ (never unreachable, no dead click), AND the test goes red,
 *    so its author has to place it deliberately.
 *
 * `HIDDEN` is the only name a row can have WITHOUT a menu entry: bare
 * `/generate` stays a slash command while row "Generate image…" (its own
 * menu-only label, the one text in this file not read off a spec) is its
 * door. Nothing reads ShellMenu until P-MENU.d swaps the bar over; this step
 * ships the tree and its guard.
 */
final class ShellMenu
{
    /**
     * Registry rows deliberately off the menu. `/generate` keeps working as
     * a slash command; the generation form's menu door is `generate-form`.
     */
    public const HIDDEN = ['generate'];

    /**
     * A tree entry that draws a divider. A command name can never collide
     * with it: spec files are named `[a-z][a-z-]*`
     * ({@see CommandSpec::$name}), and this is not either.
     */
    private const SEP = '─';

    /**
     * Marker: the submenu's rows come from {@see Pane::tabCycle()} at build
     * time, never from a declared list (the derived-not-declared law again).
     */
    private const PANES_CYCLE = '@panes';

    /**
     * Marker: the rows the registry holds and PLACEMENT never named collect
     * here at runtime, under App. Absent when there are none — a menu that
     * would be empty is not emitted.
     */
    private const RUNTIME_MORE = '@more';

    /** @var array<string, string> menu key => lang key of its bar title */
    private const TITLES = [
        'session' => 'tui.menu.title.session',
        'agent' => 'tui.menu.title.agent',
        'tools' => 'tui.menu.title.tools',
        'view' => 'tui.menu.title.view',
        'app' => 'tui.menu.title.app',
    ];

    /** @var array<string, string> submenu key => lang key of its header */
    private const SUBS = [
        'branch' => 'tui.menu.sub.branch',
        'history' => 'tui.menu.sub.history',
        'share' => 'tui.menu.sub.share',
        'automate' => 'tui.menu.sub.automate',
        'context' => 'tui.menu.sub.context',
        'knowledge' => 'tui.menu.sub.knowledge',
        'media' => 'tui.menu.sub.media',
        'review' => 'tui.menu.sub.review',
        'panes' => 'tui.menu.sub.panes',
        'layout' => 'tui.menu.sub.layout',
        'status' => 'tui.menu.sub.status',
        'help' => 'tui.menu.sub.help',
        'more' => 'tui.menu.sub.more',
    ];

    /**
     * The ONE menu-only label: `generate-form` reads "Generate image…" here
     * while its palette row keeps "Generate image" (the spec's paletteLabel,
     * which `MediaFormKeysTest` dispatches by). Every other label is the
     * spec's own.
     *
     * @var array<string, string> command name => lang key
     */
    private const MENU_LABELS = [
        'generate-form' => 'tui.menu.item.generate_image',
    ];

    /**
     * The tree as approved by R-MENU-1 ("Proposed menu tree" in
     * `prompt_kit/findings/comparison-1026/design-menu.md`), keyed by command
     * name. An entry is a command name, a marker (self::SEP /
     * self::RUNTIME_MORE), or a single-pair submenu array
     * `array<key, list<string>|self::PANES_CYCLE>`.
     *
     * @var array<string, list<string|array<string, list<string>|string>>>
     */
    private const PLACEMENT = [
        'session' => [
            'new', 'new-picker', 'close-tab', 'sessions', 'rename', 'session-pin', 'session-delete',
            self::SEP,
            ['branch' => ['branch', 'fork', 'handoff']],
            ['history' => ['compact', 'clear', 'rewind', 'undo', 'redo', 'diff']],
            ['share' => ['share', 'export']],
        ],
        'agent' => [
            'model', 'effort', 'agents', 'btw',
            self::SEP,
            ['automate' => ['goal', 'grind', 'loop', 'bg', 'shells', 'schedules', 'workflow']],
            ['context' => ['context', 'sweep', 'pruning', 'compress', 'decompress', 'recompress']],
            ['knowledge' => ['memory', 'skills', 'init', 'rules', 'newrule']],
        ],
        'tools' => [
            'generate-form',
            ['media' => ['play', 'sendto', 'rendermode']],
            ['review' => ['review', 'security-review']],
            'websearch', 'editor',
            self::SEP,
            'mcp', 'hooks',
        ],
        'view' => [
            ['panes' => self::PANES_CYCLE],
            ['layout' => ['pane-dock-left', 'pane-dock-right', 'pane', 'layout']],
            'theme',
        ],
        'app' => [
            'settings', 'permissions',
            ['status' => ['status', 'budget', 'usage', 'notices']],
            ['help' => ['keys', 'help', 'docs']],
            self::RUNTIME_MORE,
            self::SEP,
            'exit',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * The bar tree for one frame.
     *
     * @param list<CommandSpec>|null $rows the registry rows to place; null
     *                                     reads {@see CommandRegistry::all()}
     *                                     live, which is what every painted
     *                                     frame does. The parameter exists for
     *                                     the guard test to inject a row the
     *                                     shipped tree cannot know about.
     *
     * With `$a === null` every pane check is false while the STRUCTURE is
     * identical — keyboard navigation (and the guard test) need no App.
     */
    public static function tree(?App $a = null, ?array $rows = null): MenuTree
    {
        $rows ??= CommandRegistry::all();

        /** @var array<string, CommandSpec> */
        $specs = [];
        foreach ($rows as $spec) {
            $specs[$spec->name] = $spec;
        }

        $menus = [];

        foreach (self::PLACEMENT as $menuKey => $entries) {
            $nodes = [];

            foreach ($entries as $entry) {
                if (is_string($entry)) {
                    if ($entry === self::SEP) {
                        $nodes[] = MenuNode::separator();
                        continue;
                    }

                    if ($entry === self::RUNTIME_MORE) {
                        $more = self::moreRows($rows, self::declaredNames());

                        if ($more !== []) {
                            $nodes[] = MenuNode::submenu('sub:more', Lang::t(self::SUBS['more']), $more);
                        }

                        continue;
                    }

                    $spec = $specs[$entry] ?? null;

                    if ($spec === null) {
                        // A name the registry lacks never becomes a ghost row;
                        // ShellMenuTest fails on the drift instead.
                        continue;
                    }

                    $nodes[] = self::commandLeaf($spec);
                    continue;
                }

                $subKey = (string) array_key_first($entry);
                $declared = $entry[$subKey];
                $children = $declared === self::PANES_CYCLE
                    ? self::paneRows($a)
                    : self::commandRows($declared, $specs);

                if ($children !== []) {
                    // A submenu whose every name ghosted is not emitted —
                    // the test fails on that placement anyway.
                    $nodes[] = MenuNode::submenu('sub:' . $subKey, Lang::t(self::SUBS[$subKey]), $children);
                }
            }

            $menus[] = MenuNode::submenu('menu:' . $menuKey, Lang::t(self::TITLES[$menuKey]), $nodes);
        }

        return MenuTree::new($menus);
    }

    /**
     * @param list<string>         $names
     * @param array<string, CommandSpec> $specs
     *
     * @return list<MenuNode>
     */
    private static function commandRows(array $names, array $specs): array
    {
        $nodes = [];

        foreach ($names as $name) {
            $spec = $specs[$name] ?? null;

            if ($spec === null) {
                continue;
            }

            $nodes[] = self::commandLeaf($spec);
        }

        return $nodes;
    }

    /**
     * Registry rows PLACEMENT never named, in registry order, minus HIDDEN.
     *
     * The set of declared names is walked from PLACEMENT up front, not from
     * what the build has consumed so far: the marker sits mid-menu, and rows
     * placed after it (App ▸ exit) would otherwise double up inside More.
     *
     * @param list<CommandSpec>   $rows
     * @param array<string, true> $declared
     *
     * @return list<MenuNode>
     */
    private static function moreRows(array $rows, array $declared): array
    {
        $nodes = [];

        foreach ($rows as $spec) {
            if (isset($declared[$spec->name]) || in_array($spec->name, self::HIDDEN, true)) {
                continue;
            }

            $nodes[] = self::commandLeaf($spec);
        }

        return $nodes;
    }

    /**
     * Every command name PLACEMENT names, at any depth, before any menu is built.
     *
     * @return array<string, true>
     */
    private static function declaredNames(): array
    {
        $declared = [];

        $collect = static function (array $entries) use (&$collect, &$declared): void {
            foreach ($entries as $entry) {
                if (is_string($entry)) {
                    // Separators carry no name; '@' is the reserved marker
                    // prefix (PANES_CYCLE, RUNTIME_MORE) and never a command.
                    if ($entry !== self::SEP && !str_starts_with($entry, '@')) {
                        $declared[$entry] = true;
                    }

                    continue;
                }

                foreach ($entry as $children) {
                    if (is_array($children)) {
                        $collect($children);
                    }
                }
            }
        };

        foreach (self::PLACEMENT as $entries) {
            $collect($entries);
        }

        return $declared;
    }

    /**
     * View ▸ Panes ▸: one toggle per tab-cycle pane, checks read off the App.
     * Chat is focus-only but always on screen (the frame's center column,
     * never a dock slot), so its check is on whenever there is an App to ask.
     *
     * P-MENU.e: this is now the ONE pane-toggle surface. Each row carries
     * {@see MenuBar::PANE_TAB_ZONE_PREFIX} as its node zone, so the marked
     * twin paints the row with the same `panetab:<value>` click zone the old
     * menu-bar tab had — the shell's click and drag-to-dock arms keep
     * working off the row untouched. The tab's three visual states live on
     * as the cascade's native ones: the highlighted row is `shellPrimary`
     * bold, a docked row checks ✓, an undocked row is blank.
     *
     * @return list<MenuNode>
     */
    private static function paneRows(?App $a): array
    {
        $nodes = [];

        foreach (Pane::tabCycle() as $pane) {
            $nodes[] = MenuNode::toggle(
                'pane:' . $pane->value,
                $pane->label(),
                $a !== null && (!$pane->dockable() || $a->isDocked($pane)),
                $pane->icon(),
                zone: MenuBar::PANE_TAB_ZONE_PREFIX . $pane->value,
            );
        }

        return $nodes;
    }

    private static function commandLeaf(CommandSpec $spec): MenuNode
    {
        $menuOnly = self::MENU_LABELS[$spec->name] ?? null;

        return MenuNode::command(
            'cmd:' . $spec->name,
            $menuOnly === null ? $spec->label() : Lang::t($menuOnly),
        );
    }
}
