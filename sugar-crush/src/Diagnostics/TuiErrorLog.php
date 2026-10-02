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
 * NEVER FAILS THE LAUNCH, AND NEVER LEAVES THE TTY AS THE DESTINATION (audit
 * R16). A home that cannot be named, a directory that cannot be made, a file
 * whose mode cannot be tightened: each moves on to the next destination
 * rather than giving up. WHAT THIS USED TO SAY: each "returns null with the
 * ini untouched", whose cost was the pre-fix behaviour — and that cost was
 * not confined to {@see RuntimeNoticeSink::warn()}, which has its own guard:
 * the DSML, MiniMax and SGLang parsers call `error_log()` directly, and with
 * the ini still on stderr their lines were painted over the frame. The chain
 * is now:
 *
 *  1. `<home>/{@see RELATIVE_PATH}` — the documented place;
 *  2. `<tmp>/sugarcrush-<euid>/sugarcrush.log` — for a launch with no owned
 *     home, a read-only one, or a `.sugar-crush/logs` that is not safe. The
 *     temp dir is shared, so the directory must be OURS and private (owned by
 *     this euid, nothing for group or other), not merely not-world-writable;
 *  3. the null device — the last resort, which keeps the frame clean at the
 *     price of the forensic copy. {@see isNullDevice()} lets the callers that
 *     would otherwise point a reader at it say "not kept" instead.
 *
 * Null now means only "the operator's destination was kept" (or, on a host
 * where even `ini_set()` refuses, nothing could be changed).
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
     * The fallback directory under the temp dir is this prefix plus the euid,
     * so two users on one host never contend for — or read — one directory.
     */
    public const FALLBACK_DIR_PREFIX = 'sugarcrush-';

    /** The file inside the fallback directory. */
    public const FALLBACK_FILE = 'sugarcrush.log';

    /**
     * The longest destination {@see describeDestination()} spells out. Longer
     * than this — an operator's deep `error_log` path — and it is named
     * generically: the description is appended to transcript rows that are
     * themselves capped at {@see RuntimeNoticeSink::MAX_CHARS}, and a path
     * nothing bounds would eat that budget (or, past it, the whole row).
     */
    public const MAX_DESCRIBED_BYTES = 160;

    /**
     * Device spellings of fd 2 an `error_log` ini can carry. An empty value
     * is "unset", which the CLI SAPI also sends to stderr; a `php://` value
     * is handled by {@see destinationIsStderr()} rather than listed here.
     */
    private const STDERR_DEVICES = ['/dev/stderr', '/dev/fd/2', '/proc/self/fd/2'];

    /**
     * Redirect `error_log` away from the tty: into `<$home>/{@see RELATIVE_PATH}`,
     * else the private temp-dir fallback, else the null device (audit R16 —
     * see this class's doc-block for the chain).
     *
     * @param string|null $home    an owned home directory, or null when none
     *                             can be established (the chain then starts
     *                             at the temp-dir fallback)
     * @param string|null $tempDir where the fallback directory goes; null is
     *                             `sys_get_temp_dir()`. A parameter so tests
     *                             can keep the fallback out of the real /tmp
     *
     * @return string|null the destination now receiving `error_log()` — a log
     *                     file, or the null device (test it with
     *                     {@see isNullDevice()}) — or null when the ini was
     *                     left as it was because the operator already chose a
     *                     destination
     */
    public static function install(?string $home, ?string $tempDir = null): ?string
    {
        if (!self::destinationIsStderr(ini_get('error_log'))) {
            return null;
        }

        if ($home !== null && $home !== '') {
            $file = rtrim($home, '/') . '/' . self::RELATIVE_PATH;
            if (self::prepare($file, false) && ini_set('error_log', $file) !== false) {
                return $file;
            }
        }

        $tempDir ??= sys_get_temp_dir();
        if ($tempDir !== '') {
            $file = rtrim($tempDir, '/') . '/' . self::fallbackDirName() . '/' . self::FALLBACK_FILE;
            if (self::prepare($file, true) && ini_set('error_log', $file) !== false) {
                return $file;
            }
        }

        // The forensic copy is lost here, the frame is not: every direct
        // error_log() caller (the parsers above all) now writes nowhere
        // rather than over the renderer's cells.
        $null = self::nullDevice();

        return ini_set('error_log', $null) === false ? null : $null;
    }

    /**
     * Whether a destination {@see install()} returned discards what is
     * written to it — so a caller must not send a reader there.
     */
    public static function isNullDevice(string $destination): bool
    {
        return $destination === self::nullDevice();
    }

    /**
     * How a user-facing row should name where the COMPLETE text of a
     * diagnostic went, given an `error_log` ini value: `stderr`, a path (the
     * home spelled `~`), `the system log`, or null when it went to the null
     * device and nothing can be read back (audit C4).
     *
     * The value is bounded by {@see MAX_DESCRIBED_BYTES} and must be printable;
     * anything else is named generically, because the description lands in a
     * transcript row the model reads and a terminal renders.
     *
     * @param string|false $value what `ini_get('error_log')` returned
     */
    public static function describeDestination(string|false $value): ?string
    {
        if (self::destinationIsStderr($value)) {
            return 'stderr';
        }

        $value = trim((string) $value);
        if (self::isNullDevice($value)) {
            return null;
        }
        if (strtolower($value) === 'syslog') {
            return 'the system log';
        }

        $home = rtrim((string) getenv('HOME'), '/');
        if ($home !== '' && str_starts_with($value, $home . '/')) {
            $value = '~' . substr($value, \strlen($home));
        }

        if (\strlen($value) > self::MAX_DESCRIBED_BYTES
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || !mb_check_encoding($value, 'UTF-8')
        ) {
            return 'the error_log file';
        }

        return $value;
    }

    /**
     * Make `$file` a private, appendable log file, or report that it cannot
     * be one. Nothing here touches the ini.
     *
     * @param bool $sharedParent true when the directory's parent is shared
     *                           with other users (the temp dir): the directory
     *                           must then be owned by this euid and closed to
     *                           group and other, since anyone could have
     *                           created it first
     */
    private static function prepare(string $file, bool $sharedParent): bool
    {
        $dir = \dirname($file);

        if (!is_dir($dir)) {
            // `@`: a mkdir warning would itself be logged to the tty this is
            // about to stop writing to. The false return IS the handling.
            if (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                return false;
            }
            @chmod($dir, 0o700);
        }

        if (is_link($dir)) {
            return false;
        }

        if ($sharedParent) {
            // A no-op on a directory we own and made 0700; the check below
            // is what refuses one somebody else pre-created.
            @chmod($dir, 0o700);
        }

        clearstatcache(true, $dir);
        $dirPerms = @fileperms($dir);
        if ($dirPerms === false || ($dirPerms & 0o002) !== 0) {
            return false;
        }

        if ($sharedParent) {
            if (($dirPerms & 0o077) !== 0) {
                return false;
            }
            if (\function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
                return false;
            }
        }

        if (is_link($file)) {
            return false;
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
            return false;
        }

        // A file that pre-existed keeps whatever mode it had; tighten it, and
        // refuse it when that did not take.
        @chmod($file, 0o600);
        clearstatcache(true, $file);
        $perms = @fileperms($file);
        if ($perms === false || ($perms & 0o077) !== 0) {
            fclose($handle);

            return false;
        }

        // One header per launch, so two sessions — sequential, or concurrent
        // and interleaved in one file — can be told apart by pid.
        @fwrite($handle, sprintf("[%s] sugarcrush TUI session start pid=%d\n", date('d-M-Y H:i:s e'), getmypid()));
        fclose($handle);

        return true;
    }

    private static function fallbackDirName(): string
    {
        $who = \function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();

        return self::FALLBACK_DIR_PREFIX . preg_replace('/[^A-Za-z0-9_.-]/', '_', $who);
    }

    private static function nullDevice(): string
    {
        return \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
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
