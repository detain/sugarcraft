<?php

declare(strict_types=1);

namespace SugarCraft\Freeze;

/**
 * Window decoration style for rendered code images.
 *
 * Mirrors charmbracelet/freeze's window style options.
 */
enum WindowStyle: string
{
    case Macos = 'macos';
    case WindowsTerminal = 'windows-terminal';
    case ITerm2 = 'iterm';
    case Hyper = 'hyper';
    case None = 'none';
}
