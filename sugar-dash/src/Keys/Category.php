<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Keys;

/**
 * Category constants for key bindings.
 */
enum Category: string
{
    case Navigation = 'navigation';
    case Editing = 'editing';
    case View = 'view';
    case General = 'general';
    case Debug = 'debug';

    /**
     * Get the display label for this category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Navigation => 'Navigation',
            self::Editing => 'Editing',
            self::View => 'View',
            self::General => 'General',
            self::Debug => 'Debug',
        };
    }
}
