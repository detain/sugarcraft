<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Pane types for the CandyCrush TUI layout.
 *
 * Mirrors charmbracelet/candy-crush TUI pane enumeration.
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
        return match ($this) {
            self::Chat => self::Input,
            self::Input => self::Files,
            self::Files => self::Tools,
            self::Tools => self::Skills,
            self::Skills => self::Agents,
            self::Agents => self::Settings,
            self::Settings => self::Help,
            self::Help => self::Chat,
            self::Menu => self::Chat,
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
