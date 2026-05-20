<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use Psr\Log\LogLevel;
use SugarCraft\Log\Hook\HookRegistry;

/**
 * PSR-3 compatible bridge wrapping a Logger instance.
 *
 * Provides all PSR-3 LoggerInterface methods (emergency/alert/critical/
 * error/warning/notice/info/debug/log) so that SugarCraft\Log\Logger
 * can be used anywhere a PSR-3 logger is expected. Does not formally
 * implement LoggerInterface to avoid signature incompatibilities with
 * the generic `log(mixed $level, ...)` contract.
 *
 * Mirrors charmbracelet/log's PSR-3 bridge where applicable.
 */
final class PsrBridge
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
     * @param string $level PSR-3 log level string (e.g., LogLevel::INFO).
     */
    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $sugarCraftLevel = self::toSugarCraftLevel($level);

        $this->hooks->fire($sugarCraftLevel, $level, (string) $message, $context);

        $this->logger->log($sugarCraftLevel, (string) $message, $context);
    }

    /**
     * Map a PSR-3 LogLevel string to our Level enum.
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
