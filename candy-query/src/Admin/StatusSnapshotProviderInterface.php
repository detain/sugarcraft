<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Provides status snapshots for polling and rate sampling.
 */
interface StatusSnapshotProviderInterface
{
    /**
     * Get the most recent status snapshot.
     *
     * @return array<string, string>|null
     */
    public function currentSnapshot(): ?array;

    /**
     * Get the timestamp of the most recent snapshot.
     */
    public function statusVariablesTs(): float;

    /**
     * Check if the server was restarted since last poll.
     */
    public function wasReset(): bool;
}
