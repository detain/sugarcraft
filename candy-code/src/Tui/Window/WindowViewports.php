<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Window;

/**
 * The per-window stash of {@see WindowViewport}s: switching windows must
 * leave each one exactly where the user left it, so the shell stashes the
 * outgoing window's viewport and restores the incoming one's.
 *
 * Immutable map — `stash()` answers a new instance, mirroring the house
 * `with*` law ({@see \SugarCraft\Style\Style}). {@see restore()} never
 * throws for a key it never saw: an unknown window is a fresh one, and a
 * fresh one is at the bottom with nothing drafted.
 */
final class WindowViewports
{
    /**
     * @param array<string, WindowViewport> $byKey
     */
    private function __construct(
        private readonly array $byKey = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    /** The map with $key's viewport set to $viewport (replacing any earlier one). */
    public function stash(string $key, WindowViewport $viewport): self
    {
        $next = $this->byKey;
        $next[$key] = $viewport;

        return new self($next);
    }

    /** $key's stashed viewport, or the bottom/empty one when it was never stashed. */
    public function restore(string $key): WindowViewport
    {
        return $this->byKey[$key] ?? WindowViewport::bottom();
    }

    /** Whether anything is held for $key. */
    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    /** How many windows have a stashed viewport. */
    public function count(): int
    {
        return count($this->byKey);
    }
}
