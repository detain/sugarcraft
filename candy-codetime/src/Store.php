<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\Async\CancellationToken;
use SugarCraft\CodeTime\Storage\HeartbeatReader;

/**
 * JSONL-backed heartbeat store. One file per day under
 * `~/.local/share/candy-codetime/YYYY-MM-DD.jsonl` — the path stays
 * appendable, every editor plugin just `>>` echoes a JSON line.
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

    // memoization, not observable state
    private array $dayCache = [];

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
     * absolute file paths and project names: on a multi-user box a 0755
     * directory hands that map to every account. Creation happens under a
     * narrowed umask so the directory is never world-readable for an instant;
     * a pre-existing loose directory (from the old 0755 era) is tightened on
     * the next write. A failure to create or to tighten THROWS rather than
     * storing privacy-relevant data in the open — the same law AutoBackup
     * states about silent copy loss, applied one file over (the shape is
     * candy-code's PrivateDir::ensure, vendored small).
     *
     * @throws \RuntimeException if the directory cannot be created or cannot
     *                           be made owner-private
     */
    public static function ensurePrivateDir(string $dir): void
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
                    'candy-codetime: cannot create data directory ' . $dir
                    . (isset($last['message']) ? ': ' . $last['message'] : ''),
                );
            }
        }
        self::makePrivate($dir, 0o077, 0o700, 'data directory');
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
                "candy-codetime: cannot make {$label} {$path} owner-private",
            );
        }
    }

    /** @return list<Heartbeat> */
    public function loadDay(\DateTimeImmutable $day): array
    {
        $key = $day->format('Y-m-d');
        if (isset($this->dayCache[$key])) {
            return $this->dayCache[$key];
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
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return [];
        }
        $rows = [];
        try {
            while (($line = fgets($fh, self::MAX_LINE_BYTES)) !== false) {
                if (!str_ends_with($line, "\n") && !feof($fh)) {
                    // Over-cap line: keep draining until the logical line ends,
                    // but never hold more than one buffer of it.
                    while (($drain = fgets($fh, self::MAX_LINE_BYTES)) !== false) {
                        if (str_ends_with($drain, "\n")) {
                            break;
                        }
                    }
                    continue;
                }
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = Heartbeat::fromArray($decoded);
                }
            }
        } finally {
            fclose($fh);
        }
        $this->dayCache[$key] = $rows;
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
                'candy-codetime: heartbeat could not be encoded: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_file($file)) {
            // Exclusive create so a racing appender is never clobbered, then the
            // mode is tightened BEFORE any content exists — no loose-data window.
            $seed = @fopen($file, 'xb');
            if ($seed === false) {
                $last = error_get_last();
                throw new \RuntimeException(
                    'candy-codetime: cannot create ' . $file
                    . (isset($last['message']) ? ': ' . $last['message'] : ''),
                );
            }
            fclose($seed);
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
                'candy-codetime: failed to append heartbeat to ' . $file
                . (isset($last['message']) ? ': ' . $last['message'] : ''),
            );
        }
    }
}
