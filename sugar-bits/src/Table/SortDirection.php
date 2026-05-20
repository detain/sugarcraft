<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Table;

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
}
