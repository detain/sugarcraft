<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Db-layer seam for the metric sampler.
 *
 * The Db layer only needs to clear sampler state after a reconnect
 * (server restart invalidates accumulated deltas). Declaring just
 * resetAll() here lets MysqlDatabase signal a reset without importing
 * the Admin\Sampler concrete — preserving the Admin → Db dependency
 * direction.
 */
interface SamplerInterface
{
    /**
     * Clear all accumulated sampling state (e.g. after a reconnect).
     */
    public function resetAll(): void;
}
