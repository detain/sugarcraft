<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Lang;
use SugarCraft\Style\Style;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Menu\MenuCascade;
use SugarCraft\Code\Tui\Menu\MenuCursor;
use SugarCraft\Code\Tui\Menu\MenuNode;
use SugarCraft\Code\Tui\Menu\MenuPanel;
use SugarCraft\Code\Tui\Menu\MenuStep;
use SugarCraft\Code\Tui\Menu\MenuTree;
use SugarCraft\Code\Tui\Menu\ShellMenu;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Mouse\Mark;

/**
 * The shell's top menu bar: the command menus on the left, the quick-switch
 * pane tabs and the current-pane indicator on the right.
 *
 * The menus come from {@see ShellMenu} — the five-title placement the plan
 * ruled (R-MENU-1) over the {@see MenuTree} the command registry feeds. The
 * bar itself owns no menu data any more; what it owns is the cursor
 * ({@see MenuCursor}, one static replacing the old menu-index/row-index
 * pair), the width folding of {@see compose()}, and the geometry handoff to
 * {@see MenuCascade}.
 */
final class MenuBar
{
    /**
     * Zone-id prefix every clickable menu TITLE carries (crush_feat.md §8 E6:
     * "on click, dispatch the same Msg/Cmd the Enter key currently
     * dispatches"). The suffix is the 1-based painted-title index
     * {@see openMenu()} takes — including the `»` overflow slot as a title —
     * so a click is the mouse spelling of F10-then-arrows.
     */
    public const MENU_TITLE_ZONE_PREFIX = 'menu:';

    /**
     * Zone-id prefix every clickable dropdown ROW carries. The suffix is the
     * 1-based dotted path {@see selectItem()} takes (`3` is the bar panel's
     * third row; `3.1` is that submenu's first row) — the same dot path the
     * kit derives from the cursor's position, so a clicked row and a
     * keyboard-confirmed row are one dispatch.
     */
    public const MENU_ITEM_ZONE_PREFIX = 'menuitem:';

    /**
     * Zone-id prefix every clickable PANE-TAB label in the strip carries.
     * The suffix is the pane's enum value, so the click routes through
     * {@see \SugarCraft\Code\Tui\Pane::tryFrom()} exactly like a docked
     * pane's header zone does — and lands in the same
     * `App::dispatchChromeClick()` door.
     *
     * What the click MEANS is the docking phase's rule: on a dockable
     * pane's tab it toggles that pane's docked visibility through
     * {@see \SugarCraft\Code\App\App::togglePaneFromChrome()} — the one door
     * the View ▸ Panes menu row now shares — and on Chat's tab it focuses
     * the always-visible center column without toggling anything, because
     * Chat has no dock slot to lose.
     */
    public const PANE_TAB_ZONE_PREFIX = 'panetab:';

    /**
     * Id of the `»` overflow title's pseudo-menu — the node {@see compose()}
     * folds shed titles into. Prefixed `menu:` because it IS a painted
     * title; distinct from ShellMenu's `sub:more` (App's leftover-rows
     * submenu) so the two can never be confused by an id lookup.
     */
    public const MORE_MENU_ID = 'menu:more';

    /**
     * The bar's menus: one entry per PAINTED title (or, before the first
     * frame, per title the full tree would paint), each holding its
     * dropdown's row labels in order — separators and submenu titles
     * included, because every row on screen is a row of the list.
     *
     * Derived rather than declared. {@see ShellMenu} reads
     * {@see CommandRegistry} — already the ONE list the "/" popup and the
     * Ctrl+P palette both read (W2.S8a) — so a command added to the registry
     * shows up in every surface at once and a menu can no longer advertise
     * something the app cannot do. The hand-written table this replaced had
     * labels no dispatcher answered; this one cannot drift because its rows
     * ARE the tree.
     *
     * @return array<string, list<string>>
     */
    private static function menus(): array
    {
        $menus = [];
        foreach (self::tree()->menus as $root) {
            $labels = [];
            foreach ($root->children as $node) {
                $labels[] = $node->label;
            }
            $menus[$root->label] = $labels;
        }

        return $menus;
    }

