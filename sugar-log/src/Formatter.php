<?php

declare(strict_types=1);

namespace SugarCraft\Log;

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
     * @param array<string,mixed> $context Key/value pairs attached to this entry (PSR-3).
     * @param \DateTimeImmutable $time     Timestamp of the log event.
     * @param string|null        $caller   Source location (file:line) if reportCaller is on.
     * @param string|null        $prefix   Logger prefix string.
     *
     * The terminator is a bare LF on every platform, deliberately — the
     * writer emits these bytes untouched (Logger::emit() fwrite) and files,
     * pipes and byte-exact pins all expect LF; substituting PHP_EOL would
     * bake CRLF into shared logs on Windows hosts and shatter that contract.
     *
     * @return string The rendered record, terminated by a single newline.
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
