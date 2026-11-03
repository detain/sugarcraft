<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Captures the file:line of the immediate caller outside the log package.
 *
 * Mirrors charmbracelet/log's caller functionality.
 *
 * Public API — but normal callers never touch it: enable caller reporting
 * once with {@see Logger::setReportCaller()} and the Logger wires this
 * into the emit path for every record. Direct use is for integrations
 * that assemble their own formatted line.
 */
final class CallerFormatter
{
    /**
     * Walk back the call stack to find the first file outside the log package.
     *
     * The walk is bounded at 20 frames: a caller nested deeper than that
     * inside the package reports no caller rather than paying an unbounded
     * trace (N8) — the null below is that cap, not an error.
     *
     * @return string|null "basename:line" of the first frame outside the
     *                     package, or null when none appears in the walk.
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
