<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use Psr\Log\LogLevel;

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

    /**
     * Map a PSR-3 severity string onto the level ladder.
     *
     * [API-01] Public so env/config-driven levels can be parsed with the same
     * 8-way mapping the PsrBridge uses (it delegates here) — previously the
     * only way to name a level from a string was to construct a bridge.
     * emergency/alert/critical all collapse onto Fatal, notice onto Info,
     * exactly as the bridge has always routed them.
     *
     * @return ?self null for unknown strings — the caller decides the contract
     *               (the bridge throws; a config reader might default).
     */
    public static function fromPsrString(string $psrLevel): ?self
    {
        return match ($psrLevel) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL => self::Fatal,
            LogLevel::ERROR => self::Error,
            LogLevel::WARNING => self::Warn,
            LogLevel::NOTICE, LogLevel::INFO => self::Info,
            LogLevel::DEBUG => self::Debug,
            default => null,
        };
    }

    /**
     * The canonical PSR-3 string for this level (inverse of the coarse
     * {@see fromPsrString()} routing: Fatal → emergency, Info → info).
     */
    public function toPsrString(): string
    {
        return match ($this) {
            self::Debug => LogLevel::DEBUG,
            self::Info => LogLevel::INFO,
            self::Warn => LogLevel::WARNING,
            self::Error => LogLevel::ERROR,
            self::Fatal => LogLevel::EMERGENCY,
        };
    }

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
