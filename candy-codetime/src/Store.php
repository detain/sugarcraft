<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\Async\CancellationToken;
use SugarCraft\CodeTime\Storage\HeartbeatReader;
use SugarCraft\Core\Util\Sanitize;

/**
 * JSONL-backed heartbeat store. One file per day under
 * `~/.local/share/candy-codetime/YYYY-MM-DD.jsonl` — the path stays
 * appendable, every editor plugin just `>>` echoes a JSON line; whatever
 * loose modes such a line is minted with are swept back to owner-private by
 * {@see self::enforcePrivateModes()} on the next CLI launch.
 *
 * The dashboard treats the store as read-only; writes happen via the
 * `push` CLI subcommand or by your editor plugin appending directly.
 * Keeps the dashboard model simple (no race with editor writes) and lets
 * an old-school cron job ship the file off to cold storage without
 * locking concerns.
 */
final class Store implements HeartbeatReader
{
    /**
     * Upper bound on a CLI date-range walk (~10 years). `loadRange()` iterates
     * one `loadDay()` per day, so an unclamped day count from the CLI could walk
     * a century of empty days. Guards `export`/`gaps` — see Store::clampRangeDays().
     */
    public const MAX_RANGE_DAYS = 3660;

    /**
     * Hard cap on one JSONL line (1 MiB). A hostile or corrupt file cannot
     * drive loadDay()'s memory with a single multi-gigabyte "line" — anything
     * past this is dropped and drained.
     */
    public const MAX_LINE_BYTES = 1_048_576;

    /**
     * How many day files the memo may retain (CT-S16): the widest window the
     * store itself will serve, so the cache can never outgrow a legitimate
     * full-range load. Served days rotate to the tail; the head ages out.
     */
    public const DAY_CACHE_MAX_DAYS = self::MAX_RANGE_DAYS;

    // memoization, not observable state
    private array $dayCache = [];

    /**
     * Lines the last read wave dropped (CT-S19): over-cap monsters and lines
     * that are not JSON at all, plus days that could not be opened at all.
     * The dashboard drains these onto its model so the TUI can report
     * in-frame; CLI paths print one counted notice per file on stderr, the
     * same single-deliberate-line footing the unreadable-day warning had.
     */
    private int $droppedLineCount = 0;

    private int $unreadableDayCount = 0;

    /** True once the alt screen owns the terminal (see silenceDiagnostics). */
    private bool $silentDiagnostics = false;

    public function __construct(public readonly string $dir)
    {}

    /**
     * Clamp a requested day count to [1, MAX_RANGE_DAYS] so an out-of-range
     * value from the CLI cannot drive `loadRange()` into an unbounded walk.
     */
    public static function clampRangeDays(int $days): int
    {
        return max(1, min($days, self::MAX_RANGE_DAYS));
    }

    /** Default per-user directory (XDG / fallback to ~). */
    public static function defaultDir(): string
    {
        $xdg = getenv('XDG_DATA_HOME');
        if ($xdg !== false && $xdg !== '') {
            return rtrim($xdg, '/') . '/candy-codetime';
        }
        return rtrim(getenv('HOME') ?: '.', '/') . '/.local/share/candy-codetime';
    }

