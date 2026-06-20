<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Tui\Pane;

final class MenuBar
{
    private const MENUS = [
        'File' => ['New Session', 'Open Session', 'Save Transcript', 'Export Chat', '---', 'Preferences', 'Quit'],
        'Edit' => ['Copy', 'Paste', 'Select All', 'Clear History'],
        'Session' => ['Continue', 'New Session', 'Session History', 'Attach Context'],
        'Provider' => ['OpenAI', 'Anthropic', 'Claude Code', 'SGLANG', 'Bedrock', 'Vertex', '---', 'Custom...'],
        'Skills' => ['Browse Skills', 'Enable Skill...', 'Manage Built-in Skills'],
        'Agents' => ['Create Agent', 'Manage Agents', 'Active Agents'],
        'Help' => ['Keyboard Shortcuts', 'Documentation', 'About'],
    ];

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

    public static function render(App $a): string
    {
        $output = ' ';
        $menuIndex = 1;
        foreach (self::MENUS as $name => $items) {
            $isActive = self::$activeMenu === $menuIndex;
            $color = $isActive ? Color::hex('#00ffaa') : Color::hex('#fde68a');
            $output .= Style::new()->foreground($color)->bold()->render($name);
            $output .= '   ';
            $menuIndex++;
        }
        $output .= ' ';

        return $output . self::paneTabs($a);
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
        $count = count(self::MENUS);
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
        if ($menuIndex < 1 || $menuIndex > count(self::MENUS)) {
            return [$menuIndex, null];
        }

        $menuNames = array_keys(self::MENUS);
        $menuName = $menuNames[$menuIndex - 1] ?? '';

        return [$menuIndex, new MenuSelectedMsg($menuName, '')];
    }

    public static function getMenuItems(string $menuName): array
    {
        return self::MENUS[$menuName] ?? [];
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
}
