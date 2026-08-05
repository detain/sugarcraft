<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Display mode for AgentOutputPane.
 */
enum Mode
{
    /** Last N lines in a compact tile (default). */
    case Peek;

    /** Full-focus single-agent view. */
    case Attach;
}
