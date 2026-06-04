<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\History;

use SugarCraft\Query\Admin\StatusSnapshot;
use SugarCraft\Query\Admin\StatusSnapshotProviderInterface;

/**
 * Passive recorder that persists StatusSnapshots to a HistoryStore.
 *
 * This class does NOT poll on its own — callers (e.g. DashboardPage)
  * decide when to record. It can optionally pull from a
 * StatusSnapshotProvider, making it usable as a sampler input.
 *
 * Implements StatusSnapshotProviderInterface so a HistoryRecorder
 * instance can be passed directly to Sampler when needed.
 */
final class HistoryRecorder implements StatusSnapshotProviderInterface
{
    private ?StatusSnapshot $lastSnapshot = null;
    private float $lastSnapshotTs = 0.0;
    private bool $wasReset = false;

    public function __construct(
        private readonly HistoryStoreInterface $store,
        private readonly ?StatusSnapshotProviderInterface $provider = null,
    ) {}

    /**
     * Persist a snapshot to the store.
     */
    public function record(StatusSnapshot $snapshot): void
    {
        $this->store->save($snapshot);
        $this->lastSnapshot = $snapshot;
        $this->lastSnapshotTs = $snapshot->ts;
    }

    /**
     * Fetch a snapshot from the injected provider and persist it.
     */
    public function recordFromProvider(): void
    {
        if ($this->provider === null) {
            return;
        }

        $current = $this->provider->currentSnapshot();
        if ($current === null) {
            return;
        }

        $ts = $this->provider->statusVariablesTs();
        $snapshot = new StatusSnapshot($current, $ts);
        $this->record($snapshot);
    }

    /**
     * Remove all snapshots older than the given cutoff.
     *
     * @return int Number of records deleted
     */
    public function pruneOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->store->prune($cutoff);
    }

    // -------------------------------------------------------------------------
    // StatusSnapshotProviderInterface
    // -------------------------------------------------------------------------

    public function currentSnapshot(): ?array
    {
        return $this->lastSnapshot?->variables ?? null;
    }

    public function statusVariablesTs(): float
    {
        return $this->lastSnapshotTs;
    }

    public function wasReset(): bool
    {
        if ($this->wasReset) {
            // Reset was already reported on a previous call; suppress it now.
            $this->wasReset = false;
            return false;
        }

        if ($this->provider !== null) {
            $providerReset = $this->provider->wasReset();
            if ($providerReset) {
                // Remember this so the next call returns false.
                $this->wasReset = true;
            }
            return $providerReset;
        }

        return false;
    }
}