    /**
     * Create (or tighten) the data directory to owner-private 0700.
     *
     * WHY. The app's tagline is "privacy-first", yet the data dir holds
     * file paths exactly as supplied (editor plug-ins typically send
     * absolute ones) plus project names: on a multi-user box a 0755
     * directory hands that map to every account. Creation happens under a
     * narrowed umask so the directory is never world-readable for an instant;
     * a pre-existing loose directory (from the old 0755 era) is tightened on
     * the next write. A failure to create or to tighten THROWS rather than
     * storing privacy-relevant data in the open — the same law AutoBackup
     * states about silent copy loss, applied one file over (the shape is
     * candy-code's PrivateDir::ensure, vendored small).
     *
     * @param string $label nouns the message uses for the path, so a caller
     *                      creating a *backup* directory does not report a
     *                      "data directory" failure
     * @throws \RuntimeException if the directory cannot be created or cannot
     *                           be made owner-private
     */
    public static function ensurePrivateDir(string $dir, string $label = 'data directory'): void
    {
        if (!is_dir($dir)) {
            $previous = umask(0o077);
            try {
                $created = @mkdir($dir, 0o700, true);
            } finally {
                umask($previous);
            }
            if (!$created && !is_dir($dir)) {
                $last = error_get_last();
                throw new \RuntimeException(
                    'cannot create ' . $label . ' ' . $dir
                    . (isset($last['message']) ? ': ' . $last['message'] : ''),
                );
            }
        }
        self::makePrivate($dir, 0o077, 0o700, $label);
    }

    /**
     * Tighten an existing file to owner-private 0600, throwing when the chmod
     * fails. Public because {@see AutoBackup} must apply the same law to the
     * copies it writes: a byte-for-byte clone of a private day file, left at
     * the umask default, leaks the same paths (as supplied) one directory over.
     *
     * @throws \RuntimeException if the mode cannot be settled
     */
    public static function ensurePrivateFile(string $path, string $label = 'data file'): void
    {
        self::makePrivate($path, 0o077, 0o600, $label);
    }

    /**
     * Re-establish the whole privacy contract, not just the paths this
     * process writes. A plugin that direct-appends with `>>` runs no
     * candy-codetime code at all, and a pre-rename migration merely moves
     * the tree — files minted that way can sit at the shell's umask, world-
     * readable absolute paths and project maps. The sweep tightens the data
     * directory (0700), every day file and archived day file (0600), and the
     * SQLite database with its `-wal`/`-shm` sidecars (0600). It runs on
     * every CLI launch — reads included — so any exposure lasts at most
     * until the tool is run next, and it throws on an unfixable mode exactly
     * like the write paths do.
     */
    public function enforcePrivateModes(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        self::ensurePrivateDir($this->dir);
        foreach (new \GlobIterator($this->dir . '/*.jsonl') as $beat) {
            self::ensurePrivateFile($beat->getPathname(), 'day file');
        }
        foreach (['candy-codetime.db', 'candy-codetime.db-wal', 'candy-codetime.db-shm'] as $database) {
            if (is_file($path = $this->dir . '/' . $database)) {
                self::ensurePrivateFile($path, 'database file');
            }
        }
        $backupDir = $this->dir . '-backups';
        if (is_dir($backupDir)) {
            self::ensurePrivateDir($backupDir, 'backup directory');
            foreach (new \GlobIterator($backupDir . '/*.jsonl') as $archived) {
                self::ensurePrivateFile($archived->getPathname(), 'archived heartbeat file');
            }
        }
    }

    /**
     * Stop the store writing to stderr; counting continues (CT-S19). The
     * dashboard launcher calls this the moment the alt screen takes over, so
     * mid-session reloads can never spray raw bytes into a sync frame.
     */
    public function silenceDiagnostics(): void
    {
        $this->silentDiagnostics = true;
    }

    /**
     * Take the data-loss counters since the last drain (CT-S19). Resetting
     * here is what makes a footer count describe the load it accompanies
     * instead of the whole process lifetime.
     *
     * @return array{dropped: int, unreadable: int}
     */
    public function drainDiagnostics(): array
    {
        $drained = ['dropped' => $this->droppedLineCount, 'unreadable' => $this->unreadableDayCount];
        $this->droppedLineCount = 0;
        $this->unreadableDayCount = 0;
        return $drained;
    }

