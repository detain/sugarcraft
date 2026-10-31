<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks;

use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * The hook log: a bounded JSONL trail of configured hook RUNS
 * (C-EXT-6.b, GAP-HK-08), read back by `/hooks log [n]`.
 *
 * WHY A FILE. Hooks run inside forked turn and tool children — an in-memory
 * ring in the parent never sees them, which is exactly the audit trail's
 * argument (see `docs/HOOKS.md`, "The hook log") and the reason this step is
 * an M rather than a cheap display sliver. The registry writes one
 * {@see HookLogEntry} per run of a CONFIGURED, non-built-in hook: a shipped
 * built-in's trail is the audit log, and this file answers the question the
 * audit log deliberately does not — "what did MY hook do, this session".
 *
 * THE SHAPE IS AUDITHOOK'S, NOT A NEW ONE: flock append, rotate one
 * generation at a byte ceiling, redact before the write (the ORDER law lives
 * on {@see HookLogEntry::forRun()}), and refuse — rather than repair — a log
 * path the class did not choose to own. `CompactionJournal` is the JSONL
 * precedent for the wire format; this class keeps AuditHook's failure policy
 * because a LOG must never break the thing it logs.
 *
 * FAILURE POLICY (the step's headline risk, taken literally): a write on
 * every hook run must never fail the hook. Every refusal path returns false
 * and says so at most once per process through
 * {@see RuntimeNoticeSink::warn()}; {@see record()} additionally catches
 * anything the guards could not see and swallows it. The chain cannot tell a
 * logged run from a refused one, and is not supposed to.
 *
 * PRIVACY: the leaf is created 0600 (umask held for the whole open, the
 * AuditHook arm) and the class refuses to create or rotate through a
 * directory it does not consider safe to hold a private file: on the owned
 * HOME arm a real directory owned by this euid that nobody else may WRITE
 * (group/world write bits — the parent `.candy-code` is SHARED with
 * `config.json` and `hooks.yaml`, whose own rules already police it; a
 * LISTING there leaks nothing the sibling files do not already show, a WRITE
 * there plants the next symlink this very guard refuses); on the temp-dir
 * fallback arm, which the class creates itself, the full 0700 no-foreign-
 * access rule, same as AuditHook's directory.
 */
final class HookLog
{
    /** Byte ceiling before the current generation moves to `.1`. */
    public const ROTATE_AT_BYTES = 1024 * 1024;

    /** Ceiling the reader will honour, whatever `n` the user asked for. */
    public const MAX_ROWS = 200;

    /** Rows `/hooks log` shows when no `n` was given. */
    public const DEFAULT_ROWS = 20;

    /** Tail the reader pulls at a time, like AuditLogReader's window. */
    public const WINDOW_BYTES = 65_536;

    public const ROTATED_SUFFIX = '.1';

    /** Leaf name inside the chosen directory (or the pin's own file). */
    public const DEFAULT_LEAF = 'hooks-log.jsonl';

    /** Fallback stem when HOME is not verifiable — AuditHook's family. */
    private const DEFAULT_DIRECTORY_STEM = 'candy-code-hooks-';

    /** Create-mode of the fallback directory; the leaf's mode is umask's job. */
    private const DEFAULT_DIRECTORY_MODE = 0o700;

    /** Any foreign ACCESS bit, checked on the fallback directory we create. */
    private const FOREIGN_ACCESS_BITS = 0o077;

    /** Any foreign WRITE bit, checked on the shared HOME directory we did not create. */
    private const FOREIGN_WRITE_BITS = 0o022;

    /**
     * Where an UNCONFIGURED log lives, pinned by `tests/bootstrap.php` for
     * the suite (the E351 lesson: several suites drive configured hooks, and
     * an unpinned default would populate the developer's real
     * `~/.candy-code/hooks-log.jsonl` from test traffic). Production never
     * sets this; the seam exists so the guard stays pointed at a sandbox
     * rather than switched off.
     */
    private static ?string $filePin = null;

    /** One notice per process, whatever number of refusals preceded it. */
    private static bool $noticed = false;

    private readonly string $logFile;

    /** True when NO caller handed us a path — the guards apply to it alone.
     *  A pinned default is still a DEFAULT-derived path (the suite pin and
     *  `~/.candy-code/hooks-log.jsonl` must police identically, or the pin
     *  tests the wrong code); only a constructor argument exempts, exactly
     *  as in AuditHook, because there the caller owns that file's budget. */
    private readonly bool $ownsPath;

    /**
     * @param string|null $logFile a caller-chosen path (tests, embedders):
     *        appended under `LOCK_EX` with no directory policing and NO
     *        rotation — the caller owns that file's budget, exactly as with
     *        {@see \SugarCraft\Code\Hooks\BuiltIn\AuditHook}'s argument
     */
    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? self::defaultLogFile();
        $this->ownsPath = $logFile === null;
    }

    public static function pinDefaultLogFile(?string $path): void
    {
        self::$filePin = $path;
    }

    /** Read-not-reflected, so a test can assert bootstrap did its job. */
    public static function defaultLogFilePin(): ?string
    {
        return self::$filePin;
    }

    /** The path an unconfigured log writes: pin, owned HOME, per-uid temp. */
    public static function defaultLogFile(): string
    {
        if (self::$filePin !== null) {
            return self::$filePin;
        }

        $home = HomeDirectory::owned();
        if ($home !== null && $home !== '') {
            return $home . '/.candy-code/' . self::DEFAULT_LEAF;
        }

        return self::directoryFor(\function_exists('posix_geteuid') ? posix_geteuid() : null)
            . '/' . self::DEFAULT_LEAF;
    }

    /**
     * Append one entry. Returns whether it hit the disk; NEVER throws — the
     * fail-open bookkeeping law, pinned against every guard arm in this file
     * by tests/Hooks/HookLogTest.php.
     */
    public function record(HookLogEntry $entry): bool
    {
        try {
            return $this->append($entry->toLine());
        } catch (\Throwable $e) {
            // Anything the guards could not see still must not reach the hook.
            self::noticeRefusalOnce(static fn (): string => 'candy-code: the hook log could not be written ('
                . $e->getMessage() . '); hook results are unaffected.');

            return false;
        }
    }

    /**
     * The most recent entries, oldest first, optionally filtered to one
     * session — the tail walk AuditLogReader performs over its text lines,
     * kept here because the JSONL wire shape (and the torn-line rule) belong
     * to the writer's class. A missing file is the EMPTY trail, not an error:
     * "no configured hook has run yet" is the honest reading.
     *
     * @return array{rows: list<array<string, mixed>>, unreadable: bool}
     */
    public static function tail(int $limit = self::DEFAULT_ROWS, ?string $session = null, ?string $logFile = null): array
    {
        $limit = max(1, min(self::MAX_ROWS, $limit));
        $path = $logFile ?? self::defaultLogFile();
        $rows = [];
        $unreadable = false;

        // Current generation first, then the one rotated file it may need
        // rows from — the rotated file PREPENDS, and only fills what the
        // current file could not (AuditLogReader's budget law).
        foreach ([$path, $path . self::ROTATED_SUFFIX] as $position => $candidate) {
            $lines = self::lastCompleteLines($candidate, $limit);
            if ($lines === null) {
                $unreadable = true;
                continue;
            }

            $found = [];
            foreach ($lines as $line) {
                $entry = HookLogEntry::fromLine($line);
                if ($entry === null || ($session !== null && $entry->session !== $session)) {
                    continue;
                }
                $found[] = $entry->toArray();
            }

            $rows = $position === 0 ? $found : [...$found, ...$rows];
            if (\count($rows) >= $limit) {
                break;
            }
        }

        return ['rows' => \count($rows) > $limit ? \array_slice($rows, -$limit) : $rows, 'unreadable' => $unreadable];
    }

    /**
     * @return list<string>|null lines, or null when the file exists but could
     *         not be read (the unreadable arm the caller must not render as
     *         an empty trail)
     */
    private static function lastCompleteLines(string $path, int $count): ?array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false) {
            return [];
        }
        if ($size === 0) {
            return [];
        }

        // Walk back whole windows until the head lands on a newline: the
        // window's first line is complete iff it begins at offset 0 or right
        // after "\n". Oversized single lines are bounded by WINDOW*4 growth
        // only up to the file — a file whose tail region is unreadable
        // answers null, which the caller shows rather than hides.
        $start = max(0, $size - self::WINDOW_BYTES);
        $span = min($size, self::WINDOW_BYTES);
        while ($start > 0 && self::window($path, $start - 1, 1) !== "\n") {
            $span = min($size, $span * 4);
            $start = $size - $span;
        }

        $text = self::window($path, $start, $size - $start);
        if ($text === null) {
            return null;
        }

        $lines = explode("\n", $text);
        array_pop($lines); // torn trailing fragment (or the record's own "\n")
        $lines = array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));

        return \count($lines) > $count ? \array_slice($lines, -$count) : $lines;
    }

    /**
     * Bounded positional read; "" when absent (the same empty answer the
     * walk needs to terminate), null when the path exists but is not a
     * readable regular file. lstat gates the open: a FIFO or a directory
     * here would block `fopen` or read a lie.
     */
    private static function window(string $path, int $offset, int $length): ?string
    {
        if ($offset < 0 || $length <= 0) {
            return '';
        }

        $stat = @lstat($path);
        if ($stat === false) {
            return '';
        }
        if (((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return null;
            }

            return (string) stream_get_contents($handle, $length);
        } finally {
            fclose($handle);
        }
    }

    /** The whole refusal-checked append; see record() for the fail-open arm. */
    private function append(string $entry): bool
    {
        $directory = \dirname($this->logFile);

        if ($this->ownsPath && !self::directoryIsUsable($directory)) {
            self::noticeRefusalOnce(static fn (): string => self::directoryRefusalReason($directory));

            return false;
        }

        if ($this->ownsPath && is_link($this->logFile)) {
            self::noticeRefusalOnce(fn (): string => 'candy-code: ' . $this->logFile
                . ' is a symbolic link, so the hook log refuses to write through it.');

            return false;
        }

        if ($this->ownsPath) {
            $this->rotateIfNeeded();
        }

        // The umask covers CREATE; an existing leaf keeps its mode (the file
        // was either made by this arm or is caller-supplied — policing a
        // file we do not own the path of is AuditHook's declared boundary).
        $previous = $this->ownsPath ? umask(self::FOREIGN_ACCESS_BITS) : null;
        try {
            return @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX) !== false;
        } finally {
            if ($previous !== null) {
                umask($previous);
            }
        }
    }

    /** One generation deep is the declared budget; `.1` overwrites its elder. */
    private function rotateIfNeeded(): void
    {
        $size = @filesize($this->logFile);
        if ($size === false || $size <= self::ROTATE_AT_BYTES) {
            return;
        }

        @rename($this->logFile, $this->logFile . self::ROTATED_SUFFIX);
        clearstatcache(true, $this->logFile);
    }

    /**
     * Is this directory safe to create/rotate a private file through?
     *
     * Shared HOME arm (`.candy-code`, which other code owns): a real
     * directory, not a symlink, owned by this euid where the check exists,
     * and free of foreign WRITE bits — a foreign-writable parent means the
     * next entry of this guard's checks could be a planted symlink, which is
     * the very shape refusing rather than repairing exists to stop.
     * Fallback arm (the class's own per-uid directory): the full AuditHook
     * rule — no foreign ACCESS bits at all, since a stranger reading the
     * tail of hook messages is the leak this file is about.
     */
    private static function directoryIsUsable(string $directory): bool
    {
        clearstatcache(true, $directory);

        if (is_link($directory)) {
            return false;
        }

        if (!is_dir($directory)) {
            if (file_exists($directory)) {
                return false; // a FILE where a directory belongs is never repaired
            }
            if (!@mkdir($directory, self::DEFAULT_DIRECTORY_MODE, true) && !is_dir($directory)) {
                return false;
            }
            clearstatcache(true, $directory);
            if (\function_exists('posix_geteuid')) {
                $owner = @fileowner($directory);
                if ($owner !== false && $owner !== posix_geteuid()) {
                    return false;
                }
            }
        }

        $perms = @fileperms($directory);
        if ($perms === false) {
            return false;
        }

        // The class only created the fallback arm; the HOME arm predates it.
        $isFallbackArm = str_starts_with($directory, sys_get_temp_dir() . '/' . self::DEFAULT_DIRECTORY_STEM);

        if ($isFallbackArm) {
            if (\function_exists('posix_geteuid')) {
                $owner = @fileowner($directory);
                if ($owner !== false && $owner !== posix_geteuid()) {
                    return false;
                }
            }

            return ((int) $perms & self::FOREIGN_ACCESS_BITS) === 0; // accepted at 0700 or tighter
        }

        if (\function_exists('posix_geteuid')) {
            $owner = @fileowner($directory);
            if ($owner !== false && $owner !== posix_geteuid()) {
                return false;
            }
        }

        return ((int) $perms & self::FOREIGN_WRITE_BITS) === 0;
    }

    /** Spoken, never re-derived by the caller (the audit hook's reason shape). */
    private static function directoryRefusalReason(string $directory): string
    {
        return 'candy-code: the hook log directory ' . $directory
            . ' is not this user\'s private directory (symlink, wrong owner, or foreign-writable), '
            . 'so hook runs are not being logged. The chain itself is unaffected.';
    }

    /** The fallback directory for a uid, AuditHook's naming family. */
    private static function directoryFor(?int $uid): string
    {
        return sys_get_temp_dir() . '/' . self::DEFAULT_DIRECTORY_STEM . ($uid ?? 'noposix');
    }

    /**
     * At most one notice per process, and the REASON CLOSURE IS ONLY INVOKED
     * WHEN THE NOTICE WILL FIRE (AuditHook's measured lazy-arm law: a spent
     * latch must not build the message it is not going to say).
     */
    private static function noticeRefusalOnce(\Closure $reason): void
    {
        if (self::$noticed) {
            return;
        }
        self::$noticed = true;
        RuntimeNoticeSink::warn($reason());
    }
}
