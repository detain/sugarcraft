<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Persistent, disk-backed cache for rendered terminal images.
 *
 * Encoded output (the ANSI/sixel/kitty bytes a {@see Mosaic} produces) is
 * expensive to recompute: a poster must be fetched, GD-decoded, scaled, and
 * protocol-encoded. This cache stores the finished bytes on disk keyed by the
 * poster's identity — see {@see DiskCache::key()} — so a redraw across process
 * restarts is an O(1) file read.
 *
 * It pairs with the in-memory {@see AdaptiveImage} LRU: AdaptiveImage avoids
 * re-encoding within a session; DiskCache avoids re-fetching/re-encoding across
 * sessions. The cache is a generic string store, so any value can be cached
 * under any key; the protocol-aware {@see DiskCache::key()} helper is the
 * intended key for rendered images (the same image at the same cell size
 * renders differently per protocol).
 *
 * Eviction is approximately least-recently-used by file mtime:
 * {@see DiskCache::get()} and {@see DiskCache::getOrCompute()} touch an entry on
 * a hit, and {@see put()} trims the oldest entries once the directory exceeds
 * the configured cap. Because mtime is 1-second-resolution on most filesystems,
 * entries written within the same second order arbitrarily among themselves —
 * the cap is always honoured, but the victim within a same-second burst is not
 * strictly the least-recently-used.
 *
 * Writes are atomic (temp file + rename), so a concurrent reader never sees a
 * half-written entry; a process that dies mid-write can leave a stray temp
 * file, which {@see put()} sweeps once stale and {@see clear()} removes. Keys
 * are hashed to derive the on-disk filename, so an arbitrary (even
 * attacker-supplied) key can never escape the cache directory.
 */
final class DiskCache
{
    /** A write temp file older than this (seconds) is treated as orphaned. */
    private const TEMP_GRACE_SECONDS = 30;

    /**
     * Cache-format version, mixed into every {@see key()}.
     *
     * Bump this whenever the encoded bytes a renderer emits for the same
     * (url, size, protocol) change in a way that would make a previously cached
     * entry render incorrectly — e.g. a row-separator or escape-sequence fix.
     * Because the version is part of the hashed key, old entries simply stop
     * matching and are re-rendered on demand (and aged out by LRU), so a fix
     * ships without anyone having to manually clear the on-disk cache.
     *
     * v2: half/quarter-block rows are joined with "\n" (were "\r\n"); CRLF-era
     *     entries rendered as a single collapsed line and must not be reused.
     * v3: sixel encoding rewritten to be spec-correct (ST terminator, `#` colour
     *     declarations, `"` raster, `-` band joins, pixel-resolution canvas);
     *     v2-era sixel blobs were unparseable and printed as text.
     * v4: iTerm2 OSC 1337 fixed — base64 now follows `File=<args>:`, and the PNG
     *     re-encode no longer leaks to stdout; v3 iterm2 blobs were malformed.
     */
    private const FORMAT_VERSION = 4;

    /**
     * @param string $dir         Directory that holds the cache entries
     *                            (created on first write if missing).
     * @param int    $maxEntries  Soft cap on stored entries; the oldest are
     *                            evicted after each write once exceeded.
     * @throws \InvalidArgumentException  if $maxEntries is below 1
     */
    public function __construct(
        private readonly string $dir,
        private readonly int $maxEntries = 512,
    ) {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException(
                Lang::t('disk_cache.max_entries', ['max' => $maxEntries]),
            );
        }
    }

    /**
     * Canonical cache key for a rendered image.
     *
     * Combines the source URL, target cell dimensions, render protocol, and the
     * {@see FORMAT_VERSION} — everything that changes the encoded bytes — into a
     * stable hash. Bumping the format version retires every prior entry so a
     * renderer fix can never serve stale, wrongly-encoded bytes.
     */
    public static function key(string $url, int $width, int $height, string $protocol): string
    {
        return sha1($url . '|' . $width . '|' . $height . '|' . $protocol . '|v' . self::FORMAT_VERSION);
    }

    /** Whether an entry exists for the key (does not affect LRU order). */
    public function has(string $key): bool
    {
        return is_file($this->path($key));
    }

    /**
     * Return the cached value, or null on a miss.
     *
     * A hit touches the entry so it counts as recently used for eviction.
     */
    public function get(string $key): ?string
    {
        $path  = $this->path($key);
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        @touch($path);

        return $bytes;
    }

    /**
     * Store a value for the key, then evict the oldest entries if over cap.
     *
     * The write is atomic: bytes land in a temp file that is renamed into
     * place, so readers never observe a partial entry.
     *
     * @throws \RuntimeException  if the cache directory or entry cannot be written
     */
    public function put(string $key, string $value): void
    {
        $this->ensureDir();

        $path = $this->path($key);
        $tmp  = @tempnam($this->dir, 'mc-');
        if ($tmp === false) {
            throw new \RuntimeException(Lang::t('disk_cache.write_failed', ['dir' => $this->dir]));
        }

        if (@file_put_contents($tmp, $value) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(Lang::t('disk_cache.write_failed', ['dir' => $this->dir]));
        }

        $this->evict();
    }

    /**
     * Return the cached value for the key, computing and storing it on a miss.
     *
     * @param callable(): string $compute  Produces the value when absent.
     */
    public function getOrCompute(string $key, callable $compute): string
    {
        $hit = $this->get($key);
        if ($hit !== null) {
            return $hit;
        }

        $value = $compute();
        $this->put($key, $value);

        return $value;
    }

    /** Remove a single entry (no-op if absent). */
    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    /** Remove every entry in the cache directory, including any stray temp files. */
    public function clear(): void
    {
        foreach ($this->files() as $file) {
            @unlink($file);
        }
        foreach ($this->tempFiles() as $tmp) {
            @unlink($tmp);
        }
    }

    /** Number of stored entries. */
    public function count(): int
    {
        return count($this->files());
    }

    /**
     * Evict least-recently-used entries until at or below the cap.
     */
    private function evict(): void
    {
        $this->sweepStaleTemp();

        $files = $this->files();
        $excess = count($files) - $this->maxEntries;
        if ($excess <= 0) {
            return;
        }

        usort($files, fn (string $a, string $b): int => $this->mtime($a) <=> $this->mtime($b));

        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * Remove temp files orphaned by a process that died between tempnam() and
     * rename(). A live write occupies its temp file for microseconds, so any
     * `mc-*` older than the grace window is certainly abandoned.
     */
    private function sweepStaleTemp(): void
    {
        $cutoff = time() - self::TEMP_GRACE_SECONDS;
        foreach ($this->tempFiles() as $tmp) {
            if ($this->mtime($tmp) < $cutoff) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return list<string>  Absolute paths of stray write temp files.
     */
    private function tempFiles(): array
    {
        $found = @glob($this->dir . '/mc-*');

        return $found === false ? [] : $found;
    }

    /**
     * @return list<string>  Absolute paths of the current cache entries.
     */
    private function files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $found = @glob($this->dir . '/*.cache');

        return $found === false ? [] : $found;
    }

    private function mtime(string $path): int
    {
        $mtime = @filemtime($path);

        return $mtime === false ? 0 : $mtime;
    }

    private function ensureDir(): void
    {
        if (is_dir($this->dir)) {
            return;
        }

        if (!@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(Lang::t('disk_cache.mkdir_failed', ['dir' => $this->dir]));
        }
    }

    /**
     * Map a key to its on-disk path. The key is hashed, so it can never
     * contain path separators that escape the cache directory.
     */
    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.cache';
    }
}
