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
     * Cycle: Chat → Files → Tools → Skills → Agents → Settings → Chat.
     * Menu is a transient overlay reached by shortcut, not part of the Tab cycle,
     * so it folds back into Chat rather than extending the loop.
     */
    public function next(): self
    {
        // Cycles exactly the panes the bar advertises as tabs
        // (MenuBar::PANE_TABS), and nothing else. Tab used to walk all eight
        // cases, so it stopped on Input, Settings and Help -- none of which
        // appeared in the tab strip and none of which had a renderer, leaving
        // the user on a pane the UI never offered and that drew nothing.
        //
        // Settings has since rejoined the strip, because it now HAS a renderer
        // ({@see \SugarCraft\Crush\Tui\Components\SettingsPane}); the reason it
        // was excluded no longer holds. Input and Help still draw nothing, so
        // they still fold back into Chat rather than extending the loop.
        return match ($this) {
            self::Chat => self::Files,
            self::Files => self::Tools,
            self::Tools => self::Skills,
            self::Skills => self::Agents,
            self::Agents => self::Settings,
            self::Settings => self::Chat,
            self::Input, self::Help, self::Menu => self::Chat,
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
