<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

/**
 * A log entry with timestamp, level, and message.
 */
final readonly class LogEntry
{
    public function __construct(
        public string $timestamp,
        public LogLevel $level,
        public string $message,
    ) {}

    /**
     * Create a new log entry.
     */
    public static function create(
        string $message,
        LogLevel $level = LogLevel::Info,
        ?string $timestamp = null,
    ): self {
        return new self(
            timestamp: $timestamp ?? date('Y-m-d H:i:s'),
            level: $level,
            message: $message,
        );
    }
}