    /**
     * Tighten one path to owner-private modes, refusing to proceed when the
     * chmod fails (silent loose modes would contradict the privacy contract).
     */
    private static function makePrivate(string $path, int $looseMask, int $target, string $label): void
    {
        $mode = @fileperms($path);
        if ($mode === false || ($mode & $looseMask) === 0) {
            return;
        }
        if (!@chmod($path, $target)) {
            throw new \RuntimeException(
                "cannot make {$label} {$path} owner-private",
            );
        }
    }

    /** @return list<Heartbeat> */
    public function loadDay(\DateTimeImmutable $day): array
    {
        $key = $day->format('Y-m-d');
        if (isset($this->dayCache[$key])) {
            $cached = $this->dayCache[$key];
            // LRU touch (CT-S16): the most recently served day sits at the
            // tail and is the last to be evicted.
            unset($this->dayCache[$key]);
            $this->dayCache[$key] = $cached;
            return $cached;
        }
        $file = $this->dir . '/' . $key . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        // Stream the file line-by-line with fgets() rather than slurping the whole
        // day into memory — a busy day's JSONL can grow large and loadRange()
        // stacks many of these, so bounded per-line memory matters. The length
        // argument bounds one line too: a chunk that stops short of its newline
        // marks a monster line, which is drained and dropped (MAX_LINE_BYTES).
        // The @ keeps the degradation exactly one deliberate line: PHP's own
        // fopen diagnostic would otherwise print a second, un-sanitised one
        // beside the warning below (which carries the same reason anyway).
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            // Reads degrade loudly: an existing day file we cannot open would
            // otherwise under-report that day in total silence. Name it on
            // stderr (the path rode in from CANDY_CODETIME_DIR, so it is
            // swept like every other way out) and carry on with an empty day
            // — the reader's exit shape is unchanged.
            $last = error_get_last();
            $this->unreadableDayCount++;
            if ($this->silentDiagnostics) {
                return [];
            }
            fwrite(STDERR, 'candy-codetime: warning: cannot read day file '
                . Sanitize::untrusted($file)
                . (isset($last['message']) ? ': ' . Sanitize::untrusted((string) $last['message']) : '')
                . "\n");
            return [];
        }
        $rows = [];
        $overCap = 0;
        $malformed = 0;
        $firstOffender = 0;
        $lineNo = 0;
        try {
            while (($line = fgets($fh, self::MAX_LINE_BYTES)) !== false) {
                $lineNo++;
                if (!str_ends_with($line, "\n") && !feof($fh)) {
                    // Over-cap line: keep draining until the logical line ends,
                    // but never hold more than one buffer of it.
                    while (($drain = fgets($fh, self::MAX_LINE_BYTES)) !== false) {
                        if (str_ends_with($drain, "\n")) {
                            break;
                        }
                    }
                    $overCap++;
                    continue;
                }
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    try {
                        $rows[] = Heartbeat::fromArray($decoded);
                    } catch (\Throwable) {
                        // CT-S15: a decoded row without a usable time is
                        // corrupt storage — dropped on the same loud lane as
                        // undecodable lines, never stamped with wall-clock NOW.
                        $firstOffender = $firstOffender === 0 ? $lineNo : $firstOffender;
                        $malformed++;
                    }
                } else {
                    // CT-S19: a line nobody will ever read must still be
                    // counted here — the silence around these drains was the
                    // bug, and one offender's position is worth naming.
                    $firstOffender = $firstOffender === 0 ? $lineNo : $firstOffender;
                    $malformed++;
                }
            }
        } finally {
            fclose($fh);
        }
        // One counted notice per file per read wave; the accumulators feed
        // the dashboard footer whether or not the stderr lane is open.
        $this->droppedLineCount += $overCap + $malformed;
        if (!$this->silentDiagnostics && ($overCap > 0 || $malformed > 0)) {
            if ($overCap > 0) {
                fwrite(STDERR, 'candy-codetime: warning: dropped ' . $overCap
                    . ' over-cap line(s) from ' . Sanitize::untrusted($file) . "\n");
            }
            if ($malformed > 0) {
                fwrite(STDERR, 'candy-codetime: warning: dropped ' . $malformed
                    . ' unparseable line(s) from ' . Sanitize::untrusted($file)
                    . ' (first at line ' . $firstOffender . ')' . "\n");
            }
        }
        $this->dayCache[$key] = $rows;
        while (count($this->dayCache) > self::DAY_CACHE_MAX_DAYS) {
            unset($this->dayCache[array_key_first($this->dayCache)]);
        }
        return $rows;
    }

    /**
     * Load every heartbeat in [$from, $to] — date range inclusive.
     *
     * @return list<Heartbeat>
     */
    public function loadRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $chunks = [];
        $cur = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);
        while ($cur <= $end) {
            $chunks[] = $this->loadDay($cur);
            $cur  = $cur->modify('+1 day');
        }
        return array_merge([], ...$chunks);
    }

    /** Invalidate the day cache (forces reload on next loadDay). */
    public function invalidate(): void
    {
        $this->dayCache = [];
    }

    /**
     * Append a heartbeat to today's JSONL file.
     *
     * @param Heartbeat $hb
     * @param CancellationToken|null $token  Optional cancellation token to abort the write
     * @throws \RuntimeException  If the write is cancelled, the data dir cannot be
     *                            made private, the heartbeat cannot be encoded, or
     *                            the write itself fails
     *
     * @note CancellationToken is checked immediately before the write.
     *       If cancelled at that point the write is skipped and an exception is thrown.
     *       PHP cannot interrupt a blocking `file_put_contents()` mid-write.
     */
    public function append(Heartbeat $hb, ?CancellationToken $token = null): void
    {
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException('Heartbeat append cancelled');
        }

        self::ensurePrivateDir($this->dir);
        $file = $this->dir . '/' . date('Y-m-d', $hb->time) . '.jsonl';

        try {
            $line = json_encode($hb->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (\JsonException $e) {
            // An unencodable heartbeat (raw invalid UTF-8 in a field) must never
            // become a silent blank line — the old false concat wrote exactly that.
            throw new \RuntimeException(
                'heartbeat could not be encoded: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        // Exclusive create so a racing appender is never clobbered; the mode is
        // then tightened before any content exists — no loose-data window. The
        // attempt comes FIRST and existence is re-read only on failure: the
        // previous check-then-create order let two pushers (plugin + CLI hit
        // the same brand-new day) race between is_file() and fopen(), so the
        // loser saw EEXIST and its heartbeat died as a "cannot create"
        // exception. A racer having just created the file is exactly the state
        // this branch wants, not an error.
        $seed = @fopen($file, 'xb');
        if ($seed !== false) {
            fclose($seed);
        } elseif (!is_file($file)) {
            $last = error_get_last();
            throw new \RuntimeException(
                'cannot create ' . $file
                . (isset($last['message']) ? ': ' . $last['message'] : ''),
            );
        }
        self::makePrivate($file, 0o077, 0o600, 'heartbeat file');

        // Re-check cancellation immediately before the write
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException('Heartbeat append cancelled');
        }

        // LOCK_EX serialises concurrent appenders (editor plugin + CLI + cron)
        // so their lines can't interleave into a torn write.
        $written = @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        if ($written !== strlen($line)) {
            // Catches BOTH shapes of loss: a hard failure (false) and a short
            // write (a partial count — what an RLIMIT_FSIZE child actually
            // produces: the syscall dies at the cap with the signal ignored).
            // Silence here is data loss: push would print "entry pushed" and exit 0
            // while storing nothing (or half a line). AutoBackup's docblock sermon
            // on the same law one file over — a failed write must be as loud as a
            // failed backup.
            $last = error_get_last();
            throw new \RuntimeException(
                'failed to append heartbeat to ' . $file
                . (isset($last['message']) ? ': ' . $last['message'] : ''),
            );
        }
    }
}