    /**
     * Panes surfaced as quick-switch tabs in the bar, in display order.
     *
     * Each is also a click-to-toggle target ({@see PANE_TAB_ZONE_PREFIX}):
     * a dockable one's tab shows its docked visibility (highlighted when
     * docked, dimmed when not) and toggles it; Chat's tab only focuses the
     * center column, which is always visible.
     *
     * @var list<Pane>
     */
    private const PANE_TABS = [
        Pane::Chat,
        Pane::Files,
        Pane::Tools,
        Pane::Skills,
        Pane::Agents,
        Pane::Settings,
        Pane::Todo,
        Pane::Context,
    ];

    /**
     * The strip's three densities, widest first ({@see density()}): every tab
     * `[icon Label]` beside `Currently: Label`; every tab its icon alone
     * (`[icon]`) with the indicator still naming the focused pane; and, when
     * even that would be cut, the indicator's bare label.
     */
    private const DENSITY_FULL = 0;

    private const DENSITY_ICONS = 1;

    private const DENSITY_TERSE = 2;

    /**
     * The whole menu state: which title is open, which row each open panel
     * highlights, and whether a hover preview is showing. One cursor where
     * the file used to keep `$activeMenu` and `$activeItem` — a pair that
     * could not express an open submenu at all, which is half of why the bar
     * needed the kit. Null means closed.
     */
    private static ?MenuCursor $cursor = null;

    /**
     * The tree the LAST composed frame painted: the full five-title tree
     * when every title fit, or one with the shed titles folded into a `»`
     * submenu when they did not. Null before the first frame and whenever no
     * fold was needed. Navigation, clicks and panels all read this, so a
     * title the bar never painted is a title no click can name.
     */
    private static ?MenuTree $effective = null;

    /**
     * The cursor, or a closed one when the menu is shut.
     *
     * The identity of the stored instance is what lets {@see handleKey()}'s
     * caller tell "the menu answered this key" from "the key fell through":
     * an unbound key returns a step whose cursor IS the stored instance.
     */
    public static function cursor(): MenuCursor
    {
        return self::$cursor ??= MenuCursor::closed();
    }

    /**
     * The tree navigation reads: the last painted fold when there is one,
     * the structural placement otherwise. Positional indexes agree with the
     * screen at whatever width the fold was recorded at.
     */
    public static function tree(): MenuTree
    {
        return self::$effective ?? ShellMenu::tree();
    }

    /**
     * @param int|null $cols Width the bar has to fit in. Null renders the full
     *                       bar, which is what a caller measuring the bar on
     *                       its own wants; the shell frame passes the terminal
     *                       width because it must not emit an over-wide line.
     */
    public static function render(App $a, ?int $cols = null): string
    {
        return self::compose($a, $cols, false);
    }

    /**
     * The very same bar, with every menu title AND every pane-tab label
     * wrapped in a sugar-mouse click zone ({@see MENU_TITLE_ZONE_PREFIX},
     * {@see PANE_TAB_ZONE_PREFIX}).
     *
     * A SEPARATE render rather than a flag on the painted one, because the
     * sentinels are Private-Use cells that
     * {@see \SugarCraft\Code\Tui\Renderer}'s width clip and
     * `Layout::joinHorizontal()` would both measure as content — the same
     * reason {@see \SugarCraft\Code\Renderer::scanRoot()} gives for never
     * marking a row in place. So the shell paints {@see render()} and scans
     * THIS, whose layout arithmetic is identical because both come from
     * {@see compose()}.
     */
    public static function renderMarked(App $a, ?int $cols = null): string
    {
        return self::compose($a, $cols, true);
    }

