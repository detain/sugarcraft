<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Color;

/**
 * Log entry severity levels.
 */
enum LogLevel: string
{
    case Debug = 'DEBUG';
    case Info = 'INFO';
    case Warn = 'WARN';
    case Error = 'ERROR';
    case Fatal = 'FATAL';

    /**
     * Get the default color for this severity level.
     */
    public function defaultColor(): Color
    {
        return match ($this) {
            self::Debug => Color::hex('#6C7086'),
            self::Info => Color::hex('#89B4FA'),
            self::Warn => Color::hex('#F9E2AF'),
            self::Error => Color::hex('#F38BA8'),
            self::Fatal => Color::hex('#EBA0AC'),
        };
    }

    /**
     * Get the sort order for this level (lower = more severe).
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warn => 2,
            self::Error => 3,
            self::Fatal => 4,
        };
    }
}
