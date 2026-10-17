<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Taskbar-progress state values used by OSC 9;4 (the ConEmu /
 * WezTerm / Windows-Terminal protocol). Pass to
 * {@see Cmd::setProgressBar()}.
 */
enum ProgressBarState: int
{
    /** Clear any active progress indicator. */
    case Remove        = 0;
    /** Default-colour progress bar (typically blue). */
    case Normal        = 1;
    /** Error state — typically a red bar. */
    case Error         = 2;
    /** Indeterminate / unknown duration — typically a pulsing bar. */
    case Indeterminate = 3;
    /** Warning state — typically a yellow bar. */
    case Warning       = 4;
}