    /**
     * @param bool $marked Whether menu titles carry zone sentinels.
     */
    private static function compose(App $a, ?int $cols, bool $marked): string
    {
        $theme = $a->theme();
        // The budget is measured on the PLAIN strip even in the marked pass:
        // the zone sentinels are Private-Use cells Width counts as content,
        // and the whole point of the marked twin is that its visible columns
        // land where the painted ones do.
        $density = self::density($a, $theme, $cols);
        $tabsMeasure = self::paneTabs($a, $theme, false, $density);

        // The tab strip and the "Currently:" indicator are how the shell is
        // navigated, so when the terminal cannot hold the whole bar it is the
        // MENU NAMES that give way — dropped from the right, rarest-used
        // first — instead of the navigational tail being cut off the end.
        $budget = $cols === null ? null : $cols - Width::string($tabsMeasure) - 1;

        [$roots, $folded] = self::fold(ShellMenu::tree($a)->menus, $budget);
        // Record what this frame's title row IS, so the cursor, the panels
        // and the click parser all speak the painted tree — never the full
        // one a shed title belongs to no more.
        self::$effective = $folded ? MenuTree::new($roots) : null;

        $output = ' ';
        foreach ($roots as $menuIndex => $root) {
            $index = $menuIndex + 1;
            $isActive = self::cursor()->menu === $index;
            // The palettes carry ONE yellow (`warning`), so the bar's two
            // hand-picked yellows (#fde68a title, #e0af68 amber) collapse onto
            // it; the open menu switches to `primary` instead of merely going
            // bold, because `warning` and `primary` are distinct hues in all
            // five palettes and bold alone is not a colour cue at all.
            $color = $isActive ? $theme->shellPrimary : $theme->shellWarning;
            $title = Style::new()->foreground($color)->bold()->render($root->label);
            $output .= $marked
                ? Mark::zone(self::MENU_TITLE_ZONE_PREFIX . $index, $title)
                : $title;
            $output .= '   ';
        }
        $output .= ' ';

        return $output . self::paneTabs($a, $theme, $marked, $density);
    }

    /**
     * Which titles a bar of $budget cells paints.
     *
     * The old rule was a `break`: the first title that did not fit, and
     * every title after it, simply vanished — no key or click could reach
     * the commands they led to. The fold keeps the same budget arithmetic
     * (one leading cell, each name plus a three-space gap) but spends the
     * last cells on a `»` title instead of on silence: the shed roots become
     * children of `»`, so every row of every menu stays reachable at every
     * width, through a submenu of submenus. If even `»` cannot fit beside
     * the tab strip it is painted anyway at the head of what the frame will
     * clip — a door no frame may lose.
     *
     * Null budget (a standalone measuring render) folds nothing.
     *
     * @param list<MenuNode> $roots
     *
     * @return array{0: list<MenuNode>, 1: bool} the titles to paint, and
     *                                           whether a fold happened
     */
    private static function fold(array $roots, ?int $budget): array
    {
        if ($budget === null || $roots === []) {
            return [$roots, false];
        }

        $moreCost = Width::string(Lang::t('tui.menu.more')) + 3;

        $keep = count($roots);
        $spend = static fn(int $k): int => 1 + array_sum(array_map(
            static fn(MenuNode $r): int => Width::string($r->label) + 3,
            array_slice($roots, 0, $k),
        ));

        while ($keep > 0 && $spend($keep) > $budget) {
            $keep--;
        }

        if ($keep < count($roots)) {
            // Leave room for the `»` slot too — and if even `»` alone cannot
            // fit beside the tab strip, paint no titles at all (the old
            // `break` behaviour): the frame's width law outranks the door,
            // which stays fully keyboard-openable on the structural tree.
            while ($keep >= 0 && 1 + $moreCost > $budget) {
                if ($keep === 0) {
                    return [[], false];
                }

                $keep--;
            }

            while ($keep > 0 && $spend($keep) + $moreCost > $budget) {
                $keep--;
            }

            $dropped = array_splice($roots, $keep);
            $roots[] = MenuNode::submenu(self::MORE_MENU_ID, Lang::t('tui.menu.more'), $dropped);

            return [$roots, true];
        }

        return [$roots, false];
    }

