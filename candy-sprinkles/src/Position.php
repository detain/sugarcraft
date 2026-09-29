<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

/**
 * Position constants for {@see Layout::place()}. Match lipgloss's
 * `Top` / `Bottom` / `Left` / `Right` / `Center` floats: 0.0 anchors
 * to the start, 1.0 anchors to the end, 0.5 centres.
 */
final class Position
{
    public const TOP    = 0.0;
    public const LEFT   = 0.0;
    public const CENTER = 0.5;
    public const RIGHT  = 1.0;
    public const BOTTOM = 1.0;
}
