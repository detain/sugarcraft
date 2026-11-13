<?php

declare(strict_types=1);

namespace SugarCraft\Image;

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
 * Eviction is approximately least-recently-used by file mtime, under two
 * budgets: {@see DiskCache::get()} and {@see DiskCache::getOrCompute()}
 * touch an entry on a hit (coarsely — an entry younger than a minute is
 * not re-written), and {@see put()} trims the oldest entries once the
 * directory exceeds the entry cap, or once its bytes pass 125 % of the
 * byte budget (trimming back to budget). Because mtime is
 * 1-second-resolution on most filesystems, entries written within the same
 * second order arbitrarily among themselves — the caps are always honoured,
 * but the victim within a same-second burst is not strictly the
 * least-recently-used (it is deterministic: oldest, then lowest path).
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
     * Default byte budget for the whole cache directory — the lib's own
     * {@see ImageSource::MAX_BYTES} law: the store must never hold (or
     * slurp) a payload the pipeline itself refuses to fetch.
     */
    public const DEFAULT_MAX_BYTES = ImageSource::MAX_BYTES;

    /** A hit younger than this (seconds) skips its LRU touch (finding 014). */
    private const TOUCH_MIN_INTERVAL_SECONDS = 60;

    /**
     * The byte-budget sweep wakes only once usage passes this multiple of
     * $maxBytes and then trims back to the cap — trimming on every put
     * that crosses the line by one byte is churn, not eviction (finding 014).
     */
    private const EVICT_DEBOUNCE_FACTOR = 1.25;

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
     * v5: quarter-block renderer rewritten to real 2-colour sub-cell glyphs (was a
     *     fake shade renderer with fg==bg, i.e. solid blocks).
     * v6: cover scaling (Fill/Crop) no longer pre-downscales the image to the cell
     *     count and crops to the cell box's true display aspect — every renderer
     *     now downsamples from full resolution, so all cached bytes changed.
     * v7: Sixel alpha — transparent pixels now emit the `#0;2;P` background
     *     register with the colour palette shifted to 1..N instead of blending
     *     to black, so cached sixel blobs for alpha-bearing sources changed.
     * v8: three behaviour fixes — the poster key now carries the renderer config
     *     fingerprint for fingerprinting renderers (dither / maxColors / cell
     *     geometry, chafa options / format), the Stucki kernel conserves
     *     42/42, and the sixel nearest-colour memo answers the block centre
     *     instead of the first pixel that warmed the entry; sixel blobs
     *     cached under v7 can encode all three defects, so every prior entry
     *     is retired rather than selectively re-keyed.
     */
    private const FORMAT_VERSION = 8;

    /**
     * @param string $dir         Directory that holds the cache entries
     *                            (created on first write if missing).
     * @param int    $maxEntries  Soft cap on stored entries; the oldest are
     *                            evicted after each write once exceeded.
     * @param int    $maxBytes    Byte budget for the stored entries; the
     *                            sweep (see EVICT_DEBOUNCE_FACTOR) trims the
     *                            oldest back under it, and get() refuses to
     *                            read any single entry above it (finding 013).
     *                            Defaults to the lib's MAX_BYTES law.
     * @throws \InvalidArgumentException  if $maxEntries is below 1 or
     *                            $maxBytes is below 1
     */
    public function __construct(
        private readonly string $dir,
        private readonly int $maxEntries = 512,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException(
                Lang::t('disk_cache.max_entries', ['max' => $maxEntries]),
            );
        }

        if ($maxBytes < 1) {
            throw new \InvalidArgumentException(
                Lang::t('disk_cache.max_bytes', ['max' => $maxBytes]),
            );
        }
    }

    /**
     * Test seam for the LRU touch clock (same override-*-style pattern as
     * {@see ImageSource::overrideHostResolver()}): lets a test age entries
     * by virtual seconds instead of wall-waiting sixty of them.
     */
    public static function overrideClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    /** @var (callable(): int)|null */
    private static $clock;

    private static function now(): int
    {
        $clock = self::$clock;

        return $clock === null ? time() : (int) $clock();
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
     * Two ceilings (finding 013): a single entry above $maxBytes reads as a
     * miss — the lib refuses to source payloads past its MAX_BYTES law, so
     * the store must not slurp them either — and the directory as a whole is
     * swept back under budget by put(). Such an entry is never touched, so
     * it carries the oldest mtime in the house and ages out of LRU first:
     * the miss is self-healing, not a permanent orphan.
     *
     * A hit touches the entry so it counts as recently used for eviction —
     * coarsely: an entry written or touched within the last minute is NOT
     * re-touched (finding 014). A redraw loop asking for the same poster
     * every frame used to pay one write syscall per hit for an mtime change
     * that 1-second filesystem resolution cannot even observe between
     * frames; the LRU ordering the touch exists for is unaffected, because
     * per-second granularity is all the eviction sort can see anyway.
     */
    public function get(string $key): ?string
    {
        $path  = $this->path($key);
        $stamp = @stat($path); // one syscall: size ceiling AND age below
        if ($stamp === false) {
            return null;
        }

        if ($stamp['size'] > $this->maxBytes) {
            return null; // over the single-entry ceiling — treat as a miss
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        if (self::now() - $stamp['mtime'] >= self::TOUCH_MIN_INTERVAL_SECONDS) {
            @touch($path);
        }

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
     * Ruling 3b keeper (2026-10-24): get* here is cache store vocabulary — the get()/put() read/write verb pair (KV family), not a state accessor.
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
     * Evict until the directory is back inside BOTH budgets: the entry
     * count at most $maxEntries (trimmed immediately — a cap that lags a
     * burst is no cap), and the total bytes at most $maxBytes, swept only
     * once usage has passed EVICT_DEBOUNCE_FACTOR of the budget (finding
     * 014: a cap-125%-wide band means entries oscillating around the line
     * cost one sweep per overshoot instead of one per full band).
     *
     * Performance law: every surviving entry is stat-ed exactly once into
     * a (path, mtime, size) tuple and the tuples are sorted once. The
     * pre-finding comparator called filemtime() inside usort — O(n log n)
     * syscalls per put on top of the per-second re-glob — which is how a
     * cache earned the word "hot" for all the wrong reasons.
     */
    private function evict(): void
    {
        $this->sweepStaleTemp();

        $entries = $this->collectEntries();
        $excess  = count($entries) - $this->maxEntries;

        // Oldest first; equal mtimes (the same-second burst) order by path
        // so the victim list is deterministic across platforms — the count
        // cap itself keeps the pre-finding semantics untouched.
        usort($entries, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime'] ?: strcmp($a['path'], $b['path']));

        $victims = [];
        for ($i = 0; $i < $excess; $i++) {
            $victims[] = $entries[$i]['path'];
        }

        $bytes = 0;
        foreach ($entries as $entry) {
            $bytes += $entry['size'];
        }

        if ($bytes > $this->maxBytes * self::EVICT_DEBOUNCE_FACTOR) {
            for ($i = $excess > 0 ? $excess : 0; $i < count($entries) && $bytes > $this->maxBytes; $i++) {
                $bytes -= $entries[$i]['size'];
                $victims[] = $entries[$i]['path'];
            }
        }

        foreach ($victims as $path) {
            @unlink($path);
        }
    }

    /**
     * @return list<array{path: string, mtime: int, size: int}>
     */
    private function collectEntries(): array
    {
        $out = [];
        foreach ($this->files() as $file) {
            $stamp = @stat($file);
            if ($stamp === false) {
                continue; // vanished mid-sweep: nothing to evict or count
            }
            $out[] = ['path' => $file, 'mtime' => $stamp['mtime'], 'size' => $stamp['size']];
        }

        return $out;
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

        // 0o700: cached payloads are render output of (possibly private)
        // source images — the entry file mode is umask-dependent, so the
        // containing directory is the privacy boundary that must be owner-only.
        if (!@mkdir($this->dir, 0o700, true) && !is_dir($this->dir)) {
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
