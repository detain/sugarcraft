<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Palette;

/**
 * The fixed set of actions the Ctrl+P command palette's "root" mode lists.
 * {@see \SugarCraft\Crush\Chat::runSelectedPaletteAction()} dispatches on
 * these; SwitchModel/SwitchTheme transition the palette into a second-level
 * list (provider names / theme names) rather than running an action
 * directly.
 */
enum PaletteAction: string
{
    case NewSession = 'new_session';
    case SwitchSession = 'switch_session';
    case SwitchModel = 'switch_model';
    case ShareSession = 'share_session';
    case OpenDocs = 'open_docs';
    case Exit = 'exit';
    case SwitchTheme = 'switch_theme';
    case SwitchAgent = 'switch_agent';
    case ToggleMcp = 'toggle_mcp';

    public function label(): string
    {
        return match ($this) {
            self::NewSession => 'New session',
            self::SwitchSession => 'Switch session',
            self::SwitchModel => 'Switch model',
            self::ShareSession => 'Share session',
            self::OpenDocs => 'Open docs',
            self::Exit => 'Exit',
            self::SwitchTheme => 'Switch theme',
            self::SwitchAgent => 'Switch agent',
            self::ToggleMcp => 'Toggle MCPs',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::NewSession, self::SwitchSession, self::ShareSession => 'Session',
            self::SwitchModel => 'Model',
            self::SwitchTheme => 'Appearance',
            self::SwitchAgent => 'Agents',
            self::ToggleMcp => 'MCP',
            self::OpenDocs, self::Exit => 'App',
        };
    }

    public function shortcut(): ?string
    {
        return match ($this) {
            self::Exit => 'Ctrl+C',
            default => null,
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    public static function byLabel(string $label): ?self
    {
        foreach (self::all() as $action) {
            if ($action->label() === $label) {
                return $action;
            }
        }

        return null;
    }
}
