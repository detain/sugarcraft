<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

use SugarCraft\GitServer\Support\AtomicWriter;

/**
 * In-process counters for the stats collection server.
 *
 * A tiny mutable collector — the one deliberately non-immutable service
 * next to {@see AccessControl}, and it follows the same
 * instance()/setInstance() pattern: transports record into the shared
 * instance by default, and hosts/tests inject their own via
 * `setInstance()` (or the per-object `setStats()` on GitDaemon and the
 * HTTP server).
 *
 * Persistence (108) is OFF by default: a plain `new Stats()` keeps the
 * volatile semantics every existing test depends on. Pass a path (the
 * bin arms this from `stats.persist_path`) and the counters become
 * durable through a MONOTONIC merge: each flush re-reads the file, lets
 * the higher value win on every counter, pulls the in-process counters
 * up to the merged truth, and writes through the S24 AtomicWriter
 * discipline — so neither a restart nor a concurrent worker flusher can
 * move operational history backwards. Uptime stays per-process truth:
 * the clock restarts because the listener did.
 *
 * Mirrors charmbracelet/soft-serve's stats server intent (soft-serve
 * exposes Prometheus metrics on `stats.listen_addr`); candy-git-server keeps
 * the counter set minimal and serves it as JSON via {@see StatsServer}.
 */
final class Stats
{
    private static ?self $instance = null;

    private int $connections = 0;
    private int $packUploads = 0;
    private int $packDownloads = 0;
    private int $lfsBatchRequests = 0;
    private int $lfsObjectDownloads = 0;
    private int $lfsObjectUploads = 0;
    private float $startedAt;

    private ?string $persistPath;

    private int $flushIntervalSeconds;

    /** Cadence anchor: boot counts as "just written" so the first real flush waits a full interval. */
    private float $lastFlushAt = 0.0;

    public function __construct(?string $persistPath = null, int $flushIntervalSeconds = 60)
    {
        $this->startedAt            = \microtime(true);
        $this->lastFlushAt          = $this->startedAt;
        $this->persistPath          = ($persistPath !== null && $persistPath !== '') ? $persistPath : null;
        $this->flushIntervalSeconds = \max(0, $flushIntervalSeconds);
        if ($this->persistPath !== null) {
            // Hydrate from the persisted floor before any transport can
            // record: the process starts where history left off.
            $this->adopt($this->mergeWithFile());
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(self $stats): void
    {
        self::$instance = $stats;
    }

    // -------------------------------------------------------------------------
    // Recording
    // -------------------------------------------------------------------------

    /** A transport (git daemon or HTTP) handled a connection/request. */
    public function recordConnection(): void
    {
        $this->bump($this->connections);
    }

    /** A pack was received from a client (git-receive-pack / push). */
    public function recordPackUpload(): void
    {
        $this->bump($this->packUploads);
    }

    /** A pack was sent to a client (git-upload-pack / clone, fetch). */
    public function recordPackDownload(): void
    {
        $this->bump($this->packDownloads);
    }

    public function recordLfsBatch(): void
    {
        $this->bump($this->lfsBatchRequests);
    }

    public function recordLfsDownload(): void
    {
        $this->bump($this->lfsObjectDownloads);
    }

    public function recordLfsUpload(): void
    {
        $this->bump($this->lfsObjectUploads);
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function connections(): int
    {
        return $this->connections;
    }

    public function packUploads(): int
    {
        return $this->packUploads;
    }

    public function packDownloads(): int
    {
        return $this->packDownloads;
    }

    public function lfsBatchRequests(): int
    {
        return $this->lfsBatchRequests;
    }

    public function lfsObjectDownloads(): int
    {
        return $this->lfsObjectDownloads;
    }

    public function lfsObjectUploads(): int
    {
        return $this->lfsObjectUploads;
    }

    public function uptimeSeconds(): float
    {
        return \microtime(true) - $this->startedAt;
    }

    /** @return array<string, int|float> */
    public function snapshot(): array
    {
        return [
            'uptime_seconds'       => \round($this->uptimeSeconds(), 3),
            'connections'          => $this->connections,
            'pack_uploads'         => $this->packUploads,
            'pack_downloads'       => $this->packDownloads,
            'lfs_batch_requests'   => $this->lfsBatchRequests,
            'lfs_object_downloads' => $this->lfsObjectDownloads,
            'lfs_object_uploads'   => $this->lfsObjectUploads,
        ];
    }

    public function toJson(): string
    {
        return \json_encode($this->snapshot(), \JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Zero every counter and restart the uptime clock (test isolation).
     * On a PERSISTING instance this is still a live-plane reset, but the
     * merge discipline means the next flush pulls the file's higher
     * counters back up — history never un-happens; use a fresh volatile
     * instance for test isolation.
     */
    public function reset(): void
    {
        $this->connections = 0;
        $this->packUploads = 0;
        $this->packDownloads = 0;
        $this->lfsBatchRequests = 0;
        $this->lfsObjectDownloads = 0;
        $this->lfsObjectUploads = 0;
        $this->startedAt = \microtime(true);
    }

    /** Force a merge-and-write regardless of the cadence (stop path, tests). */
    public function flushNow(): bool
    {
        if ($this->persistPath === null) {
            return false;
        }
        $this->adopt($this->mergeWithFile());
        try {
            AtomicWriter::write($this->persistPath, \json_encode($this->counters(), \JSON_PRETTY_PRINT) . "\n");
        } catch (\RuntimeException) {
            // Operational history is a nice-to-have: a failed flush must
            // never crash a live server. The counters stay correct
            // in-process and the next tick retries the merge.
            return false;
        }
        $this->lastFlushAt = \microtime(true);

        return true;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function bump(int &$counter): void
    {
        $counter++;
        $this->maybeFlush();
    }

    private function maybeFlush(): void
    {
        if ($this->persistPath === null) {
            return;
        }
        if (\microtime(true) - $this->lastFlushAt < $this->flushIntervalSeconds) {
            return;
        }
        $this->flushNow();
    }

    /** Per-counter max(live, file) — the merge that keeps history monotonic. */
    private function mergeWithFile(): array
    {
        $live   = $this->counters();
        $stored = self::readCounters((string) $this->persistPath);
        foreach ($live as $key => $value) {
            $live[$key] = \max($value, $stored[$key] ?? 0);
        }

        return $live;
    }

    /** @param array<string, int> $counters */
    private function adopt(array $counters): void
    {
        $this->connections          = $counters['connections'];
        $this->packUploads          = $counters['pack_uploads'];
        $this->packDownloads        = $counters['pack_downloads'];
        $this->lfsBatchRequests     = $counters['lfs_batch_requests'];
        $this->lfsObjectDownloads   = $counters['lfs_object_downloads'];
        $this->lfsObjectUploads     = $counters['lfs_object_uploads'];
    }

    /** @return array<string, int> */
    private function counters(): array
    {
        return [
            'connections'          => $this->connections,
            'pack_uploads'         => $this->packUploads,
            'pack_downloads'       => $this->packDownloads,
            'lfs_batch_requests'   => $this->lfsBatchRequests,
            'lfs_object_downloads' => $this->lfsObjectDownloads,
            'lfs_object_uploads'   => $this->lfsObjectUploads,
        ];
    }

    /**
     * Read the persisted counters; a missing, unreadable, or corrupt file
     * is history-not-yet, not an error — every counter floors at zero.
     * Non-integer values are ignored the same way (only whole counts
     * count).
     *
     * @return array<string, int>
     */
    private static function readCounters(string $path): array
    {
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (\is_int($value) && $value >= 0) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