    /**
     * How dense the tab strip must be to fit $cols (roadmap 3.C remainder).
     *
     * The full strip is about ninety cells since the Todo tab, so below
     * roughly a hundred columns it left room for no menu at all — F10 opened
     * a dropdown under a title nobody could see — and below ninety-odd it ran
     * off the edge, where the frame's clip cut the "Currently:" indicator and
     * then the tabs themselves. So the full strip is kept only while the
     * FIRST menu still fits beside it; narrower, every tab shrinks to its
     * icon (the indicator still names the focused pane, and every tab keeps
     * its click zone); narrower still, the indicator drops its "Currently:"
     * prefix. Null — a caller measuring the bar on its own — is the full
     * strip.
     */
    private static function density(App $a, Theme $theme, ?int $cols): int
    {
        if ($cols === null) {
            return self::DENSITY_FULL;
        }

        $first = array_key_first(self::menus());
        $menu = $first === null ? 0 : Width::string((string) $first) + 3;
        // One leading cell, the first menu, the cell before the strip.
        if ($cols >= 2 + $menu + Width::string(self::paneTabs($a, $theme, false, self::DENSITY_FULL))) {
            return self::DENSITY_FULL;
        }

        return $cols >= 2 + Width::string(self::paneTabs($a, $theme, false, self::DENSITY_ICONS))
            ? self::DENSITY_ICONS
            : self::DENSITY_TERSE;
    }

    /**
     * Render the quick-switch pane tabs plus the current-pane indicator.
     *
     * Every tab is `[icon Label]`, the picture composed from
     * {@see \SugarCraft\Code\Tui\Pane::icon()} — the same mapping the pane's
     * own frame title uses, so the strip and the frame always agree.
     *
     * Three visual states per tab, so both state dimensions are legible at
     * once — which panes are ON, and which one has focus:
     *
     * - docked + focused: `shellPrimary`, bold, underlined;
     * - docked (enabled), not focused: `shellForeground`;
     * - not docked: `shellMuted` (dimmed — clicking it turns the pane on).
     *
     * Chat is the center column and always visible, so it only ever shows
     * the two focus states. With `$marked` every label also carries a
     * {@see PANE_TAB_ZONE_PREFIX} click zone — scan-only, for the reason
     * {@see renderMarked()} gives. $density is {@see density()}'s answer: a
     * narrower one drops each tab's label, then the indicator's prefix, and
     * changes nothing else.
     */
    private static function paneTabs(App $a, Theme $theme, bool $marked, int $density = self::DENSITY_FULL): string
    {
        $tabs = ' ';
        foreach (self::PANE_TABS as $pane) {
            $focused = $a->pane === $pane;
            // A non-dockable pane has no visibility to toggle and is always
            // on screen (Chat is the frame's center column), so it counts as
            // enabled for the highlight rule.
            $enabled = !$pane->dockable() || $a->isDocked($pane);

            $style = match (true) {
                $focused => Style::new()->foreground($theme->shellPrimary)->bold()->underline(),
                $enabled => Style::new()->foreground($theme->shellForeground),
                default => Style::new()->foreground($theme->shellMuted),
            };

            $label = $style->render($density === self::DENSITY_FULL
                ? '[' . $pane->icon() . ' ' . $pane->label() . ']'
                : '[' . $pane->icon() . ']');
            $tabs .= $marked
                ? Mark::zone(self::PANE_TAB_ZONE_PREFIX . $pane->value, $label)
                : $label;
            $tabs .= ' ';
        }

        $current = Style::new()->foreground($theme->shellWarning)
            ->render($density === self::DENSITY_TERSE ? $a->pane->label() : Lang::t('tui.menu.currently', ['pane' => $a->pane->label()]));

        return $tabs . ' ' . $current;
    }

