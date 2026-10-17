<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Captures the file:line of the immediate caller outside the log package.
 *
 * Mirrors charmbracelet/log's caller functionality.
 *
 * @internal This class is public API but is for framework integration
 *           use. Prefer Logger::setReportCaller(true) for normal use.
 */
final class CallerFormatter
{
    /**
     * Walk back the call stack to find the first file outside the log package.
     *
     * @return string|null "file:line" of the caller, or null if not found.
     */
    public static function find(): ?string
    {
        $traces = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        $selfDir = __DIR__;

        foreach ($traces as $t) {
            $file = $t['file'] ?? '';
            // Skip frames inside the log package itself
            if (\strpos($file, $selfDir) === 0) {
                continue;
            }
            $line = $t['line'] ?? '?';
            $basename = \basename($file);
            return "{$basename}:{$line}";
        }

        return null;
    }
}
