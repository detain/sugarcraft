<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

/**
 * Adapter that wraps a ServerContextInterface to provide StatusSnapshotProviderInterface.
 *
 * Bridges the Sampler's two-sample rate logic to the same context across
 * poll cycles without the underlying context needing to implement
 * StatusSnapshotProviderInterface directly. The bridge is deliberately
 * stateless (admindata-016, SQL-A09): every call re-reads the context, so a
 * sampler tick can never sample a memo the adapter parked on an earlier
 * poll — the context (and behind it, the AdminQueryCache snapshot slots)
 * owns the only freshness decision.
 *
 * @see Sampler
 * @see StatusSnapshotProviderInterface
 */
final class ServerStatusSnapshotAdapter implements StatusSnapshotProviderInterface
{
    public function __construct(
        private readonly ServerContextInterface $context,
    ) {}

    public function currentSnapshot(): ?array
    {
        return $this->context->statusVariables();
    }

    public function statusVariablesTs(): float
    {
        return $this->context->statusVariablesTs();
    }

    public function wasReset(): bool
    {
        return $this->context->wasReset();
    }
}
