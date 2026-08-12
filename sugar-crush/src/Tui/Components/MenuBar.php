<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Tui\Pane;

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
    ];

    private static int $activeMenu = 0;

    /**
     * @param int|null $cols Width the bar has to fit in. Null renders the full
     *                       bar, which is what a caller measuring the bar on
     *                       its own wants; the shell frame passes the terminal
     *                       width because it must not emit an over-wide line.
     */
    public static function render(App $a, ?int $cols = null): string
    {
        $tabs = self::paneTabs($a);

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
            $color = $isActive ? Color::hex('#00ffaa') : Color::hex('#fde68a');
            $output .= Style::new()->foreground($color)->bold()->render($name);
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
    private static function paneTabs(App $a): string
    {
        $tabs = ' ';
        foreach (self::PANE_TABS as $pane) {
            $color = $a->pane === $pane ? Color::hex('#00ffaa') : Color::hex('#7d6e98');
            $tabs .= Style::new()->foreground($color)->render('[' . $pane->label() . ']');
            $tabs .= ' ';
        }

        $current = Style::new()->foreground(Color::hex('#fde68a'))
            ->render('Currently: ' . $a->pane->label());

        return $tabs . ' ' . $current;
    }

    public static function handleKey(string $key, int $currentMenu): array
    {
        return match ($key) {
            'left', 'h' => [self::cycleMenu($currentMenu, -1), null],
            'right', 'l' => [self::cycleMenu($currentMenu, 1), null],
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

        return $new;
    }

    private static function selectMenuItem(int $menuIndex): array
    {
        $menus = self::menus();
        if ($menuIndex < 1 || $menuIndex > count($menus)) {
            return [$menuIndex, null];
        }

        $menuNames = array_keys($menus);
        $menuName = $menuNames[$menuIndex - 1] ?? '';

        return [$menuIndex, new MenuSelectedMsg($menuName, '')];
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
    public static function renderDropdown(): array
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
        $dim = Style::new()->foreground(Color::hex('#6b7280'));
        $item = Style::new()->foreground(Color::hex('#e5e7eb'));

        $lines = [$dim->render('┌' . str_repeat('─', $inner + 2) . '┐')];
        foreach ($labels as $label) {
            $pad = str_repeat(' ', $inner - Width::string($label));
            $lines[] = $dim->render('│ ') . $item->render($label . $pad) . $dim->render(' │');
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
            self::$activeMenu = $index;
        }
    }
}
