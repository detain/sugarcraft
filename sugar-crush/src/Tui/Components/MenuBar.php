<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Mouse\Mark;

/**
 * The shell's top menu bar: the command menus on the left, the quick-switch
 * pane tabs and the current-pane indicator on the right.
 *
 * The menus are read from {@see CommandRegistry} — see {@see menus()} for
 * why they are derived rather than declared.
 */
final class MenuBar
{
    /**
     * Zone-id prefix every clickable menu TITLE carries (crush_feat.md §8 E6:
     * "on click, dispatch the same Msg/Cmd the Enter key currently
     * dispatches"). The suffix is the 1-based menu index {@see openMenu()}
     * takes, so a click is the mouse spelling of F10-then-arrows.
     */
    public const MENU_TITLE_ZONE_PREFIX = 'menu:';

    /**
     * Zone-id prefix every clickable dropdown ROW carries. The suffix is the
     * zero-based row index {@see selectItem()} takes — the same cursor
     * up/down move and Enter read, so a clicked row runs the command it
     * names through exactly one confirm path.
     */
    public const MENU_ITEM_ZONE_PREFIX = 'menuitem:';

    /**
     * The bar's menus: one per {@see CommandSpec::$category}, holding that
     * category's rows in the registry's own declaration order.
     *
     * Derived rather than declared. {@see CommandRegistry} is already the ONE
     * list the "/" popup and the Ctrl+P palette both read (W2.S8a), and the
     * hand-written table this replaced was a third, parallel one — every row
     * of it ('Copy', 'Preferences', 'Export Chat', the provider names) was a
     * label no dispatcher anywhere in the codebase answered, while genuinely
     * dispatched commands (/compact, /branch, /rename, /rewind, /workflow,
     * /memory, /mcp, /share) appeared in neither menu. Deriving the menus
     * means a command added to the registry shows up in all three surfaces
     * and a menu can no longer advertise something the app cannot do.
     *
     * @return array<string, list<string>>
     */
    private static function menus(): array
    {
        $menus = [];
        foreach (CommandRegistry::all() as $spec) {
            $menus[$spec->category][] = $spec->label();
        }

        return $menus;
    }

    /**
     * Panes surfaced as quick-switch tabs in the bar, in display order.
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
    ];

    private static int $activeMenu = 0;

    /**
     * Zero-based row cursor INSIDE the open menu's dropdown.
     *
     * Without it {@see selectMenuItem()} could only ever name the menu, never
     * a row of it — which is why {@see MenuSelectedMsg::$item} was always the
     * empty string and pressing Enter on a menu could not dispatch anything.
     * Reset whenever the open menu changes, so opening a menu always starts
     * on its first row.
     */
    private static int $activeItem = 0;

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
     * The very same bar, with every menu title wrapped in a candy-mouse click
     * zone ({@see MENU_TITLE_ZONE_PREFIX}).
     *
     * A SEPARATE render rather than a flag on the painted one, because the
     * sentinels are Private-Use cells that
     * {@see \SugarCraft\Crush\Tui\Renderer}'s width clip and
     * `Layout::joinHorizontal()` would both measure as content — the same
     * reason {@see \SugarCraft\Crush\Renderer::scanRoot()} gives for never
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
        $tabs = self::paneTabs($a, $theme);

        // The tab strip and the "Currently:" indicator are how the shell is
        // navigated, so when the terminal cannot hold the whole bar it is the
        // MENU NAMES that give way — dropped from the right, rarest-used
        // first — instead of the navigational tail being cut off the end.
        $budget = $cols === null ? null : $cols - Width::string($tabs) - 1;

        $output = ' ';
        $width = 1;
        $menuIndex = 1;
        foreach (self::menus() as $name => $items) {
            $cost = Width::string($name) + 3;
            if ($budget !== null && $width + $cost > $budget) {
                break;
            }
            $isActive = self::$activeMenu === $menuIndex;
            // The palettes carry ONE yellow (`warning`), so the bar's two
            // hand-picked yellows (#fde68a title, #e0af68 amber) collapse onto
            // it; the open menu switches to `primary` instead of merely going
            // bold, because `warning` and `primary` are distinct hues in all
            // five palettes and bold alone is not a colour cue at all.
            $color = $isActive ? $theme->shellPrimary : $theme->shellWarning;
            $title = Style::new()->foreground($color)->bold()->render($name);
            $output .= $marked
                ? Mark::zone(self::MENU_TITLE_ZONE_PREFIX . $menuIndex, $title)
                : $title;
            $output .= '   ';
            $width += $cost;
            $menuIndex++;
        }
        $output .= ' ';

