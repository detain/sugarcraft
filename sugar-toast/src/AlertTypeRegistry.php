<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * Immutable registry of custom alert types — the registration seam
 * bubbleup exposes as `RegisterNewAlertType`, made a value the host hands to
 * {@see Toast::withTypeRegistry()} instead of process-global mutable state
 * (every Toast here is immutable; a package-level registry would let two
 * hosts in one process stomp each other's types).
 *
 * String types resolve registry-first, then the four built-ins, so a
 * definition keyed `error` deliberately re-skins the built-in.
 */
final class AlertTypeRegistry
{
    /** @param array<string, AlertTypeDefinition> $definitions keyed by lower-case key */
    private function __construct(private readonly array $definitions)
    {
    }

    public static function new(): self
    {
        return new self([]);
    }

    /** A registry with $definition added (replacing any same-key one). */
    public function with(AlertTypeDefinition $definition): self
    {
        return new self([$definition->key => $definition] + $this->definitions);
    }

    /** Case-insensitive lookup; null when the key is not registered. */
    public function resolve(string $key): ?AlertTypeDefinition
    {
        return $this->definitions[\strtolower($key)] ?? null;
    }

    /** @return list<string> registered keys, registration order newest-first */
    public function keys(): array
    {
        return \array_keys($this->definitions);
    }
}
