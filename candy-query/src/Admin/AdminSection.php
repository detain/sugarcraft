<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Sections for grouping admin dashboard pages.
 */
enum AdminSection: string
{
    case Management   = 'management';
    case Performance = 'performance';

    public function label(): string
    {
        return match ($this) {
            self::Management   => 'Management',
            self::Performance => 'Performance',
        };
    }
}