        return $output . $tabs;
    }

    /**
     * Render the quick-switch pane tabs plus the current-pane indicator.
     * The focused pane's tab is highlighted; the indicator drives the
     * "Currently: <Label>" hint the status line relies on.
     */
    private static function paneTabs(App $a, Theme $theme): string
    {
        $tabs = ' ';
        foreach (self::PANE_TABS as $pane) {
            $color = $a->pane === $pane ? $theme->shellPrimary : $theme->shellMuted;
            $tabs .= Style::new()->foreground($color)->render('[' . $pane->label() . ']');
            $tabs .= ' ';
        }

        $current = Style::new()->foreground($theme->shellWarning)
            ->render('Currently: ' . $a->pane->label());

        return $tabs . ' ' . $current;
    }

    /**
     * Route one key to the open menu.
     *
     * Up/down (and their vim spellings) move the {@see $activeItem} row
     * cursor: a dropdown that lists rows but cannot be moved through has no
     * row for Enter to name.
     *
     * @return array{0: int, 1: ?MenuSelectedMsg}
     */
    public static function handleKey(string $key, int $currentMenu): array
    {
        return match ($key) {
            'left', 'h' => [self::cycleMenu($currentMenu, -1), null],
            'right', 'l' => [self::cycleMenu($currentMenu, 1), null],
            'up', 'k' => [self::moveItem($currentMenu, -1), null],
            'down', 'j' => [self::moveItem($currentMenu, 1), null],
            'enter', 'o' => self::selectMenuItem($currentMenu),
            'escape', 'q' => [self::closeMenu(), null],
            default => [$currentMenu, null],
        };
    }

    private static function cycleMenu(int $currentMenu, int $direction): int
    {
        $count = count(self::menus());
        if ($count === 0) {
            return 0;
        }

        $new = $currentMenu + $direction;
        if ($new < 1) {
            $new = $count;
        }
        if ($new > $count) {
            $new = 1;
        }

        // A different menu has a different row list, so the cursor cannot
        // carry over — it would land on an unrelated (or out-of-range) row.
        self::$activeItem = 0;

        return $new;
    }

    /**
     * Move the dropdown's row cursor, wrapping at both ends the way the menu
     * strip itself wraps. Returns the menu index unchanged so the caller's
     * "did the menu change?" check stays meaningful.
     */
    private static function moveItem(int $currentMenu, int $direction): int
    {
        $count = count(self::itemsOf($currentMenu));
        if ($count === 0) {
            self::$activeItem = 0;

            return $currentMenu;
        }

        self::$activeItem = (self::$activeItem + $direction % $count + $count) % $count;

        return $currentMenu;
    }

    /**
     * The row labels of the menu at $menuIndex (1-based), or [] when there is
     * no such menu.
     *
     * @return list<string>
     */
    private static function itemsOf(int $menuIndex): array
    {
        $menus = self::menus();
        $names = array_keys($menus);
        $name = $names[$menuIndex - 1] ?? null;

        return $name === null ? [] : array_values($menus[$name]);
    }

    /**
     * @return array{0: int, 1: ?MenuSelectedMsg}
     */
    private static function selectMenuItem(int $menuIndex): array
    {
        $menus = self::menus();
        if ($menuIndex < 1 || $menuIndex > count($menus)) {
            return [$menuIndex, null];
        }

        $menuNames = array_keys($menus);
        $menuName = $menuNames[$menuIndex - 1] ?? '';
        $items = self::itemsOf($menuIndex);

        return [$menuIndex, new MenuSelectedMsg($menuName, $items[self::$activeItem] ?? '')];
    }

    /**
     * Choose the dropdown row at $row (zero-based) and return the message
     * Enter on that row would have produced.
     *
     * The mouse half of {@see handleKey()}'s `enter` arm: it moves the SAME
     * {@see $activeItem} cursor the arrow keys move and then goes through
     * {@see selectMenuItem()}, so a clicked row and a keyboard-confirmed row
     * are indistinguishable downstream — there is no click-only dispatch
     * path that could drift from the keyboard one.
     *
     * @return ?MenuSelectedMsg null when no menu is open, or the open menu
     *                          has no such row
     */
    public static function selectItem(int $row): ?MenuSelectedMsg
    {
        if (self::$activeMenu < 1) {
            return null;
        }

        if ($row < 0 || $row >= count(self::itemsOf(self::$activeMenu))) {
            return null;
        }

        self::$activeItem = $row;

        return self::selectMenuItem(self::$activeMenu)[1];
    }

    /**
     * The dropdown's zero-based row cursor. Exposed so the shell can assert
     * on (and a renderer can highlight) the row Enter would select.
     */
    public static function getActiveItem(): int
    {
        return self::$activeItem;
    }

    /**
     * The rows of one menu, or [] when no such menu exists.
     *
     * @return list<string>
     */
    public static function getMenuItems(string $menuName): array
    {
        return self::menus()[$menuName] ?? [];
    }

    public static function closeMenu(): int
    {
        self::$activeMenu = 0;
        self::$activeItem = 0;
        return 0;
    }

    /**
     * Get the currently active menu index.
     */
    public static function getActiveMenu(): int
    {
        return self::$activeMenu;
    }

    /**
     * Open the menu bar at $index (1-based), or close it if that menu is
     * already open — the toggle behaviour every menu key in a TUI has.
     *
     * This setter is the piece that was missing. {@see handleKey()} implements
     * full navigation (left/right/h/l between menus, enter/o to select, escape
     * to close) and {@see KeyboardHandler} routes to it, but BOTH call sites
     * are guarded by `getActiveMenu() > 0` and nothing ever assigned a
     * non-zero value — `$activeMenu` was only ever read or reset by {@see
     * closeMenu()}. So the bar rendered, and could not be opened by keyboard
     * OR mouse; the navigation was unreachable code.
     *
     * @return int the menu index now active, 0 when closed
     */
    public static function openMenu(int $index = 1): int
    {
        $count = count(self::menus());
        if ($count === 0 || $index < 1 || $index > $count) {
            return self::$activeMenu;
        }

        self::$activeItem = 0;

        return self::$activeMenu = self::$activeMenu === $index ? 0 : $index;
    }

    /**
     * Persist the menu index {@see handleKey()} navigated to.
     *
     * handleKey() is pure — it RETURNS the new index — and its only caller
     * discarded that return value, so every left/right/h/l press recomputed
     * a move and threw it away. Non-toggling, unlike {@see openMenu()}.
     */
    /**
     * The open menu's dropdown panel, or '' when nothing is open.
     *
     * The last missing piece: {@see render()} only ever recoloured the ACTIVE
     * menu's title, and {@see getMenuItems()} had no caller at all, so opening
     * a menu highlighted a name and showed nothing. Returns the item rows for
     * the caller to composite; positioning is {@see activeMenuColumn()}'s job.
     *
     * @return list<string> one rendered line per row, borders included
     */
    public static function renderDropdown(Theme $theme): array
    {
        return self::dropdownLines(false, $theme);
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
        return self::dropdownLines(true, $theme);
    }

    /**
     * @param bool $marked Whether item rows carry zone sentinels.
     *
     * @return list<string>
     */
    private static function dropdownLines(bool $marked, Theme $theme): array
    {
        if (self::$activeMenu < 1) {
            return [];
        }

        $menus = self::menus();
        $names = array_keys($menus);
        $name = $names[self::$activeMenu - 1] ?? null;
        if ($name === null) {
            return [];
        }

        /** @var list<string> $labels menus() yields display labels already */
        $labels = array_values(array_map(static fn(mixed $i): string => (string) $i, $menus[$name]));
        if ($labels === []) {
            $labels = ['(empty)'];
        }

        $inner = max(array_map(static fn(string $l): int => Width::string($l), $labels));
        // The box lines. This panel is spliced OVER the composed frame and fills
        // no background of its own, so what these glyphs are seen against is the
        // terminal's own background — which is why the colour comes from
        // {@see Theme}'s contrast-checked `border` and not from a literal. The
        // two literals this replaces were #6b7280 and #e5e7eb: the `light`
        // palette's own `muted` and `border` tokens, frozen into a dark-only
        // shell, and #6b7280 measures 2.95:1 on a dracula background.
        $dim = Style::new()->foreground($theme->border);
        $item = Style::new()->foreground($theme->shellForeground);

        // The row Enter would select has to be visible, or the cursor
        // moves invisibly and the user cannot tell what they are about to run.
        $selected = Style::new()->foreground($theme->shellPrimary)->bold();

        $lines = [$dim->render('┌' . str_repeat('─', $inner + 2) . '┐')];
        foreach ($labels as $row => $label) {
            $pad = str_repeat(' ', $inner - Width::string($label));
            $style = $row === self::$activeItem ? $selected : $item;
            $line = $dim->render('│ ') . $style->render($label . $pad) . $dim->render(' │');
            $lines[] = $marked
                ? Mark::zone(self::MENU_ITEM_ZONE_PREFIX . $row, $line)
                : $line;
        }
        $lines[] = $dim->render('└' . str_repeat('─', $inner + 2) . '┘');

        return $lines;
    }

    /**
     * Column the open menu's title starts at, so its dropdown can be drawn
     * under it. Mirrors {@see render()}'s own layout arithmetic (one leading
     * space, then each name plus a three-space gap).
     */
    public static function activeMenuColumn(): int
    {
        if (self::$activeMenu < 1) {
            return 0;
        }

        $col = 1;
        $index = 1;
        foreach (array_keys(self::menus()) as $name) {
            if ($index === self::$activeMenu) {
                return $col;
            }
            $col += Width::string($name) + 3;
            $index++;
        }

        return 0;
    }

    public static function activateMenu(int $index): void
    {
        $count = count(self::menus());
        if ($index >= 0 && $index <= $count) {
            if ($index !== self::$activeMenu) {
                self::$activeItem = 0;
            }
            self::$activeMenu = $index;
        }
    }
}
