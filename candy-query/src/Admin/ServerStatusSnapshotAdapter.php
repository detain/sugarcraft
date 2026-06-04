<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Adapter that wraps a ServerContextInterface to provide StatusSnapshotProviderInterface.
 *
 * Stores snapshots internally so that Sampler's two-sample rate logic can operate
 * on the same context across poll cycles without the underlying context needing to
 * implement StatusSnapshotProviderInterface directly.
 *
 * @see Sampler
 * @see StatusSnapshotProviderInterface
 */
final class ServerStatusSnapshotAdapter implements StatusSnapshotProviderInterface
{
    /**
     * @param array<string, string>|null $currentSnapshot Last snapshot returned by context
     */
    public function __construct(
        private readonly ServerContextInterface $context,
        private ?array $currentSnapshot = null,
    ) {}

    public function currentSnapshot(): ?array
    {
        $this->currentSnapshot = $this->context->statusVariables();
        return $this->currentSnapshot;
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
