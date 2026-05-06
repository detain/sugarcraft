<?php

declare(strict_types=1);

namespace CandyCore\Log;

/**
 * Log level enum — mirrors charmbracelet/log levels.
 */
enum Level: int
{
    case Debug = 0;
    case Info  = 1;
    case Warn  = 2;
    case Error = 3;
    case Fatal = 4;

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
