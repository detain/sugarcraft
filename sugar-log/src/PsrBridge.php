<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SugarCraft\Log\Hook\HookRegistry;

/**
 * PSR-3 bridge wrapping a Logger instance.
 *
 * Implements {@see LoggerInterface} — all PSR-3 methods
 * (emergency/alert/critical/error/warning/notice/info/debug/log) — so this
 * object can be passed anywhere a `Psr\Log\LoggerInterface` is type-hinted.
 * The generic `log()` accepts the interface's untyped `$level` and coerces
 * non-string values (PSR-3 only defines the eight string constants; anything
 * else falls back to Info, mirroring the previous mapping default).
 *
 * Note: {@see Logger} itself is deliberately NOT a PSR-3 logger — its typed
 * Level-enum API is the house style; this bridge is the interop surface.
 *
 * Mirrors charmbracelet/log's PSR-3 bridge where applicable.
 */
final class PsrBridge implements LoggerInterface
{
    private Logger $logger;
    private HookRegistry $hooks;

    public function __construct(Logger $logger, ?HookRegistry $hooks = null)
    {
        $this->logger = $logger;
        $this->hooks = $hooks ?? new HookRegistry();
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * PSR-3 generic entry. The interface leaves $level untyped; string level
     * names are mapped, anything else is normalized to its string form (or
     * '' for non-scalars, which then falls back to Info — never throws).
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $psrLevel = match (true) {
            \is_string($level) => $level,
            $level instanceof \Stringable => (string) $level,
            \is_scalar($level) => (string) $level,
            default => '',
        };
        $sugarCraftLevel = self::toSugarCraftLevel($psrLevel);

        $this->hooks->fire($sugarCraftLevel, $psrLevel, (string) $message, $context);

        $this->logger->log($sugarCraftLevel, (string) $message, $context);
    }

    /**
     * Map a PSR-3 log level string to our Level enum.
     */
    private static function toSugarCraftLevel(string $psrLevel): Level
    {
        return match ($psrLevel) {
            LogLevel::EMERGENCY => Level::Fatal,
            LogLevel::ALERT => Level::Fatal,
            LogLevel::CRITICAL => Level::Fatal,
            LogLevel::ERROR => Level::Error,
            LogLevel::WARNING => Level::Warn,
            LogLevel::NOTICE => Level::Info,
            LogLevel::INFO => Level::Info,
            LogLevel::DEBUG => Level::Debug,
            default => Level::Info,
        };
    }
}
