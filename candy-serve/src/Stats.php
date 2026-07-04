<?php

declare(strict_types=1);

namespace SugarCraft\Serve;

/**
 * In-process counters for the stats collection server (plan item 7.6).
 *
 * A tiny mutable collector — the one deliberately non-immutable service
 * next to {@see AccessControl}, and it follows the same
 * getInstance()/setInstance() pattern: transports record into the shared
 * instance by default, and hosts/tests inject their own via
 * `setInstance()` (or the per-object `setStats()` on GitDaemon and the
 * HTTP server).
 *
 * Mirrors charmbracelet/soft-serve's stats server intent (soft-serve
 * exposes Prometheus metrics on `stats.listen_addr`); candy-serve keeps
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

    public function __construct()
    {
        $this->startedAt = \microtime(true);
    }

    public static function getInstance(): self
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
        $this->connections++;
    }

    /** A pack was received from a client (git-receive-pack / push). */
    public function recordPackUpload(): void
    {
        $this->packUploads++;
    }

    /** A pack was sent to a client (git-upload-pack / clone, fetch). */
    public function recordPackDownload(): void
    {
        $this->packDownloads++;
    }

    public function recordLfsBatch(): void
    {
        $this->lfsBatchRequests++;
    }

    public function recordLfsDownload(): void
    {
        $this->lfsObjectDownloads++;
    }

    public function recordLfsUpload(): void
    {
        $this->lfsObjectUploads++;
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

    /** Zero every counter and restart the uptime clock (test isolation). */
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
}
