<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

/**
 * Mirrors charmbracelet/x/vcr event kinds.
 */
enum EventKind: string
{
    case Resize = 'resize';
    case Input = 'input';
    case Output = 'output';
    case Quit = 'quit';
}
