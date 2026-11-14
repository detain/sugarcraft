<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

/**
 * SugarCraft-original event vocabulary; only the cassette idea is
 * inspired by charmbracelet/x/vcr (which records HTTP pairs, not keystrokes).
 */
enum EventKind: string
{
    case Resize = 'resize';
    case Input = 'input';
    case Output = 'output';
    case Quit = 'quit';
    case Snapshot = 'snapshot';
    case Hide = 'hide';
    case Show = 'show';
}
