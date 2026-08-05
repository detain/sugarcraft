<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Represents the type of terminal multiplexer detected in the environment.
 *
 * When a multiplexer is active, the split pane rendering can delegate to
 * the multiplexer's native pane splitting instead of the in-process renderer.
 */
enum MultiplexerType: string
{
    /** No multiplexer detected - use in-process split rendering. */
    case None = 'none';

    /** tmux multiplexer is active. */
    case Tmux = 'tmux';

    /** iTerm2 is the active terminal (macOS). */
    case ITerm2 = 'iterm2';

    /**
     * Returns true if any multiplexer is active.
     */
    public function isActive(): bool
    {
        return $this !== self::None;
    }

    /**
     * Returns a human-readable description of the multiplexer type.
     */
    public function description(): string
    {
        return match ($this) {
            self::None => 'No multiplexer (in-process rendering)',
            self::Tmux => 'tmux multiplexer',
            self::ITerm2 => 'iTerm2 (macOS)',
        };
    }
}
