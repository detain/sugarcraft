<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * View modes for the Agent View panel.
 *
 * Mirrors the list → peek → attach navigation flow described in the spec.
 */
enum AgentViewMode: string
{
    /** List view: scrollable agent list with selection highlight. */
    case List = 'list';

    /** Peek view: last N lines of output from the selected agent. */
    case Peek = 'peek';

    /** Attach mode: all keyboard input goes to the selected agent. */
    case Attach = 'attach';
}
