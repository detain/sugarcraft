<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\ColorProfile\Probe;
use SugarCraft\Log\Support\Stdio;

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

    /**
     * Handler chained ahead of OUR panic wrapper (never our own wrapper —
     * re-install replaces, it does not stack). Null until first install.
     */
    private static mixed $panicPrevious = null;

    /** Monotonic install epoch; stale shutdown hooks self-retire (m5). */
    private static int $panicEpoch = 0;

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

    /** Always print, ignoring level filters. */
    public static function print(string $message, array $context = []): void
    {
        self::default()->print($message, $context);
    }

    /**
     * Default for the panic banner: whether it is rendered with SGR styling.
     *
     * The process-global handler never sees the construction-time color probe,
     * so it re-implements the destination decision with the fleet's detection
     * precedence (sugar-colorprofile: `CLICOLOR_FORCE` outranks `NO_COLOR`).
     * m-05: `NO_COLOR=1` must suppress the styled banner even when STDERR is a
     * real TTY — {@see ColorGate} alone cannot express that (it checks only
     * whether the destination can render). The isatty answer is a parameter
     * because {@see \stream_isatty()} cannot be faked under a piped test runner.
     */
    private static function panicFormatterPrefersColor(bool $stderrIsTty): bool
    {
        if (Probe::isForceColor()) {
            return true;
        }

        return $stderrIsTty && !Probe::isNoColor();
    }

    /**
     * Install a panic handler that catches uncaught exceptions and
     * renders them as a styled panic report.
     *
     * On an uncaught exception:
     *  - Restores the terminal from altscreen mode
     *  - Shows the cursor
     *  - Prints a banner with the exception class + message (styled only
     *    when the formatter selected at install time is a pretty one)
     *  - Prints the backtrace with file paths and line numbers
     *  - Collapses repeated stack frames
     *  - Appends a short report footer with filing guidance
     *
     * The shutdown function additionally catches fatal errors (E_ERROR,
     * E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR) that the
     * exception handler cannot catch.
     *
     * Mirrors ratatui ecosystem's `color_eyre` handler.
     *
     * The report goes to STDERR. When $formatter is omitted it is chosen
     * ONCE, at install time, from the terminal's capability:
     * {@see PanicFormatter::pretty()} when STDERR is a TTY (or under
     * CLICOLOR_FORCE=1), {@see PanicFormatter::plain()} otherwise. NO_COLOR
     * suppresses pretty() even on a real TTY (m-05). The decision is not
     * re-evaluated per report: a pretty handler installed while STDERR is a
     * TTY still bakes ANSI into a report captured to a file after a later
     * redirect — pass {@see PanicFormatter::plain()} explicitly as $formatter
     * to keep such a destination ANSI-free (m-09).
     *
     * Re-installing REPLACES the previously installed handler and its
     * shutdown hook (one panic reporter per process at a time); the chained
     * "previous" handler captured at install time still runs afterwards.
     *
     * $showLocals/$redactPaths configure the DEFAULT-chosen formatter only —
     * a formatter you build yourself has already made those decisions.
     * Passing both an explicit $formatter and non-default knobs therefore
     * throws: a silently dropped $redactPaths is a privacy failure, not a
     * styling preference, and this API refuses the trap instead of quietly
     * honoring the wrong half of the call.
     *
     * @param PanicFormatter|null $formatter  Omitted: chosen at install time by STDERR capability
     *                                        (never revisited per report, m-09); a NO_COLOR
     *                                        environment suppresses pretty() unless
     *                                        CLICOLOR_FORCE overrides (m-05).
     * @param bool                $showLocals Include local variables in backtrace;
     *                                        applies only to the default-chosen formatter
     *                                        (throws when combined with an explicit one).
     * @param list<string>        $redactPaths Paths to redact from backtrace; same
     *                                        default-formatter-only rule as $showLocals.
     *
     * @throws \InvalidArgumentException If an explicit $formatter is passed together with
     *                                  non-default $showLocals/$redactPaths (they would be
     *                                  silently ignored).
     */
    public static function installPanicHandler(
        ?PanicFormatter $formatter = null,
        bool $showLocals = false,
        array $redactPaths = [],
    ): void {
        if (null !== $formatter && ($showLocals || [] !== $redactPaths)) {
            throw new \InvalidArgumentException(
                'installPanicHandler(): $showLocals/$redactPaths configure only the'
                . ' formatter chosen by default — set them on the PanicFormatter you'
                . ' pass instead of alongside it (silently dropped redaction is the'
                . ' failure mode this refuses)'
            );
        }

        // [PORT-01] SAPI-safe STDERR (php-fpm never defines the constant).
        $panicStderr = Stdio::err();
        $formatter ??= self::panicFormatterPrefersColor(
            null !== $panicStderr && \stream_isatty($panicStderr)
        )
            ? PanicFormatter::pretty($showLocals, $redactPaths)
            : PanicFormatter::plain($showLocals, $redactPaths);

        // set_exception_handler() returns the previously registered handler, but
        // only when we call it. On the FIRST install we register a no-op purely
        // to capture what was registered before us; on RE-install we reuse the
        // foreign handler captured then, so our own earlier wrapper is replaced
        // instead of chained to (which would print a second report per
        // exception — m5).
        $previousHandler = self::$panicPrevious ?? \set_exception_handler(static function (): void {});
        self::$panicPrevious = $previousHandler;

        // Now register the real handler with the captured previous handler.
        \set_exception_handler(static function (\Throwable $e) use ($formatter, $previousHandler): void {
            self::restoreTerminal();
            $report = $formatter->format($e);
            // [N7] Exotic hosts can lose STDERR entirely; a fwrite to an
            // unavailable stream would raise during panic reporting — exactly
            // when only safe code is allowed to run. [PORT-01] SAPI-safe.
            $bannerStderr = Stdio::err();
            if (null !== $bannerStderr) {
                @\fwrite($bannerStderr, "\n{$report}\n");
            }

            // Chain to previous handler if one was registered.
            if ($previousHandler !== null) {
                $previousHandler($e);
            }
        });

        // Register shutdown function for fatal errors. PHP offers no way to
        // unregister one, so each hook checks the install epoch and self-retires
        // when a later installPanicHandler() has replaced it (m5).
        $holder = new \ArrayObject(['epoch' => ++self::$panicEpoch]);
        register_shutdown_function(static function () use ($holder, $formatter): void {
            if ($holder['epoch'] !== self::$panicEpoch) {
                return;
            }
            $err = error_get_last();
            if ($err !== null && \in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                self::restoreTerminal();
                $msg = sprintf('%s: %s in %s on line %d', self::fatalTypeName($err['type']), $err['message'], $err['file'], $err['line']);
                // [PORT-01] SAPI-safe: re-resolve at emit time, the install-time
                // handle may be gone.
                $fatalStderr = Stdio::err();
                if (null !== $fatalStderr) {
                    @\fwrite($fatalStderr, "\n{$formatter->format(new \ErrorException($msg))}\n");
                }
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
        // Guard: only emit ANSI codes when running in a real TTY.
        // fwrite to a closed or non-TTY stream can cause warnings.
        // stream_isatty() is core PHP — no ext-posix dependency.
        // [PORT-01] SAPI-safe: Stdio::err() never touches an undefined constant.
        $terminalStderr = Stdio::err();
        if (null === $terminalStderr || !\stream_isatty($terminalStderr)) {
            return;
        }

        // Exit altscreen mode (SGR 1049).
        \fwrite($terminalStderr, "\x1b[?1049l");
        // Show cursor.
        \fwrite($terminalStderr, "\x1b[?25h");
        \fflush($terminalStderr);

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
            E_USER_ERROR => 'User error',
            default      => 'Shutdown error',
        };
    }
}
