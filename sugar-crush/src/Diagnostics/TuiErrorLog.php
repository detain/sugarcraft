<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Diagnostics;

/**
 * Points PHP's `error_log` ini at a private file for the life of an
 * interactive TUI session (audit C2a).
 *
 * WHY. With the stock ini (`error_log` unset, `log_errors=1`), every
 * `error_log()` call — {@see RuntimeNoticeSink::warn()}, the DSML and MiniMax
 * tool-call parsers, the provider heuristics, PHP's own logged warnings —
 * writes to fd 2. In the TUI fd 2 is the same tty the diff renderer believes
 * it owns cell for cell, so each line is painted at whatever cursor position
 * the renderer left and stays (or tears the layout) until a full repaint. The
 * turn runs in a `pcntl_fork()`ed child, which inherits the ini, so one
 * `ini_set()` in the parent before the first fork covers both processes.
 *
 * WHERE, AND WHY THERE: `<home>/.sugar-crush/logs/sugarcrush.log`, the home
 * coming from {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} at the
 * call site. These lines carry tool names, parameter text and provider errors
 * out of the session, so they go nowhere another local user could read or
 * pre-create: the directory is made 0700 and the file is created under a 0077
 * umask and chmod'ed 0600 BEFORE the ini points at it, so `error_log()` never
 * creates it with the process umask.
 *
 * ONLY THE TUI CALLS THIS. On the `-p` one-shot and the subcommands stderr is
 * the operator's channel — a pipe, a CI log, a terminal with nothing drawn on
 * it — and moving their diagnostics into a file would hide them.
 *
 * THE OPERATOR'S DESTINATION WINS. An `error_log` ini already naming something
 * other than stderr (a file, `syslog`) is a decision somebody made, and it
 * already keeps the lines off the tty; overriding it would move their log
 * somewhere they are not looking. Only an unset/stderr destination is
 * replaced — see {@see destinationIsStderr()}.
 *
 * NEVER FAILS THE LAUNCH. A home that cannot be named, a directory that cannot
 * be made, a file whose mode cannot be tightened: each returns null with the
 * ini untouched. The cost is the pre-fix behaviour, and
 * {@see RuntimeNoticeSink::warn()} carries a second guard for exactly that case.
 */
final class TuiErrorLog
{
    /**
     * Rotate at launch when the log is bigger than this. One generation
     * (`sugarcrush.log.1`) is kept: the file exists for "what happened in the
     * session that just misbehaved", not as an archive, and an unbounded file
     * under a home directory is a disk-fill nobody asked for.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Relative to the home directory. */
    public const RELATIVE_PATH = '.sugar-crush/logs/sugarcrush.log';

    /**
     * Device spellings of fd 2 an `error_log` ini can carry. An empty value
     * is "unset", which the CLI SAPI also sends to stderr; a `php://` value
     * is handled by {@see destinationIsStderr()} rather than listed here.
     */
    private const STDERR_DEVICES = ['/dev/stderr', '/dev/fd/2', '/proc/self/fd/2'];

    /**
     * Redirect `error_log` into `<$home>/{@see RELATIVE_PATH}`.
     *
     * @param string|null $home an owned home directory, or null when none can
     *                          be established (nothing is redirected then)
     *
     * @return string|null the log file now receiving `error_log()`, or null
     *                     when the ini was left as it was — because the
     *                     operator already chose a destination, or because
     *                     the file could not be prepared safely
     */
    public static function install(?string $home): ?string
    {
        if ($home === null || $home === '' || !self::destinationIsStderr(ini_get('error_log'))) {
            return null;
        }

        $file = rtrim($home, '/') . '/' . self::RELATIVE_PATH;
        $dir = \dirname($file);

        if (!is_dir($dir)) {
            // `@`: a mkdir warning would itself be logged to the tty this is
            // about to stop writing to. The null return IS the handling.
            if (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                return null;
            }
            @chmod($dir, 0o700);
        }

        clearstatcache(true, $dir);
        $dirPerms = @fileperms($dir);
        if (is_link($dir) || $dirPerms === false || ($dirPerms & 0o002) !== 0) {
            return null;
        }

        if (is_link($file)) {
            return null;
        }

        clearstatcache(true, $file);
        if (is_file($file) && (int) @filesize($file) > self::MAX_BYTES) {
            // A failed rename leaves an oversized log, not a failed launch.
            @rename($file, $file . '.1');
        }

        $umask = umask(0o077);
        try {
            $handle = @fopen($file, 'ab');
        } finally {
            umask($umask);
        }

        if ($handle === false) {
            return null;
        }

        // A file that pre-existed keeps whatever mode it had; tighten it, and
        // refuse it when that did not take.
        @chmod($file, 0o600);
        clearstatcache(true, $file);
        $perms = @fileperms($file);
        if ($perms === false || ($perms & 0o077) !== 0) {
            fclose($handle);

            return null;
        }

        // One header per launch, so two sessions — sequential, or concurrent
        // and interleaved in one file — can be told apart by pid.
        @fwrite($handle, sprintf("[%s] sugarcrush TUI session start pid=%d\n", date('d-M-Y H:i:s e'), getmypid()));
        fclose($handle);

        if (ini_set('error_log', $file) === false) {
            return null;
        }

        return $file;
    }

    /**
     * Whether an `error_log` ini value sends `error_log()` to fd 2.
     *
     * ANY `php://` VALUE COUNTS, not just the stderr one. PHP's logger opens
     * the ini path with a plain `open(2)`, not through the stream layer, so a
     * wrapper spelling never opens and the line falls back to the SAPI logger
     * — stderr on the CLI. MEASURED, PHP 8.3.6: `php -d error_log=php://stdout
     * -r 'error_log("x");'` prints `x` on fd 2 and nothing on fd 1, and the
     * same for `php://memory` and an upper-cased `PHP://STDERR`.
     *
     * @param string|false $value what `ini_get('error_log')` returned
     */
    public static function destinationIsStderr(string|false $value): bool
    {
        if ($value === false) {
            return true;
        }

        $value = trim($value);

        return $value === ''
            || str_starts_with(strtolower($value), 'php://')
            || \in_array($value, self::STDERR_DEVICES, true);
    }
}