    /**
     * Route one key to the open menu, persisting where the cursor lands.
     *
     * The keys are {@see MenuCursor::key()}'s now: arrows and vim spellings
     * move rows, left/right walk into and out of submenus and (from a leaf)
     * along the bar, Enter/o chooses, Esc/q peels one level. The old form of
     * this method returned `[menu index, ?msg]`, a pair that could not say
     * "I answered this key without moving" — the gap bug 4 fell through.
     * Returning the {@see MenuStep} lets the caller compare the cursor the
     * step carries against the one it watched going in: a different instance
     * means the menu answered.
     *
     * @param string $key     the key's lowercase name
     * @param int    $currentMenu advisory — the cursor is the truth
     */
    public static function handleKey(string $key, int $currentMenu = 0): MenuStep
    {
        $cursor = self::$cursor;
        if ($cursor === null) {
            return MenuStep::new(self::cursor());
        }

        $step = $cursor->key($key, self::tree());
        if ($step->chosen !== null) {
            self::closeMenu();
        } else {
            self::$cursor = $step->cursor->isClosed() ? null : $step->cursor;
        }

        return $step;
    }

    /**
     * The row's chosen-node message: the title that owns the row, its label,
     * and the kit id that names the dispatch — `cmd:<name>` or
     * `pane:<value>` — so `App::dispatchMenuSelection()` no longer has to
     * reverse-lookup a command from a display string.
     */
    public static function selectionMessage(MenuNode $node): MenuSelectedMsg
    {
        return new MenuSelectedMsg(self::rootTitleOf($node->id), $node->label, $node->id);
    }

    /**
     * The painted title that owns $id — for a row reached through the `»`
     * fold, the shed root's own label, so a message never says `»`.
     */
    private static function rootTitleOf(string $id): string
    {
        $path = self::tree()->pathOf($id);
        if ($path === null) {
            return '';
        }

        $root = self::tree()->menus[$path[0]] ?? null;
        if ($root === null) {
            return '';
        }

        if ($root->id === self::MORE_MENU_ID && isset($root->children[$path[1] ?? 0])) {
            return $root->children[$path[1] ?? 0]->label;
        }

        return $root->label;
    }

    /**
     * The row labels of the menu at $menuIndex (1-based, folded tree), or []
     * when there is no such menu. Separators and submenu titles are rows of
     * the list too — the count the help text and the drift fixture speak.
     *
     * @return list<string>
     */
    private static function itemsOf(int $menuIndex): array
    {
        $labels = [];
        foreach (self::tree()->rows([$menuIndex - 1]) as $node) {
            $labels[] = $node->label;
        }

        return $labels;
    }

    /**
     * Choose the dropdown row the dot path $path names and return the
     * message Enter on that row would have produced.
     *
     * The mouse half of {@see handleKey()}'s enter arm: $path is the click
     * zone's suffix — `2` for the bar panel's second row, `2.1` for the row
     * of the submenu that row opened — and the click is replayed as a hover
     * onto the path followed by Enter, so a clicked row and a
     * keyboard-confirmed row are indistinguishable downstream. There is no
     * click-only dispatch path that could drift from the keyboard one.
     *
     * Clicking a submenu row does not choose anything: it ENTERS the submenu
     * (the same thing Enter does on one) and returns null, menu still open.
     *
     * @param int|string $row 1-based dotted row path; a bare int reads the
     *                        same way, so the legacy `selectItem(2)` call is
     *                        "the second row", not the old zero-based index
     *
     * @return ?MenuSelectedMsg null when no menu is open, the path names no
     *                          row, or the row opened a submenu
     */
    public static function selectItem(int|string $row): ?MenuSelectedMsg
    {
        $cursor = self::$cursor;
        if ($cursor === null) {
            return null;
        }

        $parts = array_map('intval', explode('.', (string) $row));
        foreach ($parts as $part) {
            if ($part < 1) {
                return null;
            }
        }

        $tree = self::tree();
        $hovered = $cursor->hover(array_map(static fn(int $n): int => $n - 1, $parts), $tree);
        if ($hovered === $cursor) {
            // hover() returns its own instance when the path does not exist
            // or names a row no cursor may rest on (a separator, a disabled
            // command): refuse, exactly as the keyboard refuses those rows.
            return null;
        }

        $step = $hovered->key('enter', $tree);
        if ($step->chosen !== null) {
            $msg = self::selectionMessage($step->chosen);
            self::closeMenu();

            return $msg;
        }

        self::$cursor = $step->cursor;

        return null;
    }

