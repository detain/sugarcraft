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
     * Hard cap on the frame walk: a caller nested deeper than this inside the
     * package reports no caller rather than paying an unbounded trace (N8).
     */
    private const MAX_FRAMES = 20;

    /**
     * Walk back the call stack to find the first file outside the log package.
     *
     * The walk is bounded at {@see self::MAX_FRAMES} frames: a caller nested
     * deeper than that inside the package reports no caller rather than paying
     * an unbounded trace (N8) — the null below is that cap, not an error.
     *
     * @return string|null "basename:line" of the first frame outside the
     *                     package, or null when none appears in the walk.
     */
    public static function find(): ?string
    {
        return self::locate(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES), __DIR__);
    }

    /**
     * The frame walk over a supplied trace, separated from find() so the
     * null path and the cap boundary are reachable without fabricating
     * stack depth inside the package (SL-13).
     *
     * @param list<array{file?: string, line?: int|string}> $traces
     */
    private static function locate(array $traces, string $selfDir): ?string
    {
        foreach (\array_slice($traces, 0, self::MAX_FRAMES) as $t) {
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
