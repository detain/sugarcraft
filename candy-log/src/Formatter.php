<?php

declare(strict_types=1);

namespace CandyCore\Log;

/**
 * Log message formatter contract.
 * Mirrors charmbracelet/log's Formatter interface.
 */
interface Formatter
{
    /**
     * Format a log event into a string.
     *
     * @param Level              $level    The log level.
     * @param string             $message  The primary message.
     * @param array<string,mixed> $context Key/value pairs attached to this entry.
     * @param \DateTimeImmutable $time     Timestamp of the log event.
     * @param string|null        $caller   Source location (file:line) if reportCaller is on.
     * @param string|null        $prefix   Logger prefix string.
     */
    public function format(
        Level $level,
        string $message,
        array $context,
        \DateTimeImmutable $time,
        ?string $caller,
        ?string $prefix,
    ): string;
}