    /**
     * The innermost highlighted row, zero-based within its panel. Exposed so
     * the shell can assert on (and a renderer can highlight) the row Enter
     * would select.
     */
    public static function activeItem(): int
    {
        $cursor = self::$cursor;

        return $cursor === null ? 0 : (int) ($cursor->rows === [] ? 0 : $cursor->rows[count($cursor->rows) - 1]);
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::activeItem()} instead. REMOVED-IN: 2.0.0.
     */
    public static function getActiveItem(): int { return self::activeItem(); }

    /**
     * The rows of one menu by title, or [] when no such menu exists.
     *
     * @return list<string>
     */
    public static function menuItems(string $menuName): array
    {
        return self::menus()[$menuName] ?? [];
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::menuItems()} instead. REMOVED-IN: 2.0.0.
     */
    public static function getMenuItems(string $menuName): array { return self::menuItems($menuName); }

    public static function closeMenu(): int
    {
        self::$cursor = null;
        self::$effective = null;

        return 0;
    }

    /**
     * Get the currently active menu index.
     */
    public static function activeMenu(): int
    {
        return self::$cursor?->menu ?? 0;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::activeMenu()} instead. REMOVED-IN: 2.0.0.
     */
    public static function getActiveMenu(): int { return self::activeMenu(); }

    /**
     * Open the menu bar at $index (1-based among the PAINTED titles — the
     * `»` fold slot counts), or close it if that menu is already open — the
     * toggle behaviour every menu key in a TUI has.
     *
     * This setter is the piece that was missing. The navigation was
     * complete but both its call sites were guarded by `getActiveMenu() > 0`
     * and nothing ever assigned a non-zero value — the bar rendered, and
     * could not be opened by keyboard OR mouse.
     *
     * @return int the menu index now active, 0 when closed
     */
    public static function openMenu(int $index = 1): int
    {
        $tree = self::tree();
        if ($tree->menus === []) {
            return self::activeMenu();
        }

        if (self::$cursor !== null && self::$cursor->menu === $index) {
            return self::closeMenu();
        }

        self::$cursor = MenuCursor::open($index, $tree);

        return self::activeMenu();
    }

    /**
     * The open menu's dropdown panel, or [] when nothing is open.
     *
     * Delegates the picture to {@see MenuCascade} — position-free (the root
     * panel pinned at column 1, line 0, no width cap, no window) so the
     * lines are exactly what {@see panels()} hands the splice, and the two
     * forwarders can never drift apart.
     *
     * @return list<string> one rendered line per row, borders included
     */
    public static function renderDropdown(Theme $theme): array
    {
        return self::dropdownLines($theme, false);
    }

    /**
     * The same dropdown with every ROW wrapped in a click zone
     * ({@see MENU_ITEM_ZONE_PREFIX}).
     *
     * Scan-only, for the reason {@see renderMarked()} gives: the painted
     * panel is spliced into the composed frame cell by cell, and a sentinel
     * inside the patch would be measured as a cell and shift the splice.
     *
     * @return list<string>
     */
    public static function renderDropdownMarked(Theme $theme): array
    {
        return self::dropdownLines($theme, true);
    }

    /**
     * @return list<string>
     */
    private static function dropdownLines(Theme $theme, bool $marked): array
    {
        $cursor = self::$cursor;
        if ($cursor === null) {
            return [];
        }

        $panels = MenuCascade::layout(
            self::tree(),
            $cursor,
            $theme,
            1,
            0,
            PHP_INT_MAX,
            PHP_INT_MAX,
            $marked,
        );

        return $panels === [] ? [] : $panels[0]->lines;
    }

