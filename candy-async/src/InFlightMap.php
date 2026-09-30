<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\Promise\PromiseInterface;

/**
 * Keyed in-flight promise registry — the single coalescing slot backing both
 * {@see AsyncCache}'s concurrent-loader dedupe and {@see AsyncOps::singleFlight()}.
 *
 * WHY a shared mechanism: two independent coalescing maps would each need the
 * same release-ordering law discovered downstream (phlix ApiClient drives the
 * in-flight guard through a Deferred so the slot is occupied BEFORE the worker
 * can settle synchronously, and freed exactly once when it does). One home for
 * that law keeps it pinned once.
 *
 * Extracted from the candy-query/phlix pattern.
 *
 * @internal  Library plumbing — not part of the public API.
 */
final class InFlightMap
{
    /** @var array<string, PromiseInterface> */
    private array $flights = [];

    public function find(string $key): ?PromiseInterface
    {
        return $this->flights[$key] ?? null;
    }

    public function begin(string $key, PromiseInterface $flight): void
    {
        $this->flights[$key] = $flight;
    }

    /**
     * Release the slot for $key when $flight still occupies it.
     *
     * The identity check, not the key, decides: when an invalidation displaced
     * this flight and a newer load occupies the slot, releasing by key alone
     * would free the CURRENT occupant on an obsolete arrival's tail — letting a
     * third caller fork a duplicate load alongside a perfectly healthy one.
     *
     * The bool return doubles as the adoption gate for caching callers: "you
     * were still the current flight" means the result may enter the store.
     *
     * @return bool  True when $flight was the current occupant and is released.
     */
    public function end(string $key, PromiseInterface $flight): bool
    {
        if (($this->flights[$key] ?? null) !== $flight) {
            return false;
        }
        unset($this->flights[$key]);
        return true;
    }

    /** Drop the slot unconditionally — the invalidation path. */
    public function forget(string $key): void
    {
        unset($this->flights[$key]);
    }

    public function forgetAll(): void
    {
        $this->flights = [];
    }
}
