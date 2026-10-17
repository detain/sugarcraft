<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Log level enum — mirrors charmbracelet/log levels.
 */
enum Level: int
{
    case Debug = -4;
    case Info  =  0;
    case Warn  =  4;
    case Error =  8;
    case Fatal = 12;

    public function label(): string
    {
        return match ($this) {
            self::Debug => 'DEBUG',
            self::Info  => 'INFO',
            self::Warn  => 'WARN',
            self::Error => 'ERROR',
            self::Fatal => 'FATAL',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Debug => 'DBG',
            self::Info  => 'INF',
            self::Warn  => 'WRN',
            self::Error => 'ERR',
            self::Fatal => 'FTL',
        };
    }
}
