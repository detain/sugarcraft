<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Static facade over a process-wide default {@see Logger}.
 *
 * `Log::debug('hi')` is equivalent to `Log::default()->debug('hi')`.
 * Swap the default logger with `Log::setLogger($custom)` (handy for tests).
 *
 * Lives in its own class because PHP can't have static and instance methods
 * sharing a name on `Logger` itself.
 */
final class Log
{
    private static ?Logger $default = null;

    /** Get (or lazily create) the process-wide default logger. */
    public static function default(): Logger
    {
        return self::$default ??= Logger::new();
    }

    /** Replace the process-wide default logger. */
    public static function setLogger(Logger $logger): void
    {
        self::$default = $logger;
    }

    /** Reset the default logger so the next call rebuilds it. */
    public static function reset(): void
    {
        self::$default = null;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::default()->log(Level::Debug, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::default()->log(Level::Info, $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::default()->log(Level::Warn, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::default()->log(Level::Error, $message, $context);
    }

    public static function fatal(string $message, array $context = []): void
    {
        self::default()->log(Level::Fatal, $message, $context);
    }

    /** Always print, ignoring level filters (uses Info under the hood). */
    public static function print(string $message, array $context = []): void
    {
        self::default()->log(Level::Info, $message, $context);
    }

    /**
     * Install a panic handler that catches uncaught exceptions and
     * renders them as a styled panic report.
     *
     * On an uncaught exception:
     *  - Restores the terminal from altscreen mode
     *  - Shows the cursor
     *  - Prints a colorized banner with the exception class + message
     *  - Prints the backtrace with file paths and line numbers
     *  - Collapses repeated stack frames
     *  - Appends a hint to run `caliber refresh` if applicable
     *
     * The shutdown function additionally catches fatal errors (E_ERROR,
     * E_PARSE) that the exception handler cannot catch.
     *
     * Mirrors ratatui ecosystem's `color_eyre` handler.
     *
     * @param PanicFormatter|null $formatter  Defaults to PanicFormatter::pretty().
     * @param bool                $showLocals Include local variables in backtrace.
     * @param list<string>        $redactPaths Paths to redact from backtrace.
     */
    public static function installPanicHandler(
        ?PanicFormatter $formatter = null,
        bool $showLocals = false,
        array $redactPaths = [],
    ): void {
        $formatter ??= PanicFormatter::pretty($showLocals, $redactPaths);

        // Register exception handler.
        set_exception_handler(static function (\Throwable $e) use ($formatter): void {
            self::restoreTerminal();
            $report = $formatter->format($e);
            \fwrite(\STDERR, "\n{$report}\n");
        });

        // Register shutdown function for fatal errors.
        register_shutdown_function(static function () use ($formatter): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::restoreTerminal();
                $msg = sprintf('%s: %s in %s on line %d', self::fatalTypeName($err['type']), $err['message'], $err['file'], $err['line']);
                \fwrite(\STDERR, "\n{$formatter->format(new \ErrorException($msg))}\n");
            }
        });
    }

    /**
     * Restore the terminal to a usable state: exit altscreen and show cursor.
     *
     * Called automatically by the panic handler but also safe to call
     * directly during normal shutdown.
     */
    public static function restoreTerminal(): void
    {
        // Exit altscreen mode (SGR 1049).
        \fwrite(\STDERR, "\x1b[?1049l");
        // Show cursor.
        \fwrite(\STDERR, "\x1b[?25h");
        \fflush(\STDERR);

        // Attempt TTY state restore if available.
        if (class_exists(\SugarCraft\Core\Util\Tty::class)) {
            try {
                \SugarCraft\Core\Util\Tty::restoreLast();
            } catch (\Throwable) {
                // Best-effort.
            }
        }
    }

    private static function fatalTypeName(int $type): string
    {
        return match ($type) {
            E_ERROR      => 'Fatal error',
            E_PARSE      => 'Parse error',
            E_CORE_ERROR => 'Core error',
            E_COMPILE_ERROR => 'Compile error',
            default      => 'Shutdown error',
        };
    }
}
