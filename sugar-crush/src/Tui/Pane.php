<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Pane types for the SugarCrush TUI layout.
 *
 * Mirrors charmbracelet/crush TUI pane enumeration.
 *
 * @internal
 */
enum Pane: string
{
    case Chat = 'chat';
    case Input = 'input';
    case Skills = 'skills';
    case Agents = 'agents';
    case Files = 'files';
    case Tools = 'tools';
    case Settings = 'settings';
    case Help = 'help';
    case Menu = 'menu';

    /**
     * Returns the next pane in the cycling order.
     *
     * Cycle: Chat → Input → Files → Tools → Skills → Agents → Settings → Help → Chat.
     * Menu is a transient overlay reached by shortcut, not part of the Tab cycle,
     * so it folds back into Chat rather than extending the loop.
     */
    public function next(): self
    {
        // Cycles exactly the panes the bar advertises as tabs
        // (MenuBar::PANE_TABS), and nothing else. Tab used to walk all eight
        // cases, so it stopped on Input, Settings and Help -- none of which
        // appear in the tab strip and none of which has a renderer, leaving
        // the user on a pane the UI never offered and that draws nothing.
        // Those cases still exist for their own uses; they are just not
        // somewhere Tab can strand you.
        return match ($this) {
            self::Chat => self::Files,
            self::Files => self::Tools,
            self::Tools => self::Skills,
            self::Skills => self::Agents,
            self::Agents => self::Chat,
            self::Input, self::Settings, self::Help, self::Menu => self::Chat,
        };
    }

    /**
     * Returns a human-readable label for the pane.
     */
    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Input => 'Input',
            self::Skills => 'Skills',
            self::Agents => 'Agents',
            self::Files => 'Files',
            self::Tools => 'Tools',
            self::Settings => 'Settings',
            self::Help => 'Help',
            self::Menu => 'Menu',
        };
    }
}