    /**
     * The open menu as panel-kit geometry, positioned for a frame of $cols
     * whose menu bar occupies the rows above screen line $top.
     *
     * One panel per visible level — the bar panel and every submenu opened
     * under it (a hover preview counts as a level, and paints last) — with
     * the anchor the splice needs already resolved: `col` is the box's
     * 1-based left border and lands one cell right of where the title is
     * PAINTED. When the terminal sheds the title (the same budget
     * {@see compose()} applies), the cascade pulls the box left so it ends
     * inside $cols instead of hanging past the edge, and a child panel that
     * would cross the edge flips to its parent's left; the same rows the
     * overlay paints, {@see panelsMarked()} marks.
     *
     * $rows is the frame the panel is spliced into; null means "as tall as
     * the list" — the kit's windowing (`…` rows) only arms when the caller
     * says where the floor is. Today's renderer clips panels itself with its
     * own continuation row, so it passes nothing and stays byte-identical.
     *
     * Unlike the pre-kit bar — whose single panel carried empty `nodes` and
     * a zero `firstRowIndex` until a click-routing step filled them — every
     * panel here arrives filled by {@see MenuCascade}: the $j-th entry of
     * `nodes` is full dropdown row `firstRowIndex + $j`, and `path` names the
     * panel levels above it, so a click's zone id rebuilds as the 1-based
     * dotted path `[...path, firstRowIndex + $j]` with no bookkeeping left in
     * this class.
     *
     * @return list<MenuPanel>
     */
    public static function panels(App $a, int $cols, int $top, ?int $rows = null): array
    {
        return self::panelsFrom($a, $cols, $top, false, $rows);
    }

    /**
     * The same panels with the row zones of {@see renderDropdownMarked()}.
     *
     * @return list<MenuPanel>
     */
    public static function panelsMarked(App $a, int $cols, int $top, ?int $rows = null): array
    {
        return self::panelsFrom($a, $cols, $top, true, $rows);
    }

    /**
     * @return list<MenuPanel>
     */
    private static function panelsFrom(App $a, int $cols, int $top, bool $marked, ?int $rows): array
    {
        $cursor = self::$cursor;
        if ($cursor === null) {
            return [];
        }

        // The fold was recorded with a live App, so its View ▸ Panes rows
        // carry this frame's docked checks; before any fold was needed the
        // structural placement serves, rebuilt with $a for the same reason.
        $tree = self::$effective ?? ShellMenu::tree($a);

        return MenuCascade::layout(
            $tree,
            $cursor,
            $a->theme(),
            self::activeMenuColumn() + 1,
            $top,
            $cols,
            $rows ?? PHP_INT_MAX,
            $marked,
        );
    }

    /**
     * Column the open menu's title starts at, so its dropdown can be drawn
     * under it. Mirrors {@see compose()}'s own layout arithmetic (one leading
     * space, then each name plus a three-space gap) over the PAINTED titles —
     * the folded tree when there is one, `»` counting as a title — so a shed
     * menu's panel anchors at the fold's column instead of somewhere off
     * screen.
     */
    public static function activeMenuColumn(): int
    {
        $cursor = self::$cursor;
        if ($cursor === null || $cursor->menu < 1) {
            return 0;
        }

        $col = 1;
        foreach (self::tree()->menus as $index => $root) {
            if ($index + 1 === $cursor->menu) {
                return $col;
            }
            $col += Width::string($root->label) + 3;
        }

        return 0;
    }

    /**
     * Persist a menu index the keyboard layer navigated to.
     *
     * Non-toggling, unlike {@see openMenu()}: an index the cursor already
     * carries leaves it exactly where it is (submenus included), and a
     * different one opens that menu fresh. 0 closes. Kept for callers that
     * drive the bar by index; {@see handleKey()} no longer needs it because
     * it persists the cursor itself.
     */
    public static function activateMenu(int $index): void
    {
        if ($index < 1) {
            self::closeMenu();

            return;
        }

        $cursor = self::$cursor;
        if ($cursor !== null && $cursor->menu === $index) {
            return;
        }

        self::$cursor = MenuCursor::open($index, self::tree());
    }
}
