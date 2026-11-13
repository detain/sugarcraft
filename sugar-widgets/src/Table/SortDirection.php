<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Table;

/**
 * Sort direction for a table column.
 */
enum SortDirection: string
{
    case Asc  = 'asc';
    case Desc = 'desc';

    /**
     * Return the opposite direction.
     */
    public function toggle(): self
    {
        return match ($this) {
            self::Asc  => self::Desc,
            self::Desc => self::Asc,
        };
    }

    /**
     * The header glyph for this direction (M5 sort indicator). Mirrors charmbracelet/bubbles
     * table's default arrow pair; U+2191/U+2193 are single-cell
     * everywhere (Width::string = 1).
     */
    public function marker(): string
    {
        return $this === self::Asc ? "\u{2191}" : "\u{2193}";
    }
}
